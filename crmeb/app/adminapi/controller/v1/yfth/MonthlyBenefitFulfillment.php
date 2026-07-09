<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\MonthlyBenefitFulfillmentServices;

class MonthlyBenefitFulfillment extends AuthController
{
    public function index(MonthlyBenefitFulfillmentServices $services)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([
            ['fulfillment_no', ''],
            [['uid', 'd'], 0],
            [['store_id', 'd'], 0],
            [['pickup_store_id', 'd'], 0],
            [['benefit_item_id', 'd'], 0],
            ['status', ''],
            ['fulfillment_method', ''],
        ]), $this->adminInfo ?: []));
    }

    public function detail(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment', 'GET');
        return app('json')->success($services->adminDetail((int)$id, $this->adminInfo ?: []));
    }

    public function confirm(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment/<id>/confirm', 'POST');
        return app('json')->success($services->adminConfirm((int)$id, $this->writePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function reject(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment/<id>/reject', 'POST');
        return app('json')->success($services->adminReject((int)$id, $this->writePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function prepare(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment/<id>/prepare', 'POST');
        return app('json')->success($services->adminPrepare((int)$id, $this->writePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function ship(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment/<id>/ship', 'POST');
        $data = $this->request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
            ['client_operation_key', ''],
            ['delivery_company', ''],
            ['delivery_no', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return app('json')->success($services->adminShip((int)$id, $data, (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function complete(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment/<id>/complete', 'POST');
        return app('json')->success($services->adminComplete((int)$id, $this->writePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function exception(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment/<id>/exception', 'POST');
        return app('json')->success($services->adminException((int)$id, $this->writePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function cancel(MonthlyBenefitFulfillmentServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/monthly_benefit/fulfillment/<id>/cancel', 'POST');
        return app('json')->success($services->adminCancel((int)$id, $this->writePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    private function writePayload(): array
    {
        $data = $this->request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
            ['client_operation_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return $data;
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
