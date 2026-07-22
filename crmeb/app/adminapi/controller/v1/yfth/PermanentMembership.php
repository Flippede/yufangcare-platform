<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\PermanentMembershipServices;

class PermanentMembership extends AuthController
{
    public function index(PermanentMembershipServices $services)
    {
        $this->auth('yfth/permanent_membership/enrollment', 'GET');
        return app('json')->success($services->adminList($this->request->getMore([[['store_id', 'd'], 0], [['target_uid', 'd'], 0], ['status', '']]), $this->adminInfo ?: []));
    }

    public function detail(PermanentMembershipServices $services, $id)
    {
        $this->auth('yfth/permanent_membership/enrollment', 'GET');
        return app('json')->success($services->adminDetail((int)$id, $this->adminInfo ?: []));
    }

    public function members(PermanentMembershipServices $services)
    {
        $this->auth('yfth/permanent_membership/member', 'GET');
        return app('json')->success($services->adminMembers($this->request->getMore([[['store_id', 'd'], 0], [['uid', 'd'], 0], ['status', '']]), $this->adminInfo ?: []));
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
