<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use crmeb\exceptions\ApiException;

class BenefitPeriodServices extends PackageBenefitBaseServices
{
    public function __construct(YfthBenefitPeriodDao $dao)
    {
        $this->dao = $dao;
    }

    public function openDuePeriods(int $now = 0): array
    {
        $now = $now ?: time();
        /** @var YfthBenefitItemDao $itemDao */
        $itemDao = app()->make(YfthBenefitItemDao::class);
        $opened = 0;
        $openedItems = 0;
        $expired = 0;
        $expiredItems = 0;

        $periods = $this->dao->search([])
            ->where('status', 'unopened')
            ->where('open_at', '<=', $now)
            ->select()
            ->toArray();
        foreach ($periods as $period) {
            $this->dao->update((int)$period['id'], ['status' => 'available', 'update_time' => $now]);
            $itemDao->search([])
                ->where('period_id', (int)$period['id'])
                ->where('status', 'unopened')
                ->where('available_time', '<=', $now)
                ->update(['status' => 'available', 'update_time' => $now]);
            $opened++;
            $this->recordPackageAudit('benefit_period', (string)$period['id'], 'open', $period, array_merge($period, ['status' => 'available']), 0, 'timer', (int)$period['store_id']);
        }

        $availablePeriodIds = $this->dao->search([])
            ->where('status', 'available')
            ->column('id');
        if ($availablePeriodIds) {
            $openedItems = $itemDao->search([])
                ->whereIn('period_id', $availablePeriodIds)
                ->where('status', 'unopened')
                ->where('available_time', '<=', $now)
                ->update(['status' => 'available', 'update_time' => $now]);

            $expiredItems = $itemDao->search([])
                ->whereIn('period_id', $availablePeriodIds)
                ->where('status', 'available')
                ->where('expire_time', '>', 0)
                ->where('expire_time', '<', $now)
                ->update(['status' => 'expired', 'update_time' => $now]);
        }

        $expirePeriods = $this->dao->search([])
            ->where('status', 'available')
            ->where('expire_at', '>', 0)
            ->where('expire_at', '<', $now)
            ->select()
            ->toArray();
        foreach ($expirePeriods as $period) {
            $this->dao->update((int)$period['id'], ['status' => 'expired', 'update_time' => $now]);
            $itemDao->search([])
                ->where('period_id', (int)$period['id'])
                ->where('status', 'available')
                ->where('expire_time', '<', $now)
                ->update(['status' => 'expired', 'update_time' => $now]);
            $expired++;
            $this->recordPackageAudit('benefit_period', (string)$period['id'], 'expire', $period, array_merge($period, ['status' => 'expired']), 0, 'timer', (int)$period['store_id']);
        }

        return ['opened' => $opened, 'opened_items' => $openedItems, 'expired' => $expired, 'expired_items' => $expiredItems, 'checked_at' => $now];
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
