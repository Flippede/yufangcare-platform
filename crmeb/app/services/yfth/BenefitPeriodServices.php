<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthBenefitPlanDao;
use app\dao\yfth\YfthPackageInstanceDao;
use crmeb\exceptions\ApiException;

class BenefitPeriodServices extends PackageBenefitBaseServices
{
    public function __construct(YfthBenefitPeriodDao $dao)
    {
        $this->dao = $dao;
    }

    public function openDuePeriods(int $now = 0, int $limit = 100): array
    {
        $now = $now ?: time();
        $limit = max(1, min($limit, 200));
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        $result = [
            'opened' => 0,
            'opened_items' => 0,
            'expired' => 0,
            'expired_items' => 0,
            'skipped' => 0,
            'errors' => [],
            'checked_at' => $now,
            'limit' => $limit,
        ];

        $periods = $this->dao->search([])
            ->where('status', 'unopened')
            ->where('open_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->where('expire_at', '=', 0)->whereOr('expire_at', '>=', $now);
            })
            ->order('open_at asc,id asc')
            ->limit($limit)
            ->select()
            ->toArray();
        foreach ($periods as $period) {
            try {
                $opened = $this->transaction(function () use ($period, $now, $itemDao) {
                    $locked = $this->lockPeriod((int)$period['id']);
                    if ((string)$locked['status'] !== 'unopened') {
                        return ['skipped' => true, 'reason' => 'period_status_changed'];
                    }
                    if (!$this->isPeriodOpenable($locked)) {
                        return ['skipped' => true, 'reason' => 'plan_or_instance_not_active'];
                    }
                    if ((int)$locked['expire_at'] > 0 && (int)$locked['expire_at'] < $now) {
                        return ['skipped' => true, 'reason' => 'period_expired_before_open'];
                    }
                    $this->dao->update((int)$locked['id'], ['status' => 'available', 'update_time' => $now]);
                    $openedItems = $this->openItemsForPeriod($itemDao, (int)$locked['id'], $now);
                    $this->syncOpenedMonth((int)$locked['plan_id'], (int)$locked['month_no'], $now);
                    $this->recordPackageAudit('benefit_period', (string)$locked['id'], 'open', $locked, array_merge($locked, ['status' => 'available']), 0, 'timer', (int)$locked['store_id']);
                    return ['opened' => true, 'opened_items' => $openedItems];
                });
                if (!empty($opened['opened'])) {
                    $result['opened']++;
                    $result['opened_items'] += (int)$opened['opened_items'];
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['period_id' => (int)$period['id'], 'error' => $e->getMessage()];
            }
        }

        $expirePeriods = $this->dao->search([])
            ->where('status', 'available')
            ->order('expire_at asc,id asc')
            ->limit($limit)
            ->select()
            ->toArray();
        foreach ($expirePeriods as $period) {
            try {
                $expired = $this->transaction(function () use ($period, $now, $itemDao) {
                    $locked = $this->lockPeriod((int)$period['id']);
                    if ((string)$locked['status'] !== 'available') {
                        return ['skipped' => true, 'reason' => 'period_status_changed'];
                    }
                    if (!$this->isPeriodOpenable($locked)) {
                        return ['skipped' => true, 'reason' => 'plan_or_instance_not_active'];
                    }
                    $openedItems = $this->openItemsForPeriod($itemDao, (int)$locked['id'], $now);
                    $expiredItems = $this->expireItemsForPeriod($itemDao, (int)$locked['id'], $now);
                    if ((int)$locked['expire_at'] > 0 && (int)$locked['expire_at'] < $now) {
                        $this->dao->update((int)$locked['id'], ['status' => 'expired', 'update_time' => $now]);
                        $expiredItems += $itemDao->search([])
                            ->where('period_id', (int)$locked['id'])
                            ->whereIn('status', ['unopened', 'available'])
                            ->update(['status' => 'expired', 'update_time' => $now]);
                        $this->recordPackageAudit('benefit_period', (string)$locked['id'], 'expire', $locked, array_merge($locked, ['status' => 'expired']), 0, 'timer', (int)$locked['store_id']);
                        return ['expired' => true, 'opened_items' => $openedItems, 'expired_items' => $expiredItems];
                    }
                    return ['expired' => false, 'opened_items' => $openedItems, 'expired_items' => $expiredItems];
                });
                $result['opened_items'] += (int)($expired['opened_items'] ?? 0);
                $result['expired_items'] += (int)($expired['expired_items'] ?? 0);
                if (!empty($expired['expired'])) {
                    $result['expired']++;
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['period_id' => (int)$period['id'], 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    private function lockPeriod(int $periodId): array
    {
        return $this->requireRow($this->dao->search([])->where('id', $periodId)->lock(true)->find(), 'benefit_period_not_found');
    }

    private function isPeriodOpenable(array $period): bool
    {
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        /** @var YfthPackageInstanceDao $instanceDao */
        $instanceDao = app()->make(YfthPackageInstanceDao::class);
        $plan = $planDao->get((int)$period['plan_id']);
        $instance = $instanceDao->get((int)$period['package_instance_id']);
        if (!$plan || !$instance) {
            return false;
        }
        if ((string)$plan['status'] !== 'active' || (string)$instance['status'] !== 'active') {
            return false;
        }
        return in_array((string)($instance['refund_status'] ?? 'none'), ['', 'none'], true);
    }

    private function openItemsForPeriod(YfthBenefitItemDao $itemDao, int $periodId, int $now): int
    {
        return (int)$itemDao->search([])
            ->where('period_id', $periodId)
            ->where('status', 'unopened')
            ->where('available_time', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->where('expire_time', '=', 0)->whereOr('expire_time', '>=', $now);
            })
            ->update(['status' => 'available', 'update_time' => $now]);
    }

    private function expireItemsForPeriod(YfthBenefitItemDao $itemDao, int $periodId, int $now): int
    {
        return (int)$itemDao->search([])
            ->where('period_id', $periodId)
            ->where('status', 'available')
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<', $now)
            ->update(['status' => 'expired', 'update_time' => $now]);
    }

    private function syncOpenedMonth(int $planId, int $monthNo, int $now): void
    {
        /** @var YfthBenefitPlanDao $planDao */
        $planDao = app()->make(YfthBenefitPlanDao::class);
        $planDao->search([])
            ->where('id', $planId)
            ->where('opened_month_no', '<', $monthNo)
            ->update(['opened_month_no' => $monthNo, 'update_time' => $now]);
    }

    public function timeline(int $uid, int $instanceId): array
    {
        if ($uid <= 0 || $instanceId <= 0) {
            throw new ApiException('uid_and_instance_id_required');
        }
        $periods = $this->dao->selectList([
            'uid' => $uid,
            'package_instance_id' => $instanceId,
        ], '*', 0, 0, 'month_no asc', [], false)->toArray();

        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        foreach ($periods as &$period) {
            $period['items'] = $itemDao->selectList(['period_id' => (int)$period['id']], '*', 0, 0, 'id asc', [], false)->toArray();
        }
        return $periods;
    }

    public function currentMonthBenefits(int $uid, int $instanceId = 0): array
    {
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        $query = $itemDao->search([])
            ->where('uid', $uid)
            ->where('status', 'available');
        if ($instanceId > 0) {
            $query->where('package_instance_id', $instanceId);
        }
        return $query->order('month_no asc,id asc')->select()->toArray();
    }

    public function benefitHistory(int $uid): array
    {
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $where = ['uid' => $uid];
        return [
            'list' => $itemDao->selectList($where, '*', $page, $limit, 'id desc', [], false)->toArray(),
            'count' => $itemDao->getCount($where),
        ];
    }
}
