<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthPackagePurchaseDao;
use crmeb\exceptions\AdminException;

class PackageLifecycleServices extends PackageBenefitBaseServices
{
    public function markRefunding(array $purchase, string $reason, array $eventData = []): void
    {
        $this->transaction(function () use ($purchase, $reason, $eventData) {
            /** @var YfthPackagePurchaseDao $purchaseDao */
            $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
            $purchase = $this->lockPurchase((int)$purchase['id']);
            $before = $purchase;
            if ((string)$purchase['purchase_status'] !== 'refunding') {
                $this->assertTransition('purchase', (string)$purchase['purchase_status'], 'refunding');
                $purchaseDao->update((int)$purchase['id'], ['purchase_status' => 'refunding', 'update_time' => time()]);
            }
            if ((int)$purchase['instance_id'] > 0) {
                $this->syncInstanceState((int)$purchase['instance_id'], 'refunding', 'refunding', 'pending', $reason);
            }
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refunding', $before, array_merge($eventData, ['purchase_status' => 'refunding']), 0, 'system', (int)$purchase['store_id'], $reason);
        });
    }

    public function restoreAfterRefundCancel(array $purchase, array $eventData = []): void
    {
        $this->transaction(function () use ($purchase, $eventData) {
            /** @var YfthPackagePurchaseDao $purchaseDao */
            $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
            $purchase = $this->lockPurchase((int)$purchase['id']);
            $target = (int)$purchase['instance_id'] > 0 ? 'activated' : 'wait_pay';
            $purchaseDao->update((int)$purchase['id'], ['purchase_status' => $target, 'update_time' => time()]);
            if ((int)$purchase['instance_id'] > 0) {
                $this->syncInstanceState((int)$purchase['instance_id'], 'active', 'active', 'none', 'refund_cancel');
                app()->make(PackageInstanceServices::class)->recomputeMemberIdentity((int)$purchase['uid']);
            }
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refund_cancel', $purchase, array_merge($eventData, ['purchase_status' => $target]), 0, 'system', (int)$purchase['store_id']);
        });
    }

    public function markRefundSucceeded(array $purchase, array $eventData = []): void
    {
        $this->transaction(function () use ($purchase, $eventData) {
            /** @var YfthPackagePurchaseDao $purchaseDao */
            $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
            /** @var YfthBenefitItemDao $itemDao */
            $itemDao = app()->make(YfthBenefitItemDao::class);
            $purchase = $this->lockPurchase((int)$purchase['id']);

            if ((int)$purchase['instance_id'] <= 0) {
                $this->assertTransition('purchase', (string)$purchase['purchase_status'], 'refunded');
                $purchaseDao->update((int)$purchase['id'], ['purchase_status' => 'refunded', 'update_time' => time()]);
                $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refund_succeeded_unactivated', $purchase, $eventData, 0, 'system', (int)$purchase['store_id']);
                return;
            }

            $usedCount = (int)$itemDao->search([])
                ->where('package_instance_id', (int)$purchase['instance_id'])
                ->where(function ($query) {
                    $query->where('quantity_used', '>', 0)->whereOr('status', 'used');
                })
                ->count();
            if ($usedCount > 0) {
                $this->assertTransition('purchase', (string)$purchase['purchase_status'], 'closed_after_partial_refund');
                $purchaseDao->update((int)$purchase['id'], ['purchase_status' => 'closed_after_partial_refund', 'update_time' => time()]);
                $this->syncInstanceState((int)$purchase['instance_id'], 'closed', 'closed', 'partial_fulfillment_refunded', (string)($eventData['refund_reason_wap'] ?? 'refund_succeeded'));
            } else {
                $this->assertTransition('purchase', (string)$purchase['purchase_status'], 'refunded');
                $purchaseDao->update((int)$purchase['id'], ['purchase_status' => 'refunded', 'update_time' => time()]);
                $this->syncInstanceState((int)$purchase['instance_id'], 'refunded', 'refunded', 'full_refunded_no_fulfillment', (string)($eventData['refund_reason_wap'] ?? 'refund_succeeded'));
            }
            app()->make(PackageInstanceServices::class)->recomputeMemberIdentity((int)$purchase['uid']);
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refund_succeeded', $purchase, array_merge($eventData, ['used_count' => $usedCount]), 0, 'system', (int)$purchase['store_id']);
        });
    }

    public function restoreAfterRefundFailed(array $purchase, array $eventData = []): void
    {
        $this->transaction(function () use ($purchase, $eventData) {
            /** @var YfthPackagePurchaseDao $purchaseDao */
            $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
            $purchase = $this->lockPurchase((int)$purchase['id']);
            $target = (int)$purchase['instance_id'] > 0 ? 'activated' : 'wait_pay';
            $purchaseDao->update((int)$purchase['id'], ['purchase_status' => $target, 'update_time' => time()]);
            if ((int)$purchase['instance_id'] > 0) {
                $this->syncInstanceState((int)$purchase['instance_id'], 'active', 'active', 'none', 'refund_failed_restore');
                app()->make(PackageInstanceServices::class)->recomputeMemberIdentity((int)$purchase['uid']);
            }
            $this->recordPackageAudit('package_purchase', (string)$purchase['id'], 'refund_failed_restored', $purchase, array_merge($eventData, ['purchase_status' => $target]), 0, 'system', (int)$purchase['store_id'], (string)($eventData['refuse_reason'] ?? ''));
        });
    }

