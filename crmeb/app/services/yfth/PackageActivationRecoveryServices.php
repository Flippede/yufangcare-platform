<?php

namespace app\services\yfth;

use app\dao\yfth\YfthPackagePurchaseDao;
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
        $reason = trim($reason);
        if ($reason === '') {
            throw new AdminException('activation_retry_reason_required');
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
        return $this->recoverOne($purchase, $operatorUid, 'admin_manual', $reason);
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
