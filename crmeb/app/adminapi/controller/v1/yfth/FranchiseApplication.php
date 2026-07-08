<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\FranchiseApplicationServices;

class FranchiseApplication extends AuthController
{
    public function applicationList(FranchiseApplicationServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_application/application', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([
            ['keyword', ''],
            ['status', ''],
            [['assigned_uid', 'd'], 0],
            [['applicant_uid', 'd'], 0],
            ['city', ''],
        ]), $this->adminInfo ?: []));
    }

    public function applicationDetail(FranchiseApplicationServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_application/application/<id>', 'GET');
        return app('json')->success($services->adminDetail((int)$id, $this->adminInfo ?: []));
    }

    public function assign(FranchiseApplicationServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_application/application/<id>/assign', 'POST');
        $data = $this->request->postMore([
            [['assigned_uid', 'd'], 0],
        ]);
        return app('json')->success($services->assignOwner((int)$id, (int)$data['assigned_uid'], (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function status(FranchiseApplicationServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_application/application/<id>/status', 'POST');
        $data = $this->request->postMore([
            ['status', ''],
            ['reason', ''],
        ]);
        return app('json')->success($services->changeStatus((int)$id, (string)$data['status'], (string)$data['reason'], (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function follow(FranchiseApplicationServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/franchise_application/application/<id>/follow', 'POST');
        return app('json')->success($services->addFollow((int)$id, $this->request->postMore([
            ['type', 'phone'],
            ['content', ''],
            ['next_time', 0],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function statusOptions(FranchiseApplicationServices $services)
    {
        $this->assertAdminApiAuth('yfth/franchise_application/application', 'GET');
        return app('json')->success($services->statuses());
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        /** @var SystemRoleServices $roleServices */
        $roleServices = app()->make(SystemRoleServices::class);
        $roleServices->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
