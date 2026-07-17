<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\HqAcceptanceFixtureServices;
use app\services\yfth\HqUserDebugPurgeServices;
use app\services\yfth\HqUserRoleManagementServices;
use app\services\yfth\FranchisePartnerServices;

class HqUserRole extends AuthController
{
    public function users(HqUserRoleManagementServices $services)
    {
        $this->auth('yfth/user_role/user', 'GET');
        return app('json')->success($services->users($this->request->getMore([
            ['keyword', ''],
            [['page', 'd'], 1],
            [['limit', 'd'], 20],
        ]), $this->adminInfo ?: []));
    }

    public function detail(HqUserRoleManagementServices $services, $uid)
    {
        $this->auth('yfth/user_role/user/<uid>', 'GET');
        return app('json')->success($services->detail((int)$uid, $this->adminInfo ?: []));
    }

    public function grant(HqUserRoleManagementServices $services, $uid)
    {
        $this->auth('yfth/user_role/user/<uid>/grant', 'POST');
        return app('json')->success($services->grant((int)$uid, $this->request->postMore([
            [['store_id', 'd'], 0],
            ['role_code', ''],
            ['reason', ''],
            ['request_id', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function grantMembership(HqUserRoleManagementServices $services, $uid)
    {
        $this->auth('yfth/user_role/user/<uid>/membership/grant', 'POST');
        return app('json')->success($services->grantMembership((int)$uid, $this->request->postMore([
            [['store_id', 'd'], 0],
            ['reason', ''],
            ['request_id', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function partnerGrantOptions(FranchisePartnerServices $services)
    {
        $this->auth('yfth/user_role/partner/grant_options', 'GET');
        $data = $this->request->getMore([
            ['rank_code', ''],
            ['keyword', ''],
        ]);
        return app('json')->success($services->adminGrantOptions(
            (string)$data['rank_code'],
            (string)$data['keyword'],
            $this->adminInfo ?: []
        ));
    }

    public function grantPartner(FranchisePartnerServices $services, $uid)
    {
        $this->auth('yfth/user_role/user/<uid>/partner/grant', 'POST');
        return app('json')->success($services->adminGrantPartner((int)$uid, $this->request->postMore([
            ['rank_code', ''],
            [['parent_uid', 'd'], 0],
            ['reason', ''],
            ['request_id', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function revoke(HqUserRoleManagementServices $services, $id)
    {
        $this->auth('yfth/user_role/role/<id>/revoke', 'POST');
        return app('json')->success($services->revoke((int)$id, $this->request->postMore([
            ['reason', ''],
            ['request_id', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function fixture(HqAcceptanceFixtureServices $services)
    {
        $this->auth('yfth/user_role/fixture', 'GET');
        return app('json')->success($services->summary($this->adminInfo ?: []));
    }

    public function generateFixture(HqAcceptanceFixtureServices $services)
    {
        $this->auth('yfth/user_role/fixture/generate', 'POST');
        return app('json')->success($services->generate($this->fixturePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function resetFixture(HqAcceptanceFixtureServices $services)
    {
        $this->auth('yfth/user_role/fixture/reset', 'POST');
        return app('json')->success($services->reset($this->fixturePayload(), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function resetFixturePasswords(HqAcceptanceFixtureServices $services)
    {
        $this->auth('yfth/user_role/fixture/password/reset', 'POST');
        return app('json')->success($services->resetPasswords(
            $this->fixturePayload(),
            (int)$this->adminId,
            $this->adminInfo ?: []
        ));
    }

    public function purgePreflight(HqUserDebugPurgeServices $services, $uid)
    {
        $this->auth('yfth/user_role/user/<uid>/purge/preflight', 'GET');
        return app('json')->success($services->preflight((int)$uid, $this->adminInfo ?: []));
    }

    public function purge(HqUserDebugPurgeServices $services, $uid)
    {
        $this->auth('yfth/user_role/user/<uid>/purge', 'DELETE');
        return app('json')->success($services->purge((int)$uid, $this->request->postMore([
            ['confirmation', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }

    private function fixturePayload(): array
    {
        return $this->request->postMore([
            ['reason', ''],
            ['request_id', ''],
        ]);
    }
}