    public function changeInstanceState(int $instanceId, string $toStatus, string $reason, int $operatorUid = 0)
    {
        if (trim($reason) === '') {
            throw new AdminException('state_change_reason_required');
        }
        return $this->transaction(function () use ($instanceId, $toStatus, $reason, $operatorUid) {
            /** @var YfthPackageInstanceDao $instanceDao */
            $instanceDao = app()->make(YfthPackageInstanceDao::class);
            /** @var YfthPackagePurchaseDao $purchaseDao */
            $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
            $instance = $this->requireRow($instanceDao->search([])->where('id', $instanceId)->lock(true)->find(), 'package_instance_not_found');
            $before = $instance;
            $planStatus = in_array($toStatus, ['frozen', 'suspended'], true) ? 'paused' : $toStatus;
            $refundStatus = in_array($toStatus, ['refunded', 'closed'], true) ? 'manual_closed' : null;
            $this->syncInstanceState($instanceId, $toStatus, $planStatus, $refundStatus, $reason);
            if ((int)$instance['purchase_id'] > 0) {
                $purchase = $this->lockPurchase((int)$instance['purchase_id']);
                $purchaseStatus = $this->purchaseStatusForInstanceStatus($toStatus);
                if ($purchaseStatus && (string)$purchase['purchase_status'] !== $purchaseStatus) {
                    $this->assertTransition('purchase', (string)$purchase['purchase_status'], $purchaseStatus);
                    $purchaseDao->update((int)$purchase['id'], ['purchase_status' => $purchaseStatus, 'update_time' => time()]);
                }
            }
            $after = $this->requireRow($instanceDao->get($instanceId), 'package_instance_not_found');
            $this->recordPackageAudit('package_instance', (string)$instanceId, 'lifecycle_state', $before, $after, $operatorUid, 'admin', (int)$instance['store_id'], $reason);
            app()->make(PackageInstanceServices::class)->recomputeMemberIdentity((int)$instance['uid']);
            return $after;
        });
    }

    private function lockPurchase(int $purchaseId): array
    {
        /** @var YfthPackagePurchaseDao $purchaseDao */
        $purchaseDao = app()->make(YfthPackagePurchaseDao::class);
        return $this->requireRow($purchaseDao->search([])->where('id', $purchaseId)->lock(true)->find(), 'package_purchase_not_found');
    }

    private function syncInstanceState(int $instanceId, string $instanceStatus, string $planStatus, ?string $refundStatus, string $reason): void
    {
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);

        $instance = $this->requireRow($instanceDao->get($instanceId), 'package_instance_not_found');
        $this->assertTransition('instance', (string)$instance['status'], $instanceStatus);
        $instanceUpdate = [
            'status' => $instanceStatus,
            'close_reason' => $reason,
            'update_time' => time(),
        ];
        if ($refundStatus !== null) {
            $instanceUpdate['refund_status'] = $refundStatus;
        }
        $instanceDao->update($instanceId, $instanceUpdate);

        $plan = $planDao->getOne(['package_instance_id' => $instanceId]);
        if ($plan) {
            $targetPlanStatus = $this->normalizePlanStatus($planStatus);
            $this->assertTransition('plan', (string)$plan['status'], $targetPlanStatus);
            $planDao->update((int)$plan['id'], ['status' => $targetPlanStatus, 'update_time' => time()]);
        }

        if (in_array($instanceStatus, ['closed', 'refunded'], true)) {
            $periodStatus = $instanceStatus === 'refunded' ? 'refunded' : 'closed';
            $periodDao->search([])->where('package_instance_id', $instanceId)->whereNotIn('status', ['closed', 'refunded'])->update([
                'status' => $periodStatus,
                'update_time' => time(),
            ]);
            $itemDao->search([])->where('package_instance_id', $instanceId)->whereNotIn('status', ['used', 'closed', 'refunded'])->update([
                'status' => $periodStatus,
                'update_time' => time(),
            ]);
        }
    }

    private function normalizePlanStatus(string $status): string
    {
        if (in_array($status, ['frozen', 'suspended'], true)) {
            return 'paused';
        }
        return $status;
    }

    private function purchaseStatusForInstanceStatus(string $status): string
    {
        $map = [
            'active' => 'activated',
            'frozen' => 'activated',
            'suspended' => 'activated',
            'refunding' => 'refunding',
            'refunded' => 'refunded',
            'closed' => 'closed',
            'expired' => 'closed',
        ];
        return $map[$status] ?? '';
    }
}
