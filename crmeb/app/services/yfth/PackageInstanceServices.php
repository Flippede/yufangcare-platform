<?php

namespace app\services\yfth;

use app\dao\yfth\YfthBenefitItemDao;
use app\dao\yfth\YfthBenefitPeriodDao;
use app\dao\yfth\YfthPackageInstanceDao;
use app\dao\yfth\YfthUserIdentityDao;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;

class PackageInstanceServices extends PackageBenefitBaseServices
{
    public function __construct(YfthPackageInstanceDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminList(array $where): array
    {
        $where = $this->cleanWhere([
            'uid' => (int)($where['uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'template_id' => (int)($where['template_id'] ?? 0) ?: '',
            'status' => $where['status'] ?? '',
            'refund_status' => $where['refund_status'] ?? '',
            'order_sn' => $where['order_sn'] ?? '',
        ]);
        return $this->pageList($where, '*', 'id desc', function ($row) {
            return $this->formatInstance($row);
        });
    }

    public function myPackages(int $uid): array
    {
        if ($uid <= 0) {
            throw new ApiException('user_login_required');
        }
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $where = ['uid' => $uid];
        $list = $this->dao->selectList($where, '*', $page, $limit, 'id desc', [], false)->toArray();
        return [
            'list' => array_map(function ($row) {
                return $this->formatInstance($row);
            }, $list),
            'count' => $this->dao->getCount($where),
        ];
    }

    public function userDetail(int $uid, int $instanceId): array
    {
        $row = $this->requireRow($this->dao->get($instanceId), 'package_instance_not_found');
        if ((int)$row['uid'] !== $uid) {
            throw new ApiException('package_instance_forbidden');
        }
        return $this->detailPayload($row);
    }

    public function adminDetail(int $instanceId): array
    {
        return $this->detailPayload($this->requireRow($this->dao->get($instanceId), 'package_instance_not_found'));
    }

    public function changeState(int $instanceId, string $toStatus, string $reason, int $operatorUid = 0)
    {
        if (trim($reason) === '') {
            throw new AdminException('state_change_reason_required');
        }
        $row = $this->requireRow($this->dao->get($instanceId), 'package_instance_not_found');
        $this->assertTransition('instance', (string)$row['status'], $toStatus);
        $before = $row;
        $data = [
            'status' => $toStatus,
            'close_reason' => $reason,
            'update_time' => time(),
        ];
        $result = $this->dao->update($instanceId, $data);
        $after = $this->dao->get($instanceId)->toArray();
        $this->recordPackageAudit('package_instance', (string)$instanceId, 'change_state', $before, $after, $operatorUid, 'admin', (int)$row['store_id'], $reason);
        $this->recomputeMemberIdentity((int)$row['uid']);
        return $result;
    }

    public function recomputeMemberIdentity(int $uid): void
    {
        /** @var YfthUserIdentityDao $identityDao */
        $identityDao = app()->make(YfthUserIdentityDao::class);
        /** @var UserIdentityServices $identityServices */
        $identityServices = app()->make(UserIdentityServices::class);
        $now = time();
        $activeInstances = $this->dao->search([])
            ->where('uid', $uid)
            ->where('status', 'active')
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->select()
            ->toArray();
        $activeIds = array_map('intval', array_column($activeInstances, 'id'));

        foreach ($activeInstances as $instance) {
            $identityServices->saveIdentity([
                'uid' => $uid,
                'role_code' => 'member_5980',
                'status' => YfthConstants::STATUS_ACTIVE,
                'source_type' => 'package_instance',
                'source_id' => (int)$instance['id'],
                'effective_time' => (int)$instance['start_time'],
                'expire_time' => (int)$instance['end_time'],
            ]);
        }

        $identityRows = $identityDao->selectList([
            'uid' => $uid,
            'role_code' => 'member_5980',
            'source_type' => 'package_instance',
            'status' => YfthConstants::STATUS_ACTIVE,
        ], '*', 0, 0, 'id desc', [], false)->toArray();
        foreach ($identityRows as $identity) {
            if (!in_array((int)$identity['source_id'], $activeIds, true)) {
                $identityDao->update((int)$identity['id'], [
                    'status' => YfthConstants::STATUS_DISABLED,
                    'active_key' => null,
                    'expire_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }
    }

    private function detailPayload(array $row): array
    {
        $row = $this->formatInstance($row);
        /** @var BenefitPlanServices $planServices */
        $planServices = app()->make(BenefitPlanServices::class);
        $row['plan'] = $planServices->planByInstance((int)$row['id']);
        /** @var BenefitPeriodServices $periodServices */
        $periodServices = app()->make(BenefitPeriodServices::class);
        $row['timeline'] = $periodServices->timeline((int)$row['uid'], (int)$row['id']);
        return $row;
    }

    private function formatInstance(array $row): array
    {
        $row['rule_snapshot'] = $this->jsonDecode($row['rule_snapshot'] ?? '');
        $row['store_snapshot'] = $this->jsonDecode($row['store_snapshot'] ?? '');
        return $row;
    }
}
