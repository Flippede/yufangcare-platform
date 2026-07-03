<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthPackageInstanceDao;
use crmeb\exceptions\ApiException;

class ServiceBenefitConsumptionServices extends PackageBenefitBaseServices
{
    public function consumeForServiceWriteoff(array $appointment, array $benefitLock, int $writeoffId, int $operatorId, string $roleCode, int $storeId, string $requestId): array
    {
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthBenefitPeriodDao $periodDao */
        $periodDao = app()->make(YfthBenefitPeriodDao::class);

        $item = $this->requireRow($itemDao->search([])->where('id', (int)$benefitLock['benefit_item_id'])->lock(true)->find(), 'benefit_item_not_found');
        $instance = $this->requireRow($instanceDao->search([])->where('id', (int)$benefitLock['package_instance_id'])->lock(true)->find(), 'package_instance_not_found');
        $plan = $this->requireRow($planDao->search([])->where('id', (int)$benefitLock['benefit_plan_id'])->lock(true)->find(), 'benefit_plan_not_found');
        $period = $this->requireRow($periodDao->search([])->where('id', (int)$benefitLock['benefit_period_id'])->lock(true)->find(), 'benefit_period_not_found');

        $this->assertLinkedRows($appointment, $benefitLock, $item, $instance, $plan, $period);
        if ((string)$item['benefit_type'] !== 'service') {
            throw new ApiException('only_service_benefit_can_writeoff');
        }
        if ((string)$item['status'] !== 'available' || (float)$item['quantity_available'] <= 0 || (float)$item['quantity_used'] >= (float)$item['quantity_total']) {
            throw new ApiException('benefit_item_not_available');
        }
        if ((string)$instance['status'] !== 'active' || !in_array((string)($instance['refund_status'] ?? 'none'), ['', 'none'], true)) {
            throw new ApiException('package_instance_not_active');
        }
        if ((string)$plan['status'] !== 'active') {
            throw new ApiException('benefit_plan_not_active');
        }
        if ((string)$period['status'] !== 'available') {
            throw new ApiException('benefit_period_not_available');
        }

        $this->assertTransition('item', (string)$item['status'], 'used');
        $before = $item;
        $quantityTotal = $this->normalizeMoney($item['quantity_total']);
        $itemDao->update((int)$item['id'], [
            'status' => 'used',
            'fulfillment_status' => 'service_writeoff',
            'quantity_available' => '0.00',
            'quantity_used' => $quantityTotal,
            'update_time' => time(),
        ]);
        $periodDao->update((int)$period['id'], [
            'fulfilled_item_count' => (int)$period['fulfilled_item_count'] + 1,
            'update_time' => time(),
        ]);
        $instanceDao->update((int)$instance['id'], [
            'fulfilled_count' => (int)$instance['fulfilled_count'] + 1,
            'update_time' => time(),
        ]);

        $after = $this->requireRow($itemDao->get((int)$item['id']), 'benefit_item_not_found');
        $this->recordPackageAudit('benefit_item', (string)$item['id'], 'service_writeoff', $before, $after, $operatorId, $roleCode, $storeId, 'service_writeoff:' . $writeoffId, $requestId);
        return [
            'before_item' => $before,
            'after_item' => $after,
            'package_instance_id' => (int)$instance['id'],
            'benefit_plan_id' => (int)$plan['id'],
            'benefit_period_id' => (int)$period['id'],
            'benefit_item_id' => (int)$item['id'],
        ];
    }

    private function assertLinkedRows(array $appointment, array $benefitLock, array $item, array $instance, array $plan, array $period): void
    {
        $pairs = [
            [(int)$appointment['uid'], (int)$benefitLock['uid'], 'benefit_lock_uid_mismatch'],
            [(int)$appointment['benefit_item_id'], (int)$benefitLock['benefit_item_id'], 'benefit_lock_item_mismatch'],
            [(int)$appointment['package_instance_id'], (int)$benefitLock['package_instance_id'], 'benefit_lock_instance_mismatch'],
            [(int)$appointment['benefit_plan_id'], (int)$benefitLock['benefit_plan_id'], 'benefit_lock_plan_mismatch'],
            [(int)$appointment['benefit_period_id'], (int)$benefitLock['benefit_period_id'], 'benefit_lock_period_mismatch'],
            [(int)$item['uid'], (int)$benefitLock['uid'], 'benefit_item_uid_mismatch'],
            [(int)$item['package_instance_id'], (int)$instance['id'], 'benefit_item_instance_mismatch'],
            [(int)$item['plan_id'], (int)$plan['id'], 'benefit_item_plan_mismatch'],
            [(int)$item['period_id'], (int)$period['id'], 'benefit_item_period_mismatch'],
            [(int)$plan['package_instance_id'], (int)$instance['id'], 'benefit_plan_instance_mismatch'],
            [(int)$period['package_instance_id'], (int)$instance['id'], 'benefit_period_instance_mismatch'],
            [(int)$period['plan_id'], (int)$plan['id'], 'benefit_period_plan_mismatch'],
        ];
        foreach ($pairs as $pair) {
            if ($pair[0] !== $pair[1]) {
                throw new ApiException($pair[2]);
            }
        }
    }
}
