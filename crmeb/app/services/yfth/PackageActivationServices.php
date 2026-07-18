<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthPackageProductBindingDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use app\dao\yfth\YfthPackagePurchaseBenefitSnapshotDao;
use app\dao\yfth\YfthPackagePurchaseSnapshotDao;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use crmeb\exceptions\ApiException;

class PackageActivationServices extends PackageBenefitBaseServices
{
    public function activateByPaidOrder(array $orderInfo): array
    {
        $purchaseRow = $this->resolvePurchaseForPaidOrder($orderInfo);
        if (!$purchaseRow) {
            return $this->missingPurchaseResult($orderInfo);
        }
        return $this->activateWithIdempotency($purchaseRow, 'activate', 'package_activate:' . (int)$purchaseRow['order_id']);
    }

    public function manualActivateByPaidOrder(array $orderInfo, int $operatorUid, string $reason, string $requestId = ''): array
    {
        $reason = trim($reason);
        if ($operatorUid <= 0) {
            throw new ApiException('manual_activation_operator_required');
        }
        if ($reason === '') {
            throw new ApiException('manual_activation_reason_required');
        }
        $purchaseRow = $this->resolvePurchaseForPaidOrder($orderInfo);
        if (!$purchaseRow) {
            return $this->missingPurchaseResult($orderInfo);
        }
        $requestId = $requestId ?: $this->makeNo('YFMAN');
        return $this->activateWithIdempotency($purchaseRow, 'manual_activate', 'package_activate_manual:' . (int)$purchaseRow['id'], [
            'manual' => true,
            'operator_uid' => $operatorUid,
            'reason' => $reason,
            'request_id' => $requestId,
        ]);
    }

    private function activateWithIdempotency(array $purchaseRow, string $action, string $key, array $manual = []): array
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        /** @var IdempotencyRecordServices $idempotency */
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin('yfth_package', $action, $key, [
            'purchase_id' => (int)$purchaseRow['id'],
            'order_id' => (int)$purchaseRow['order_id'],
            'order_sn' => (string)$purchaseRow['order_sn'],
        ], (string)$purchaseRow['order_id'], 86400);
        if (!$begin['acquired']) {
            if (!empty($begin['can_retry'])) {
                $begin = $idempotency->tryReacquire($begin['record'], 86400);
            }
            if (!$begin['acquired']) {
                return [
                    'replayed' => true,
                    'status' => $begin['status'],
                    'result' => $begin['result_summary'] ?? [],
                    'can_retry' => $begin['can_retry'] ?? false,
                    'manual' => !empty($manual['manual']),
                ];
            }
        }

        if (!empty($manual['manual'])) {
            $this->markManualRetryAttempt($purchaseDao, (int)$purchaseRow['id'], $manual);
        }

