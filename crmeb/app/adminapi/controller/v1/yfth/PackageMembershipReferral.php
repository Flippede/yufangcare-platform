<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\DirectReferralRewardServices;
use app\services\yfth\AdminStoreContextServices;
use app\services\yfth\PackageMembershipServices;
use crmeb\exceptions\AdminException;

class PackageMembershipReferral extends AuthController
{
    public function members(PackageMembershipServices $services)
    {
        $this->auth('yfth/package_membership/member', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([
            [['uid', 'd'], 0],
            [['store_id', 'd'], 0],
            ['status', ''],
        ])));
    }

    public function candidates(DirectReferralRewardServices $services)
    {
        $this->auth('yfth/package_membership/candidate', 'GET');
        return app('json')->success($services->candidateList($this->request->getMore([
            [['referrer_uid', 'd'], 0],
            [['referred_uid', 'd'], 0],
            [['store_id', 'd'], 0],
            ['candidate_type', ''],
            ['status', ''],
        ])));
    }

    public function rules(DirectReferralRewardServices $services)
    {
        $this->auth('yfth/package_membership/rule', 'GET');
        return app('json')->success($services->ruleList($this->request->getMore([
            ['status', ''],
        ])));
    }

    public function saveRule(DirectReferralRewardServices $services)
    {
        $this->auth('yfth/package_membership/rule', 'POST');
        return app('json')->success($services->saveRule($this->request->postMore([
            [['id', 'd'], 0],
            [['version_no', 'd'], 0],
            [['package_ratio_first_bps', 'd'], 1500],
            [['package_ratio_second_bps', 'd'], 2500],
            [['package_ratio_third_bps', 'd'], 6000],
            [['package_observation_days', 'd'], 0],
            [['mall_consumption_enabled', 'd'], 0],
            [['mall_consumption_ratio_bps', 'd'], 0],
            ['effective_at', 0],
            ['expires_at', 0],
        ]), (int)$this->adminId));
    }

    public function publishRule(DirectReferralRewardServices $services, $id)
    {
        $this->auth('yfth/package_membership/rule/<id>/publish', 'POST');
        return app('json')->success($services->publishRule((int)$id, (int)$this->adminId));
    }

    public function legacyBackfill(PackageMembershipServices $services)
    {
        $this->auth('yfth/package_membership/legacy_backfill', 'POST');
        $data = $this->request->postMore([
            ['mode', 'dry_run'],
            [['limit', 'd'], 100],
            ['reason', ''],
            ['request_id', ''],
        ]);
        if (!in_array((string)$data['mode'], ['dry_run', 'execute'], true)) {
            throw new AdminException('legacy_membership_backfill_mode_invalid');
        }
        $execute = (string)$data['mode'] === 'execute';
        return app('json')->success($services->legacyBackfill(
            $execute,
            (int)$data['limit'],
            (int)$this->adminId,
            (string)$data['reason'],
            (string)$data['request_id'] ?: ('legacy-membership-backfill-' . date('YmdHis'))
        ));
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($this->adminInfo ?: []);
    }
}
