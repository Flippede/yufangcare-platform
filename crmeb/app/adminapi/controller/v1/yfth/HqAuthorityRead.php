<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\HqAuthorityAdminReadServices;
use app\services\yfth\HqAuthorityAuditReadServices;
use app\services\yfth\HqAuthorityReadParameterServices;

class HqAuthorityRead extends AuthController
{
    public function attributionList(HqAuthorityAdminReadServices $services, HqAuthorityReadParameterServices $parameters)
    {
        $this->assertAuth('yfth/hq_authority/attribution', 'GET');
        return app('json')->success($services->attributionList(
            $parameters->attributionFilters($this->request->get()),
            $this->adminInfo ?: []
        ));
    }

    public function attributionDetail(HqAuthorityAdminReadServices $services, HqAuthorityReadParameterServices $parameters, $id)
    {
        $this->assertAuth('yfth/hq_authority/attribution/<id>', 'GET');
        return app('json')->success($services->attributionDetail($parameters->positiveId($id, 'attribution_id'), $this->adminInfo ?: []));
    }

    public function referralList(HqAuthorityAdminReadServices $services, HqAuthorityReadParameterServices $parameters)
    {
        $this->assertAuth('yfth/hq_authority/referral', 'GET');
        return app('json')->success($services->referralList(
            $parameters->referralFilters($this->request->get()),
            $this->adminInfo ?: []
        ));
    }

    public function referralDetail(HqAuthorityAdminReadServices $services, HqAuthorityReadParameterServices $parameters, $id)
    {
        $this->assertAuth('yfth/hq_authority/referral/<id>', 'GET');
        return app('json')->success($services->referralDetail($parameters->positiveId($id, 'referral_id'), $this->adminInfo ?: []));
    }

    public function attributionEvents(HqAuthorityAuditReadServices $services, HqAuthorityReadParameterServices $parameters, $id)
    {
        $this->assertAuth('yfth/hq_authority/attribution/<id>/events', 'GET');
        return app('json')->success($services->attributionEvents($parameters->positiveId($id, 'attribution_id'), $this->adminInfo ?: []));
    }

    public function referralEvents(HqAuthorityAuditReadServices $services, HqAuthorityReadParameterServices $parameters, $id)
    {
        $this->assertAuth('yfth/hq_authority/referral/<id>/events', 'GET');
        return app('json')->success($services->referralEvents($parameters->positiveId($id, 'referral_id'), $this->adminInfo ?: []));
    }

    private function assertAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
