<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\HqAuthorityAdminReadServices;
use app\services\yfth\HqAuthorityAuditReadServices;

class HqAuthorityRead extends AuthController
{
    public function attributionList(HqAuthorityAdminReadServices $services)
    {
        $this->assertAuth('yfth/hq_authority/attribution', 'GET');
        return app('json')->success($services->attributionList($this->request->getMore([
            [['uid', 'd'], 0], [['store_id', 'd'], 0], ['status', ''],
            ['start_date', ''], ['end_date', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function attributionDetail(HqAuthorityAdminReadServices $services, $id)
    {
        $this->assertAuth('yfth/hq_authority/attribution/<id>', 'GET');
        return app('json')->success($services->attributionDetail((int)$id, $this->adminInfo ?: []));
    }

    public function referralList(HqAuthorityAdminReadServices $services)
    {
        $this->assertAuth('yfth/hq_authority/referral', 'GET');
        return app('json')->success($services->referralList($this->request->getMore([
            [['store_id', 'd'], 0], [['referrer_uid', 'd'], 0], [['referred_uid', 'd'], 0],
            ['status', ''], ['start_date', ''], ['end_date', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function referralDetail(HqAuthorityAdminReadServices $services, $id)
    {
        $this->assertAuth('yfth/hq_authority/referral/<id>', 'GET');
        return app('json')->success($services->referralDetail((int)$id, $this->adminInfo ?: []));
    }

    public function attributionEvents(HqAuthorityAuditReadServices $services, $id)
    {
        $this->assertAuth('yfth/hq_authority/attribution/<id>/events', 'GET');
        return app('json')->success($services->attributionEvents((int)$id, $this->adminInfo ?: []));
    }

    public function referralEvents(HqAuthorityAuditReadServices $services, $id)
    {
        $this->assertAuth('yfth/hq_authority/referral/<id>/events', 'GET');
        return app('json')->success($services->referralEvents((int)$id, $this->adminInfo ?: []));
    }

    private function assertAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
