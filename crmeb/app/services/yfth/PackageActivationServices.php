<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use app\services\order\StoreOrderServices;
use crmeb\exceptions\ApiException;

class PackageActivationServices extends PackageBenefitBaseServices
{
    public function activateByPaidOrder(array $orderInfo): array
    {
        $orderId = (int)($orderInfo['id'] ?? 0);
        $orderSn = (string)($orderInfo['order_id'] ?? '');
        if ($orderId <= 0 && $orderSn === '') {
            return ['skipped' => true, 'reason' => 'missing_order_identity'];
        }

        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $purchase = $orderId > 0 ? $purchaseDao->getOne(['order_id' => $orderId]) : null;
        if (!$purchase && $orderSn !== '') {
            $purchase = $purchaseDao->getOne(['order_sn' => $orderSn]);
        }
        if (!$purchase) {
            return ['skipped' => true, 'reason' => 'not_package_order'];
        }
        $purchaseRow = $purchase->toArray();
        $key = 'package_activate:' . (int)$purchaseRow['order_id'];

        /** @var IdempotencyRecordServices $idempotency */
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin('yfth_package', 'activate', $key, [
            'purchase_id' => (int)$purchaseRow['id'],
            'order_id' => (int)$purchaseRow['order_id'],
            'order_sn' => (string)$purchaseRow['order_sn'],
        ], (string)$purchaseRow['order_id'], 86400);
        if (!$begin['acquired']) {
            return [
                'replayed' => true,
                'status' => $begin['status'],
                'result' => $begin['result_summary'] ?? [],
            ];
        }

        try {
            $result = $this->transaction(function () use ($purchaseDao, $purchaseRow) {
                return $this->activateLockedPurchase($purchaseDao, (int)$purchaseRow['id']);
            });
            $idempotency->complete((int)$begin['record']['id'], $result);
            return $result;
        } catch (\Throwable $e) {
            $idempotency->fail((int)$begin['record']['id'], $e->getMessage());
            throw $e;
        }
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

        /** @var PackageTemplateServices $templateServices */
        $templateServices = app()->make(PackageTemplateServices::class);
        $rule = $templateServices->ruleById((int)$purchaseRow['rule_version_id']);
        $start = (int)($order['pay_time'] ?: time());
        $end = strtotime('+' . (int)$rule['month_count'] . ' months', $start);
        $validation = $this->jsonDecode($purchaseRow['validation_snapshot'] ?? '');

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
            'rule_snapshot' => $this->jsonEncode($validation['rule'] ?? []),
            'store_snapshot' => $this->jsonEncode($validation['store'] ?? []),
        ], true));
        $instanceId = (int)$instance->id;

        $planId = $this->createPlanAndBenefits($instanceId, $purchaseRow, $rule, $start, $end);
        $instanceDao->update($instanceId, ['plan_id' => $planId, 'update_time' => time()]);
        $purchaseDao->update($purchaseId, [
            'purchase_status' => 'activated',
            'activation_status' => 'succeeded',
            'instance_id' => $instanceId,
            'update_time' => time(),
        ]);

        /** @var PackageInstanceServices $instanceServices */
        $instanceServices = app()->make(PackageInstanceServices::class);
        $instanceServices->recomputeMemberIdentity((int)$purchaseRow['uid']);

        $this->recordPackageAudit('package_instance', (string)$instanceId, 'activate', [], [
            'purchase_id' => $purchaseId,
            'plan_id' => $planId,
            'month_count' => (int)$rule['month_count'],
        ], (int)$purchaseRow['uid'], 'customer', (int)$purchaseRow['store_id']);

        return [
            'purchase_id' => $purchaseId,
            'instance_id' => $instanceId,
            'plan_id' => $planId,
            'month_count' => (int)$rule['month_count'],
        ];
    }

    private function createPlanAndBenefits(int $instanceId, array $purchase, array $rule, int $start, int $end): int
    {
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        /** @var BenefitTemplateServices $benefitServices */
        $benefitServices = app()->make(BenefitTemplateServices::class);

        $plan = $planDao->save($this->withTimestamps([
            'plan_no' => $this->makeNo('YFPL'),
            'package_instance_id' => $instanceId,
            'uid' => (int)$purchase['uid'],
            'store_id' => (int)$purchase['store_id'],
            'template_id' => (int)$purchase['template_id'],
            'rule_version_id' => (int)$purchase['rule_version_id'],
            'month_count' => (int)$rule['month_count'],
            'status' => 'active',
            'start_time' => $start,
            'end_time' => $end,
            'opened_month_no' => 0,
        ], true));
        $planId = (int)$plan->id;
        $rules = $benefitServices->rulesForVersion((int)$rule['id']);
        $rulesByMonth = [];
        foreach ($rules as $monthlyRule) {
            $rulesByMonth[(int)$monthlyRule['month_no']][] = $monthlyRule;
        }

        for ($monthNo = 1; $monthNo <= (int)$rule['month_count']; $monthNo++) {
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
                    'source_rule_id' => (int)$monthlyRule['id'],
                ], true));
            }
            if ($status === 'available') {
                $planDao->update($planId, ['opened_month_no' => $monthNo, 'update_time' => time()]);
            }
        }

        return $planId;
    }
}
