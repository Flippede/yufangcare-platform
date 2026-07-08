<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\ReferralRewardServices;

class ReferralReward extends AuthController
{
    public function ruleList(ReferralRewardServices $services)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/rule', 'GET');
        return app('json')->success($services->adminRuleList($this->request->getMore([
            ['scene', ''],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function ruleSave(ReferralRewardServices $services)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/rule', 'POST');
        return app('json')->success($services->adminRuleSave($this->request->postMore([
            [['id', 'd'], 0],
            ['scene', 'package_5980'],
            ['name', ''],
            [['version_no', 'd'], 1],
            ['status', 'draft'],
            ['effective_start', 0],
            ['effective_end', 0],
            ['items', []],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function rulePublish(ReferralRewardServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/rule/<id>/publish', 'POST');
        return app('json')->success($services->adminRulePublish((int)$id, (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function ruleCopy(ReferralRewardServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/rule/<id>/copy', 'POST');
        return app('json')->success($services->adminRuleCopy((int)$id, (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function candidateList(ReferralRewardServices $services)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/candidate', 'GET');
        return app('json')->success($services->adminCandidateList($this->request->getMore([
            ['scene', ''],
            ['status', ''],
            [['referrer_uid', 'd'], 0],
        ]), $this->adminInfo ?: []));
    }

    public function eventList(ReferralRewardServices $services)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/event', 'GET');
        return app('json')->success($services->adminEventList($this->request->getMore([
            ['scene', ''],
            ['event_type', ''],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function attributionList(ReferralRewardServices $services)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/attribution', 'GET');
        return app('json')->success($services->adminAttributionList($this->request->getMore([
            ['scene', ''],
            ['business_type', ''],
            ['status', ''],
            [['referrer_uid', 'd'], 0],
        ]), $this->adminInfo ?: []));
    }

    public function ledgerList(ReferralRewardServices $services)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/ledger', 'GET');
        return app('json')->success($services->adminLedgerList($this->request->getMore([
            ['scene', ''],
            ['status', ''],
            ['business_type', ''],
            [['referrer_uid', 'd'], 0],
        ]), $this->adminInfo ?: []));
    }

    public function ledgerDetail(ReferralRewardServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/ledger/<id>', 'GET');
        return app('json')->success($services->adminLedgerDetail((int)$id, $this->adminInfo ?: []));
    }

    public function ledgerSettle(ReferralRewardServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/ledger/<id>/settle', 'POST');
        return app('json')->success($services->adminSettleLedger((int)$id, $this->request->postMore([
            ['offline_ref_no', ''],
            ['remark', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function ledgerCancelSettlement(ReferralRewardServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/ledger/<id>/cancel_settlement', 'POST');
        return app('json')->success($services->adminCancelSettlement((int)$id, $this->request->postMore([
            ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function ledgerReverse(ReferralRewardServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/ledger/<id>/reverse', 'POST');
        return app('json')->success($services->adminReverseLedger((int)$id, $this->request->postMore([
            ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function scan(ReferralRewardServices $services)
    {
        $this->assertAdminApiAuth('yfth/referral_reward/scan', 'POST');
        return app('json')->success($services->adminScan($this->request->postMore([
            [['dry_run', 'd'], 1],
            [['limit', 'd'], 50],
            ['scene', 'all'],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
