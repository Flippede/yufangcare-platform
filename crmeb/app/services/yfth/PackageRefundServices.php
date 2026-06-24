<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use app\services\order\StoreOrderServices;

class PackageRefundServices extends PackageBenefitBaseServices
{
    public function onRefundApplied(array $order): void
    {
        $purchase = $this->findPurchaseByOrder((int)($order['id'] ?? 0), (string)($order['order_id'] ?? ''));
        if (!$purchase) {
            return;
        }
        $this->markRefunding($purchase, 'refund_applied');
    }

    public function onRefundCanceled(array $refundInfo): void
    {
        $purchase = $this->findPurchaseByOrder((int)($refundInfo['store_order_id'] ?? 0), '');
        if (!$purchase) {
            return;
        }
        $this->restoreAfterRefundCancel($purchase);
    }

    public function onRefundSucceeded(string $orderSn, array $eventData = []): void
    {
        $purchase = $this->findPurchaseByOrder(0, $orderSn);
        if (!$purchase) {
            return;
        }
        $this->transaction(function () use ($purchase, $eventData) {
            $this->markRefundSucceeded($purchase, $eventData);
        });
    }

    public function onRefundFailed(string $orderSn, array $eventData = []): void
    {
        $purchase = $this->findPurchaseByOrder(0, $orderSn);
        if (!$purchase) {
            return;
        }
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $before = $purchase;
        $purchaseDao->update((int)$purchase['id'], [
            'purchase_status' => 'refund_failed',
            'update_time' => time(),
        ]);
        $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refund_failed', $before, $eventData, 0, 'system', (int)$purchase['store_id'], (string)($eventData['refund_reason'] ?? ''));
    }

    private function markRefunding(array $purchase, string $reason): void
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        $before = $purchase;
        $from = (string)$purchase['purchase_status'];
        if ($from !== 'refunding') {
            $this->assertTransition('purchase', $from, 'refunding');
            $purchaseDao->update((int)$purchase['id'], ['purchase_status' => 'refunding', 'update_time' => time()]);
        }

        if ((int)$purchase['instance_id'] > 0) {
            $instance = $instanceDao->get((int)$purchase['instance_id']);
            if ($instance && $instance['status'] !== 'refunding') {
                $this->assertTransition('instance', (string)$instance['status'], 'refunding');
                $instanceDao->update((int)$instance['id'], [
                    'status' => 'refunding',
                    'refund_status' => 'pending',
                    'update_time' => time(),
                ]);
            }
        }
        $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refunding', $before, array_merge($purchase, ['purchase_status' => 'refunding']), 0, 'system', (int)$purchase['store_id'], $reason);
    }

    private function restoreAfterRefundCancel(array $purchase): void
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        $target = (int)$purchase['instance_id'] > 0 ? 'activated' : 'wait_pay';
        $purchaseDao->update((int)$purchase['id'], ['purchase_status' => $target, 'update_time' => time()]);
        if ((int)$purchase['instance_id'] > 0) {
            $instanceDao->update((int)$purchase['instance_id'], [
                'status' => 'active',
                'refund_status' => 'none',
                'update_time' => time(),
            ]);
        }
        $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refund_cancel', $purchase, ['purchase_status' => $target], 0, 'system', (int)$purchase['store_id']);
    }

    private function markRefundSucceeded(array $purchase, array $eventData): void
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);

        $purchaseDao->update((int)$purchase['id'], [
            'purchase_status' => 'refunded',
            'update_time' => time(),
        ]);

        if ((int)$purchase['instance_id'] <= 0) {
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refund_succeeded_unactivated', $purchase, $eventData, 0, 'system', (int)$purchase['store_id']);
            return;
        }

        $instance = $this->requireRow($instanceDao->get((int)$purchase['instance_id']), 'package_instance_not_found');
        $usedCount = (int)$itemDao->search([])
            ->where('package_instance_id', (int)$instance['id'])
            ->where(function ($query) {
                $query->where('quantity_used', '>', 0)->whereOr('status', 'used');
            })
            ->count();
        $refundStatus = $usedCount > 0 ? 'partial_fulfillment_refunded' : 'full_refunded_no_fulfillment';
        $instanceStatus = $usedCount > 0 ? 'closed' : 'refunded';
        $instanceDao->update((int)$instance['id'], [
            'status' => $instanceStatus,
            'refund_status' => $refundStatus,
            'fulfilled_count' => $usedCount,
            'close_reason' => (string)($eventData['refund_reason_wap'] ?? 'refund_succeeded'),
            'update_time' => time(),
        ]);
        $planDao->update(['package_instance_id' => (int)$instance['id']], [
            'status' => $instanceStatus === 'refunded' ? 'refunded' : 'closed',
            'update_time' => time(),
        ]);
        $periodDao->search([])->where('package_instance_id', (int)$instance['id'])->update([
            'status' => $instanceStatus === 'refunded' ? 'refunded' : 'closed',
            'update_time' => time(),
        ]);
        $itemDao->search([])->where('package_instance_id', (int)$instance['id'])->where('status', '<>', 'used')->update([
            'status' => $instanceStatus === 'refunded' ? 'refunded' : 'closed',
            'update_time' => time(),
        ]);
        app()->make(PackageInstanceServices::class)->recomputeMemberIdentity((int)$purchase['uid']);
        $this->recordPackageAudit('package_instance', (string)$instance['id'], 'refund_succeeded', $instance, array_merge($eventData, [
            'status' => $instanceStatus,
            'refund_status' => $refundStatus,
            'used_count' => $usedCount,
        ]), 0, 'system', (int)$purchase['store_id']);
    }

    private function findPurchaseByOrder(int $orderId = 0, string $orderSn = ''): array
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        $purchase = null;
        if ($orderId > 0) {
            $purchase = $purchaseDao->getOne(['order_id' => $orderId]);
        }
        if (!$purchase && $orderSn !== '') {
            $purchase = $purchaseDao->getOne(['order_sn' => $orderSn]);
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
}
