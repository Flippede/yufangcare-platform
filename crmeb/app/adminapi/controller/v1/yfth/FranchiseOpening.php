<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\FranchiseOpeningServices;

class FranchiseOpening extends AuthController
{
    public function contractList(FranchiseOpeningServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/contract', 'GET');
        return app('json')->success($services->adminContractList($this->request->getMore([
            ['status', ''],
            [['application_id', 'd'], 0],
        ]), $this->adminInfo ?: []));
    }

    public function contractDetail(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/contract/<id>', 'GET');
        return app('json')->success($services->adminContractDetail((int)$id, $this->adminInfo ?: []));
    }

    public function contractCreate(FranchiseOpeningServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/contract/create', 'POST');
        return app('json')->success($services->adminCreateContract($this->request->postMore([
            [['application_id', 'd'], 0],
            ['amount_snapshot', '0.00'],
            ['attachment_ids', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function contractConfirm(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/contract/<id>/confirm', 'POST');
        return app('json')->success($services->adminConfirmContract((int)$id, $this->request->postMore([
            ['action', 'hq_confirm'],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function paymentList(FranchiseOpeningServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/payment', 'GET');
        return app('json')->success($services->adminPaymentList($this->request->getMore([
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function paymentConfirm(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/payment/<id>/confirm', 'POST');
        return app('json')->success($services->adminConfirmPayment((int)$id, (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function paymentReject(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/payment/<id>/reject', 'POST');
        $data = $this->request->postMore([['reason', '']]);
        return app('json')->success($services->adminRejectPayment((int)$id, (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function profileDetail(FranchiseOpeningServices $services, $application_id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/profile/<application_id>', 'GET');
        return app('json')->success($services->adminProfileDetail((int)$application_id, $this->adminInfo ?: []));
    }

    public function profileSave(FranchiseOpeningServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/profile/save', 'POST');
        return app('json')->success($services->adminSaveProfile($this->request->postMore([
            [['application_id', 'd'], 0],
            ['intended_store_type', ''],
            ['store_name', ''],
            ['province', ''],
            ['city', ''],
            ['district', ''],
            ['address', ''],
            [['business_subject_id', 'd'], 0],
            ['status', 'submitted'],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function profileBindStore(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/profile/<id>/bind_store', 'POST');
        $data = $this->request->postMore([[['system_store_id', 'd'], 0]]);
        return app('json')->success($services->adminBindStore((int)$id, (int)$data['system_store_id'], (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function profileCreateStore(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/profile/<id>/create_store', 'POST');
        return app('json')->success($services->adminCreateAndBindStore((int)$id, $this->request->postMore([
            ['image', ''], ['oblong_image', ''], ['latitude', ''], ['longitude', ''],
            ['valid_time', '09:00 - 18:00'], ['day_time', '周一至周日'], ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function taskList(FranchiseOpeningServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/task', 'GET');
        return app('json')->success($services->adminTaskList($this->request->getMore([
            [['application_id', 'd'], 0],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function taskReview(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/task/<id>/review', 'POST');
        return app('json')->success($services->adminReviewTask((int)$id, $this->request->postMore([
            ['action', 'approve'],
            ['reject_reason', ''],
            ['content', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function acceptanceList(FranchiseOpeningServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/acceptance', 'GET');
        return app('json')->success($services->adminAcceptanceList($this->request->getMore([
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function acceptanceDetail(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/acceptance/<id>', 'GET');
        return app('json')->success($services->adminAcceptanceDetail((int)$id, $this->adminInfo ?: []));
    }

    public function acceptanceReview(FranchiseOpeningServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/acceptance/<id>/review', 'POST');
        return app('json')->success($services->adminReviewAcceptance((int)$id, $this->request->postMore([
            ['action', 'pass'],
            ['reject_reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function identityGrant(FranchiseOpeningServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_opening/identity_grant', 'POST');
        return app('json')->success($services->adminGrantIdentity($this->request->postMore([
            [['application_id', 'd'], 0],
            ['role_code', 'store_manager'],
            ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
