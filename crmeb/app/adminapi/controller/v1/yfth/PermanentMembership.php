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
        return app('json')->success($services->adminMembers($this->request->getMore([[['store_id', 'd'], 0], [['uid', 'd'], 0]]), $this->adminInfo ?: []));
    }

    public function create(PermanentMembershipServices $services)
    {
        $this->auth('yfth/permanent_membership/enrollment', 'POST');
        $data = $this->writePayload([[['store_id', 'd'], 0]]);
        return app('json')->success($services->createForAdmin((int)$data['store_id'], (int)$this->adminId, $this->adminInfo ?: [], $data));
    }

    public function bind(PermanentMembershipServices $services, $id)
    {
        $this->auth('yfth/permanent_membership/enrollment/<id>/bind', 'POST');
        $data = $this->writePayload([['identity_token', '']]);
        return app('json')->success($services->bindForAdmin((int)$id, (string)$data['identity_token'], (int)$this->adminId, $this->adminInfo ?: [], $data));
    }

    public function payment(PermanentMembershipServices $services, $id)
    {
        $this->auth('yfth/permanent_membership/enrollment/<id>/payment', 'POST');
        return app('json')->success($services->confirmPaymentForAdmin((int)$id, (int)$this->adminId, $this->adminInfo ?: [], $this->writePayload()));
    }

    public function confirmationCode(PermanentMembershipServices $services, $id)
    {
        $this->auth('yfth/permanent_membership/enrollment/<id>/confirmation_code', 'POST');
        return app('json')->success($services->confirmationCodeForAdmin((int)$id, (int)$this->adminId, $this->adminInfo ?: []));
    }

    private function writePayload(array $extra = []): array
    {
        $data = $this->request->postMore(array_merge($extra, [['idempotency_key', ''], ['client_operation_key', '']]));
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$this->request->header('Idempotency-Key', '');
        return $data;
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
