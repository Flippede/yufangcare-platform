<?php

namespace app\services\yfth;

use app\dao\yfth\YfthPackagePurchaseDao;
use app\dao\yfth\YfthPackagePurchaseBenefitSnapshotDao;
use app\dao\yfth\YfthPackagePurchaseSnapshotDao;
use app\services\order\StoreOrderServices;
use crmeb\exceptions\AdminException;

class PackageActivationRecoveryServices extends PackageBenefitBaseServices
{
    public function recoverPaidUnactivated(int $limit = 50, int $operatorUid = 0, string $source = 'timer'): array
    {
        $limit = max(1, min($limit, 200));
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $rows = $purchaseDao->search([])
            ->where('order_id', '>', 0)
            ->where('instance_id', '=', 0)
            ->whereIn('activation_status', ['pending', 'failed'])
            ->whereNotIn('purchase_status', ['refunding', 'refunded', 'closed', 'closed_after_partial_refund', 'partial_fulfillment_refunded'])
            ->order('activation_retry_at asc,id asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $result = [
            'scanned' => count($rows),
            'activated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'items' => [],
        ];
        foreach ($rows as $purchase) {
            $item = $this->recoverOne($purchase, $operatorUid, $source, '');
            if (!empty($item['activated'])) {
                $result['activated']++;
            } elseif (!empty($item['failed'])) {
                $result['failed']++;
            } else {
                $result['skipped']++;
            }
            $result['items'][] = $item;
        }
        return $result;
    }

    public function retryPurchase(int $purchaseId, string $reason, int $operatorUid = 0): array
    {
        return $this->manualRetryActivation($purchaseId, $reason, $operatorUid);
    }

