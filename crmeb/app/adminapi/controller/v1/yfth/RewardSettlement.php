<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\AdminStoreContextServices;
use app\services\yfth\DirectReferralRewardSettlementServices;

class RewardSettlement extends AuthController
{
    public function candidates(DirectReferralRewardSettlementServices $services)
    {
        $this->auth('yfth/reward_settlement/candidate', 'GET');
        return app('json')->success($services->headquartersCandidates($this->request->getMore([
            [['referrer_uid', 'd'], 0],
            [['referred_uid', 'd'], 0],
            [['store_id', 'd'], 0],
            ['candidate_type', ''],
            ['status', ''],
        ])));
    }

    public function cancel(DirectReferralRewardSettlementServices $services, $id)
    {
        $this->auth('yfth/reward_settlement/candidate/<id>/cancel', 'POST');
        return app('json')->success($services->cancelByHeadquarters((int)$id, (int)$this->adminId, $this->request->postMore([
            ['request_id', ''],
            ['reason', ''],
        ])));
    }

    public function correct(DirectReferralRewardSettlementServices $services, $id)
    {
        $this->auth('yfth/reward_settlement/candidate/<id>/correct', 'POST');
        return app('json')->success($services->correctByHeadquarters((int)$id, (int)$this->adminId, $this->request->postMore([
            ['request_id', ''],
            ['reason', ''],
        ])));
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($this->adminInfo ?: []);
    }
}
