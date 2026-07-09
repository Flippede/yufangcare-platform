<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\ProductQuotaServices;

class ProductQuota extends AuthController
{
    public function accountList(ProductQuotaServices $services)
    {
        $this->assertAdminApiAuth('yfth/product_quota/account', 'GET');
        return app('json')->success($services->adminAccountList($this->request->getMore([
            [['store_id', 'd'], 0],
            ['quota_type', ''],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function accountDetail(ProductQuotaServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/product_quota/account', 'GET');
        return app('json')->success($services->adminAccountDetail((int)$id, $this->adminInfo ?: []));
    }

    public function ledgerList(ProductQuotaServices $services)
    {
        $this->assertAdminApiAuth('yfth/product_quota/ledger', 'GET');
        return app('json')->success($services->adminLedgerList($this->request->getMore([
            [['store_id', 'd'], 0],
            [['account_id', 'd'], 0],
            ['quota_type', ''],
            ['direction', ''],
            ['action_type', ''],
            ['source_type', ''],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function grantList(ProductQuotaServices $services)
    {
        $this->assertAdminApiAuth('yfth/product_quota/grant', 'GET');
        return app('json')->success($services->adminGrantList($this->request->getMore([
            [['store_id', 'd'], 0],
            [['account_id', 'd'], 0],
            ['quota_type', ''],
            ['status', ''],
            ['source_type', ''],
        ]), $this->adminInfo ?: []));
    }

    public function grantCreate(ProductQuotaServices $services)
    {
        $this->assertAdminApiAuth('yfth/product_quota/grant', 'POST');
        return app('json')->success($services->adminCreateGrant($this->request->postMore([
            [['store_id', 'd'], 0],
            ['quota_type', 'return_goods'],
            ['amount_cent', 0],
            ['source_type', 'headquarters_manual_grant'],
            [['source_id', 'd'], 0],
            ['reason', ''],
            ['idempotency_key', ''],
            ['client_operation_key', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function grantConfirm(ProductQuotaServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/product_quota/grant/<id>/confirm', 'POST');
        return app('json')->success($services->adminConfirmGrant((int)$id, (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function grantReject(ProductQuotaServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/product_quota/grant/<id>/reject', 'POST');
        return app('json')->success($services->adminRejectGrant((int)$id, $this->request->postMore([
            ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function grantReverse(ProductQuotaServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/product_quota/grant/<id>/reverse', 'POST');
        return app('json')->success($services->adminReverseGrant((int)$id, $this->request->postMore([
            ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function adjustmentCreate(ProductQuotaServices $services)
    {
        $this->assertAdminApiAuth('yfth/product_quota/adjustment', 'POST');
        return app('json')->success($services->adminCreateAdjustment($this->request->postMore([
            [['account_id', 'd'], 0],
            ['action_type', ''],
            ['amount_cent', 0],
            ['reason', ''],
            ['dedupe_key', ''],
            ['client_operation_key', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function accountFreeze(ProductQuotaServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/product_quota/account/<id>/freeze', 'POST');
        return app('json')->success($services->adminFreezeAccount((int)$id, $this->request->postMore([
            ['reason', ''],
            ['dedupe_key', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function accountUnfreeze(ProductQuotaServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/product_quota/account/<id>/unfreeze', 'POST');
        return app('json')->success($services->adminUnfreezeAccount((int)$id, $this->request->postMore([
            ['reason', ''],
            ['dedupe_key', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function accountClose(ProductQuotaServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/product_quota/account/<id>/close', 'POST');
        return app('json')->success($services->adminCloseAccount((int)$id, $this->request->postMore([
            ['reason', ''],
            ['dedupe_key', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