    public function manualRetryActivation(int $purchaseId, string $reason, int $operatorUid, string $requestId = ''): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new AdminException('activation_retry_reason_required');
        }
        if ($operatorUid <= 0) {
            throw new AdminException('activation_retry_operator_required');
        }
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $purchase = $this->requireRow($purchaseDao->get($purchaseId), 'package_purchase_not_found');
        if ((int)$purchase['order_id'] <= 0) {
            throw new AdminException('package_purchase_order_required');
        }
        if ((int)$purchase['instance_id'] > 0) {
            return [
                'purchase_id' => $purchaseId,
                'activated' => false,
                'skipped' => true,
                'reason' => 'already_has_instance',
                'instance_id' => (int)$purchase['instance_id'],
            ];
        }
        if (in_array((string)$purchase['purchase_status'], ['refunding', 'refunded', 'closed', 'closed_after_partial_refund', 'partial_fulfillment_refunded'], true)) {
            throw new AdminException('package_purchase_not_retryable_in_current_status');
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $order = $this->requireRow($orderServices->get((int)$purchase['order_id']), 'order_not_found');
        $this->assertManualRetryOrderAndSnapshots($purchase, $order);

        $requestId = $requestId ?: $this->makeNo('YFMAN');
        try {
            /** @var PackageActivationServices $activationServices */
            $activationServices = app()->make(PackageActivationServices::class);
            $activation = $activationServices->manualActivateByPaidOrder(is_array($order) ? $order : $order->toArray(), $operatorUid, $reason, $requestId);
            $payload = [
                'purchase_id' => (int)$purchase['id'],
                'order_id' => (int)$purchase['order_id'],
                'order_sn' => (string)$purchase['order_sn'],
                'request_id' => $requestId,
                'activation' => $activation,
            ];
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'activation_manual_retry', $purchase, $payload, $operatorUid, 'admin', (int)$purchase['store_id'], $reason, $requestId);
            return [
                'purchase_id' => (int)$purchase['id'],
                'order_id' => (int)$purchase['order_id'],
                'order_sn' => (string)$purchase['order_sn'],
                'manual_retry' => true,
                'request_id' => $requestId,
                'activated' => empty($activation['replayed']) && (int)($activation['instance_id'] ?? 0) > 0,
                'replayed' => !empty($activation['replayed']),
                'result' => $activation,
            ];
        } catch (\Throwable $e) {
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'activation_manual_retry_failed', $purchase, [
                'error' => substr($e->getMessage(), 0, 255),
                'request_id' => $requestId,
            ], $operatorUid, 'admin', (int)$purchase['store_id'], $reason, $requestId);
            throw $e;
        }
    }

    private function recoverOne(array $purchase, int $operatorUid, string $source, string $reason): array
    {
        try {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $order = $this->requireRow($orderServices->get((int)$purchase['order_id']), 'order_not_found');
            if ((int)($order['paid'] ?? 0) !== 1) {
                return $this->skip($purchase, 'order_not_paid', $operatorUid, $source, $reason);
            }
            if ((string)($order['order_id'] ?? '') !== (string)$purchase['order_sn']) {
                return $this->skip($purchase, 'order_identity_mismatch', $operatorUid, $source, $reason);
            }

            /** @var PackageActivationServices $activationServices */
            $activationServices = app()->make(PackageActivationServices::class);
            $activation = $activationServices->activateByPaidOrder(is_array($order) ? $order : $order->toArray());
            if (!empty($activation['replayed'])) {
                if (($activation['status'] ?? '') === 'failed' && empty($activation['can_retry'])) {
                    return $this->skip($purchase, 'activation_auto_retry_limit_exceeded', $operatorUid, $source, $reason);
                }
                return $this->skip($purchase, 'activation_replay_' . (string)($activation['status'] ?? 'unknown'), $operatorUid, $source, $reason);
            }
            $payload = [
                'purchase_id' => (int)$purchase['id'],
                'order_id' => (int)$purchase['order_id'],
                'order_sn' => (string)$purchase['order_sn'],
                'source' => $source,
                'activation' => $activation,
            ];
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'activation_recover', $purchase, $payload, $operatorUid, $source === 'admin_manual' ? 'admin' : 'system', (int)$purchase['store_id'], $reason);
            return [
                'purchase_id' => (int)$purchase['id'],
                'order_id' => (int)$purchase['order_id'],
                'order_sn' => (string)$purchase['order_sn'],
                'activated' => empty($activation['replayed']) || !empty($activation['result']['instance_id']),
                'result' => $activation,
            ];
        } catch (\Throwable $e) {
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'activation_recover_failed', $purchase, [
                'error' => substr($e->getMessage(), 0, 255),
                'source' => $source,
            ], $operatorUid, $source === 'admin_manual' ? 'admin' : 'system', (int)$purchase['store_id'], $reason);
            return [
                'purchase_id' => (int)$purchase['id'],
                'order_id' => (int)$purchase['order_id'],
                'order_sn' => (string)$purchase['order_sn'],
                'failed' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function assertManualRetryOrderAndSnapshots(array $purchase, array $order): void
    {
        if ((int)($order['paid'] ?? 0) !== 1) {
            throw new AdminException('package_manual_retry_order_not_paid');
        }
        if ((string)($order['order_id'] ?? '') !== (string)$purchase['order_sn']) {
            throw new AdminException('package_manual_retry_order_identity_mismatch');
        }
        if ((int)($order['is_del'] ?? 0) !== 0 || (int)($order['is_cancel'] ?? 0) !== 0) {
            throw new AdminException('package_manual_retry_order_closed');
        }
        if ((int)($order['refund_status'] ?? 0) !== 0) {
            throw new AdminException('package_manual_retry_order_refunding_or_refunded');
        }
        /** @var YfthPackagePurchaseSnapshotDao $snapshotDao */
        $snapshotDao = app()->make(YfthPackagePurchaseSnapshotDao::class);
        $snapshot = $this->requireRow($snapshotDao->getOne(['purchase_id' => (int)$purchase['id']]), 'package_purchase_snapshot_not_found');
        if ((int)$snapshot['order_id'] !== (int)$purchase['order_id'] || (string)$snapshot['order_sn'] !== (string)$purchase['order_sn']) {
            throw new AdminException('package_manual_retry_snapshot_order_mismatch');
        }
        /** @var YfthPackagePurchaseBenefitSnapshotDao $benefitSnapshotDao */
        $benefitSnapshotDao = app()->make(YfthPackagePurchaseBenefitSnapshotDao::class);
        if ($benefitSnapshotDao->getCount(['purchase_id' => (int)$purchase['id']]) <= 0) {
            throw new AdminException('package_purchase_benefit_snapshot_missing');
        }
    }

    private function skip(array $purchase, string $skipReason, int $operatorUid, string $source, string $reason): array
    {
        $payload = [
            'purchase_id' => (int)$purchase['id'],
            'order_id' => (int)$purchase['order_id'],
            'order_sn' => (string)$purchase['order_sn'],
            'source' => $source,
            'skip_reason' => $skipReason,
        ];
        $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'activation_recover_skipped', $purchase, $payload, $operatorUid, $source === 'admin_manual' ? 'admin' : 'system', (int)$purchase['store_id'], $reason);
        return [
            'purchase_id' => (int)$purchase['id'],
            'order_id' => (int)$purchase['order_id'],
            'order_sn' => (string)$purchase['order_sn'],
            'skipped' => true,
            'reason' => $skipReason,
        ];
    }
}