        try {
            $result = $this->transaction(function () use ($purchaseDao, $purchaseRow) {
                return $this->activateLockedPurchase($purchaseDao, (int)$purchaseRow['id']);
            });
            $idempotency->complete((int)$begin['record']['id'], $result);
            if (!empty($manual['manual'])) {
                $purchaseDao->update((int)$purchaseRow['id'], [
                    'manual_retry_result' => 'succeeded',
                    'update_time' => time(),
                ]);
                $result['manual_retry'] = true;
                $result['manual_request_id'] = (string)$manual['request_id'];
            }
            $rewardEventId = (int)($result['permanent_membership']['reward_event_id'] ?? 0);
            if ($rewardEventId > 0) {
                try {
                    app()->make(UnifiedRewardOrchestratorServices::class)->process($rewardEventId, 'package-activation');
                } catch (\Throwable $rewardError) {
                    // Durable event remains failed and retryable; package activation is not rolled back.
                    $this->recordPackageAudit('package_purchase', (string)$purchaseRow['id'], 'reward_event_deferred', [], [
                        'reward_event_id' => $rewardEventId,
                        'error' => substr($rewardError->getMessage(), 0, 255),
                    ], 0, 'system', 0, 'reward_event_deferred');
                }
            }
            return $result;
        } catch (\Throwable $e) {
            $idempotency->fail((int)$begin['record']['id'], $e->getMessage());
            $update = [
                'activation_status' => 'failed',
                'last_activation_error' => substr($e->getMessage(), 0, 255),
                'activation_retry_at' => time(),
                'update_time' => time(),
            ];
            if (!empty($manual['manual'])) {
                $update['manual_retry_result'] = 'failed';
            } else {
                $update['activation_attempt_count'] = (int)($purchaseRow['activation_attempt_count'] ?? 0) + 1;
            }
            $purchaseDao->update((int)$purchaseRow['id'], $update);
            throw $e;
        }
    }

    private function resolvePurchaseForPaidOrder(array $orderInfo): array
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        $orderSn = (string)($orderInfo['order_id'] ?? '');
        if ($orderId <= 0 && $orderSn === '') {
            return [];
        }

        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $purchase = $orderId > 0 ? $purchaseDao->getOne(['order_unique_key' => (string)$orderId]) : null;
        if (!$purchase && $orderId > 0) {
            $purchase = $purchaseDao->getOne(['order_id' => $orderId]);
        }
        if (!$purchase && $orderSn !== '') {
            $purchase = $purchaseDao->getOne(['order_sn_unique_key' => $orderSn]);
            if (!$purchase) {
                $purchase = $purchaseDao->getOne(['order_sn' => $orderSn]);
            }
        }
        return $purchase ? $purchase->toArray() : [];
    }

    private function missingPurchaseResult(array $orderInfo): array
    {
        $attempt = app()->make(PackagePurchaseServices::class)->locatePackageOrderAttempt($orderInfo);
        if ($attempt || $this->isPackageSkuOrder($orderInfo)) {
            $payload = [
                'order_id' => (int)($orderInfo['id'] ?? 0),
                'order_sn' => (string)($orderInfo['order_id'] ?? ''),
                'uid' => (int)($orderInfo['uid'] ?? 0),
                'store_id' => (int)($orderInfo['store_id'] ?? 0),
                'intent_id' => (int)($attempt['intent_id'] ?? 0),
                'attempt_id' => (int)($attempt['id'] ?? 0),
                'request_id' => (string)($attempt['request_id'] ?? ''),
                'reason' => 'package_order_missing_purchase',
            ];
            $this->recordPackageAudit('package_orphan_order', (string)($orderInfo['id'] ?? $orderInfo['order_id'] ?? ''), 'paid_order_missing_purchase', [], $payload, 0, 'system', (int)($orderInfo['store_id'] ?? 0), 'package_order_missing_purchase');
            return ['skipped' => true, 'reason' => 'package_order_missing_purchase', 'pending_compensation' => true];
        }
        return ['skipped' => true, 'reason' => 'not_package_order'];
    }

    private function isPackageSkuOrder(array $orderInfo): bool
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        if ($orderId <= 0) {
            return false;
        }
        /** @var StoreOrderCartInfoServices $cartInfoServices */
        $cartInfoServices = app()->make(StoreOrderCartInfoServices::class);
        /** @var YfthPackageProductBindingDao $bindingDao */
        $bindingDao = app()->make(YfthPackageProductBindingDao::class);
        foreach ($cartInfoServices->getOrderCartInfo($orderId) as $item) {
            $cart = $item['cart_info'] ?? [];
            $productId = (int)($cart['productInfo']['id'] ?? $item['product_id'] ?? 0);
            $skuUnique = (string)($cart['productInfo']['attrInfo']['unique'] ?? $cart['attrInfo']['unique'] ?? $cart['productAttrUnique'] ?? '');
            if ($productId > 0 && $skuUnique !== '' && $bindingDao->getOne([
                'product_id' => $productId,
                'product_attr_unique' => $skuUnique,
                'binding_status' => 'active',
            ])) {
                return true;
            }
        }
        return false;
    }

    private function markManualRetryAttempt(YfthPackagePurchaseDao $purchaseDao, int $purchaseId, array $manual): void
    {
        $purchaseDao->search([])
            ->where('id', $purchaseId)
            ->inc('manual_retry_count')
            ->update([
                'last_manual_retry_at' => time(),
                'last_manual_retry_operator' => (int)$manual['operator_uid'],
                'manual_retry_reason' => substr((string)$manual['reason'], 0, 255),
                'manual_retry_request_id' => substr((string)$manual['request_id'], 0, 64),
                'manual_retry_result' => 'processing',
                'update_time' => time(),
            ]);
    }

    private function activateLockedPurchase(YfthPackagePurchaseDao $purchaseDao, int $purchaseId): array
    {
        $purchase = $purchaseDao->search([])->where('id', $purchaseId)->lock(true)->find();
        if (!$purchase) {
            throw new ApiException('package_purchase_not_found');
        }
        $purchaseRow = $purchase->toArray();

        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        $existing = $instanceDao->getOne(['purchase_id' => $purchaseId]);
        if ($existing) {
            return [
                'instance_id' => (int)$existing['id'],
                'purchase_id' => $purchaseId,
                'already_activated' => true,
            ];
        }

        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $this->requireRow($orderServices->get((int)$purchaseRow['order_id']), 'order_not_found');
        if ((int)$order['paid'] !== 1) {
            throw new ApiException('order_not_paid');
        }
        if ((string)$order['order_id'] !== (string)$purchaseRow['order_sn']) {
            throw new ApiException('order_identity_mismatch');
        }

        /** @var YfthPackagePurchaseSnapshotDao $snapshotDao */
        $snapshotDao = app()->make(YfthPackagePurchaseSnapshotDao::class);
        $snapshotRow = $this->requireRow($snapshotDao->getOne(['purchase_id' => $purchaseId]), 'package_purchase_snapshot_not_found');
        if ((int)$snapshotRow['order_id'] !== (int)$purchaseRow['order_id'] || (string)$snapshotRow['order_sn'] !== (string)$purchaseRow['order_sn']) {
            throw new ApiException('package_purchase_snapshot_order_mismatch');
        }
        if (!$this->moneyEquals($snapshotRow['package_price'], $purchaseRow['expected_pay_price'])
            || !$this->moneyEquals($order['pay_price'], $snapshotRow['order_pay_price'])) {
            throw new ApiException('package_purchase_snapshot_price_mismatch');
        }

        /** @var YfthPackagePurchaseBenefitSnapshotDao $benefitSnapshotDao */
        $benefitSnapshotDao = app()->make(YfthPackagePurchaseBenefitSnapshotDao::class);
        $benefitSnapshots = $benefitSnapshotDao->selectList(['purchase_id' => $purchaseId], '*', 0, 0, 'month_no asc,id asc', [], false)->toArray();
        if (!$benefitSnapshots) {
            throw new ApiException('package_purchase_benefit_snapshot_missing');
        }

        $start = (int)($order['pay_time'] ?: time());
        $end = strtotime('+' . (int)$snapshotRow['month_count'] . ' months', $start);

        $instance = $instanceDao->save($this->withTimestamps([
            'instance_no' => $this->makeNo('YFI'),
            'purchase_id' => $purchaseId,
            'uid' => (int)$purchaseRow['uid'],
            'store_id' => (int)$purchaseRow['store_id'],
            'template_id' => (int)$purchaseRow['template_id'],
            'rule_version_id' => (int)$purchaseRow['rule_version_id'],
            'order_id' => (int)$purchaseRow['order_id'],
            'order_sn' => (string)$purchaseRow['order_sn'],
            'plan_id' => 0,
            'status' => 'active',
            'refund_status' => 'none',
            'fulfilled_count' => 0,
            'start_time' => $start,
            'end_time' => $end,
            'activated_time' => time(),
            'close_reason' => '',
            'rule_snapshot' => $this->jsonEncode($snapshotRow),
            'store_snapshot' => $this->jsonEncode([
                'store_id' => (int)$snapshotRow['store_id'],
                'available_store_ids' => $this->jsonDecode($snapshotRow['available_store_ids'] ?? ''),
                'subjects' => [
                    'sales_subject_id' => (int)$snapshotRow['sales_subject_id'],
                    'payment_subject_id' => (int)$snapshotRow['payment_subject_id'],
                    'fulfillment_subject_id' => (int)$snapshotRow['fulfillment_subject_id'],
                    'invoice_subject_id' => (int)$snapshotRow['invoice_subject_id'],
                    'refund_subject_id' => (int)$snapshotRow['refund_subject_id'],
                ],
            ]),
        ], true));
        $instanceId = (int)$instance->id;

        $planId = $this->createPlanAndBenefitsFromSnapshot($instanceId, $purchaseRow, $snapshotRow, $benefitSnapshots, $start, $end);
        $instanceDao->update($instanceId, ['plan_id' => $planId, 'update_time' => time()]);
        $purchaseDao->update($purchaseId, [
            'purchase_status' => 'activated',
            'activation_status' => 'succeeded',
            'instance_id' => $instanceId,
            'activation_retry_at' => 0,
            'last_activation_error' => '',
            'update_time' => time(),
        ]);

        /** @var PackageInstanceServices $instanceServices */
        $instanceServices = app()->make(PackageInstanceServices::class);
        $instanceServices->recomputeMemberIdentity((int)$purchaseRow['uid']);

        $membership = app()->make(PackageMembershipActivationCoordinator::class)
            ->activateInTransaction($purchaseRow, $snapshotRow, $instanceId);

        $this->recordPackageAudit('package_instance', (string)$instanceId, 'activate', [], [
            'purchase_id' => $purchaseId,
            'plan_id' => $planId,
            'month_count' => (int)$snapshotRow['month_count'],
        ], (int)$purchaseRow['uid'], 'customer', (int)$purchaseRow['store_id']);

        return [
            'purchase_id' => $purchaseId,
            'instance_id' => $instanceId,
            'plan_id' => $planId,
            'month_count' => (int)$snapshotRow['month_count'],
            'permanent_membership' => $membership,
        ];
    }

    private function createPlanAndBenefitsFromSnapshot(int $instanceId, array $purchase, array $snapshot, array $benefitSnapshots, int $start, int $end): int
    {
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);

        $plan = $planDao->save($this->withTimestamps([
            'plan_no' => $this->makeNo('YFPL'),
            'package_instance_id' => $instanceId,
            'uid' => (int)$purchase['uid'],
            'store_id' => (int)$purchase['store_id'],
            'template_id' => (int)$purchase['template_id'],
            'rule_version_id' => (int)$purchase['rule_version_id'],
            'month_count' => (int)$snapshot['month_count'],
            'status' => 'active',
            'start_time' => $start,
            'end_time' => $end,
            'opened_month_no' => 0,
        ], true));
        $planId = (int)$plan->id;
        $rulesByMonth = [];
        foreach ($benefitSnapshots as $monthlyRule) {
            $rulesByMonth[(int)$monthlyRule['month_no']][] = $monthlyRule;
        }

        for ($monthNo = 1; $monthNo <= (int)$snapshot['month_count']; $monthNo++) {
            $periodStart = strtotime('+' . ($monthNo - 1) . ' months', $start);
            $periodEnd = strtotime('+' . $monthNo . ' months', $start) - 1;
            $openAt = $periodStart;
            $expireAt = $periodEnd;
            $status = $openAt <= time() ? 'available' : 'unopened';
            $period = $periodDao->save($this->withTimestamps([
                'plan_id' => $planId,
                'package_instance_id' => $instanceId,
                'uid' => (int)$purchase['uid'],
                'store_id' => (int)$purchase['store_id'],
                'month_no' => $monthNo,
                'period_code' => 'YFTH-' . $instanceId . '-' . $monthNo,
                'period_start_time' => $periodStart,
                'period_end_time' => $periodEnd,
                'open_at' => $openAt,
                'expire_at' => $expireAt,
                'status' => $status,
                'total_item_count' => count($rulesByMonth[$monthNo] ?? []),
                'fulfilled_item_count' => 0,
            ], true));
            $periodId = (int)$period->id;
            foreach ($rulesByMonth[$monthNo] ?? [] as $monthlyRule) {
                $itemOpenAt = $periodStart + ((int)$monthlyRule['available_offset_days'] * 86400);
                $itemExpireAt = (int)$monthlyRule['expire_offset_days'] > 0
                    ? $periodStart + ((int)$monthlyRule['expire_offset_days'] * 86400)
                    : $periodEnd;
                $itemStatus = $itemOpenAt <= time() ? 'available' : 'unopened';
                $itemDao->save($this->withTimestamps([
                    'plan_id' => $planId,
                    'period_id' => $periodId,
                    'package_instance_id' => $instanceId,
                    'uid' => (int)$purchase['uid'],
                    'store_id' => (int)$purchase['store_id'],
                    'month_no' => $monthNo,
                    'benefit_template_id' => (int)$monthlyRule['benefit_template_id'],
                    'benefit_code' => (string)$monthlyRule['benefit_code'],
                    'benefit_name' => (string)$monthlyRule['benefit_name'],
                    'benefit_type' => (string)$monthlyRule['benefit_type'],
                    'quantity_total' => $this->normalizeMoney($monthlyRule['quantity']),
                    'quantity_available' => $this->normalizeMoney($monthlyRule['quantity']),
                    'quantity_used' => '0.00',
                    'available_time' => $itemOpenAt,
                    'expire_time' => $itemExpireAt,
                    'status' => $itemStatus,
                    'fulfillment_status' => 'none',
                    'source_rule_id' => (int)$monthlyRule['source_rule_id'],
                ], true));
            }
            if ($status === 'available') {
                $planDao->update($planId, ['opened_month_no' => $monthNo, 'update_time' => time()]);
            }
        }

        return $planId;
    }
}
