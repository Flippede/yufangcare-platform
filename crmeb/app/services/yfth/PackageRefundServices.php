<?php

namespace app\services\yfth;

use app\dao\order\StoreOrderRefundDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use app\services\order\StoreOrderServices;
use think\facade\Log;

class PackageRefundServices extends PackageBenefitBaseServices
{
    public function onRefundApplied(array $order): void
    {
        $purchase = $this->resolvePurchase($order, 'refund_apply');
        if (!$purchase) {
            return;
        }
        app()->make(PackageLifecycleServices::class)->markRefunding($purchase, 'refund_applied', $order);
    }

    public function onRefundCanceled(array $refundInfo): void
    {
        $purchase = $this->resolvePurchase($refundInfo, 'refund_cancel');
        if (!$purchase) {
            return;
        }
        app()->make(PackageLifecycleServices::class)->restoreAfterRefundCancel($purchase, $refundInfo);
    }

    public function onRefundSucceeded(string $orderSn, array $eventData = []): void
    {
        $purchase = $this->resolvePurchase(array_merge($eventData, ['event_order_sn' => $orderSn]), 'refund_success');
        if (!$purchase) {
            return;
        }
        app()->make(PackageLifecycleServices::class)->markRefundSucceeded($purchase, $eventData);
    }

    public function onRefundFailed(string $orderSn, array $eventData = []): void
    {
        $purchase = $this->resolvePurchase(array_merge($eventData, ['event_order_sn' => $orderSn]), 'refund_fail');
        if (!$purchase) {
            return;
        }
        app()->make(PackageLifecycleServices::class)->restoreAfterRefundFailed($purchase, $eventData);
    }

    private function resolvePurchase(array $payload, string $scene): array
    {
        $candidates = $this->extractOrderCandidates($payload);
        foreach ($candidates['order_ids'] as $orderId) {
            $purchase = $this->findPurchaseByOrder((int)$orderId, '');
            if ($purchase) {
                return $purchase;
            }
        }
        foreach ($candidates['order_sns'] as $orderSn) {
            $purchase = $this->findPurchaseByOrder(0, (string)$orderSn);
            if ($purchase) {
                return $purchase;
            }
        }
        $this->recordPendingCompensation($payload, $scene, $candidates);
        return [];
    }

    private function extractOrderCandidates(array $payload): array
    {
        $orderIds = [];
        $orderSns = [];
        foreach (['store_order_id', 'oid'] as $field) {
            if (!empty($payload[$field])) {
                $orderIds[] = (int)$payload[$field];
            }
        }
        foreach (['store_order_sn', 'order_sn', 'event_order_sn'] as $field) {
            if (!empty($payload[$field])) {
                $orderSns[] = (string)$payload[$field];
            }
        }

        if (!empty($payload['id']) && empty($payload['pay_price']) && empty($payload['total_price'])) {
            $this->appendRefundRecordCandidates((int)$payload['id'], '', $orderIds, $orderSns);
        }
        if (!empty($payload['refund_order_id'])) {
            $this->appendRefundRecordCandidates(0, (string)$payload['refund_order_id'], $orderIds, $orderSns);
        }
        if (!empty($payload['order_id'])) {
            $orderIdValue = (string)$payload['order_id'];
            $this->appendRefundRecordCandidates(0, $orderIdValue, $orderIds, $orderSns);
            $orderSns[] = $orderIdValue;
        }

        return [
            'order_ids' => array_values(array_unique(array_filter($orderIds))),
            'order_sns' => array_values(array_unique(array_filter($orderSns))),
        ];
    }

    private function appendRefundRecordCandidates(int $refundId, string $refundOrderSn, array &$orderIds, array &$orderSns): void
    {
        /** @var StoreOrderRefundDao $refundDao */
        $refundDao = app()->make(StoreOrderRefundDao::class);
        $refund = null;
        if ($refundId > 0) {
            $refund = $refundDao->get($refundId);
        }
        if (!$refund && $refundOrderSn !== '') {
            $refund = $refundDao->getOne(['order_id' => $refundOrderSn]);
        }
        if (!$refund) {
            return;
        }
        $refund = $refund->toArray();
        if ((int)($refund['store_order_id'] ?? 0) > 0) {
            $orderIds[] = (int)$refund['store_order_id'];
        }
        if (!empty($refund['store_order_sn'])) {
            $orderSns[] = (string)$refund['store_order_sn'];
        }
        if ((int)($refund['store_order_id'] ?? 0) > 0) {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $order = $orderServices->get((int)$refund['store_order_id']);
            if ($order) {
                $orderSns[] = (string)$order['order_id'];
            }
        }
    }

    private function findPurchaseByOrder(int $orderId = 0, string $orderSn = ''): array
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $purchase = null;
        if ($orderId > 0) {
            $purchase = $purchaseDao->getOne(['order_unique_key' => (string)$orderId]);
            if (!$purchase) {
                $purchase = $purchaseDao->getOne(['order_id' => $orderId]);
            }
        }
        if (!$purchase && $orderSn !== '') {
            $purchase = $purchaseDao->getOne(['order_sn_unique_key' => $orderSn]);
            if (!$purchase) {
                $purchase = $purchaseDao->getOne(['order_sn' => $orderSn]);
            }
        }
        if (!$purchase && $orderId > 0) {
            /** @var StoreOrderServices $orderServices */
            $orderServices = app()->make(StoreOrderServices::class);
            $order = $orderServices->get($orderId);
            if ($order) {
                $purchase = $purchaseDao->getOne(['order_sn' => (string)$order['order_id']]);
            }
        }
        return $purchase ? $purchase->toArray() : [];
    }

    private function recordPendingCompensation(array $payload, string $scene, array $candidates): void
    {
        Log::warning([
            'msg' => 'yfth_package_refund_mapping_pending_compensation',
            'scene' => $scene,
            'payload' => $this->sanitizeState($payload),
            'candidates' => $candidates,
        ]);
        $this->recordPackageAudit(
            'package_refund_event',
            substr(hash('sha256', $scene . $this->jsonEncode($payload)), 0, 32),
            'mapping_pending_compensation',
            [],
            ['scene' => $scene, 'candidates' => $candidates],
            0,
            'system',
            0,
            'refund_event_mapping_failed'
        );
    }
}
