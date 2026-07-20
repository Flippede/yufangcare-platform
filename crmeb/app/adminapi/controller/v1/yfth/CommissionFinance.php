<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\AdminStoreContextServices;
use app\services\yfth\AutomaticCommissionServices;
use app\services\yfth\CommissionFinanceServices;

class CommissionFinance extends AuthController
{
    public function ruleList(AutomaticCommissionServices $services)
    {
        $this->auth('yfth/commission/rule', 'GET');
        return app('json')->success($services->ruleList($this->request->getMore([
            ['status', ''], ['scope_type', ''], ['enabled', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function ruleSave(AutomaticCommissionServices $services)
    {
        $this->auth('yfth/commission/rule', 'POST');
        return app('json')->success($services->saveRule($this->request->postMore([
            ['scope_type', 'all'], [['scope_id', 'd'], 0], [['c1_ratio_bps', 'd'], 0],
            [['b1_ratio_bps', 'd'], 0], [['observation_days', 'd'], 0], [['enabled', 'd'], 1],
            [['effective_at', 'd'], 0], [['expires_at', 'd'], 0], ['note', ''],
        ]), (int)$this->adminId));
    }

    public function rulePublish(AutomaticCommissionServices $services, $id)
    {
        $this->auth('yfth/commission/rule', 'POST');
        return app('json')->success($services->publishRule((int)$id, (int)$this->adminId));
    }

    public function accounts(CommissionFinanceServices $services)
    {
        $this->auth('yfth/commission/account', 'GET');
        $data = $this->request->getMore([[['uid', 'd'], 0], [['store_id', 'd'], 0]]);
        if ((int)$data['uid'] > 0) return app('json')->success($services->userSummary((int)$data['uid']));
        if ((int)$data['store_id'] > 0) {
            return app('json')->success($services->storeSummary([
                'uid' => 0, 'role_code' => 'franchisee', 'store_id' => (int)$data['store_id'],
            ]));
        }
        return app('json')->success(['user_or_store_required' => true]);
    }

    public function accruals(AutomaticCommissionServices $services)
    {
        $this->auth('yfth/commission/accrual', 'GET');
        return app('json')->success($services->accrualList($this->request->getMore([
            ['status', ''], ['source_type', ''], [['store_id', 'd'], 0], [['c1_uid', 'd'], 0],
            [['order_id', 'd'], 0], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function ledger(CommissionFinanceServices $services)
    {
        $this->auth('yfth/commission/ledger', 'GET');
        return app('json')->success($services->headquartersLedger($this->request->getMore([
            ['account_type', ''], [['account_id', 'd'], 0], ['bucket', ''], ['source_type', ''],
            [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function legacyReport(AutomaticCommissionServices $services)
    {
        $this->auth('yfth/commission/accrual', 'GET');
        return app('json')->success($services->legacyCompatibilityReport());
    }

    public function adjustment(CommissionFinanceServices $services)
    {
        $this->auth('yfth/commission/adjustment', 'POST');
        $data = $this->request->postMore([
            ['account_type', 'user'], [['account_id', 'd'], 0], ['bucket', 'c1_commission'],
            [['delta_cent', 'd'], 0], ['reason', ''], ['request_id', ''],
        ]);
        if ((string)$data['account_type'] === 'store') {
            return app('json')->success($services->adjustStore((int)$data['account_id'], (string)$data['bucket'],
                (int)$data['delta_cent'], (int)$this->adminId, (string)$data['reason'], (string)$data['request_id']));
        }
        return app('json')->success($services->adjustUser((int)$data['account_id'], (int)$data['delta_cent'],
            (int)$this->adminId, (string)$data['reason'], (string)$data['request_id']));
    }

    public function withdrawals(CommissionFinanceServices $services)
    {
        $this->auth('yfth/commission/withdrawal', 'GET');
        return app('json')->success($services->headquartersWithdrawals($this->request->getMore([
            ['status', ''], [['store_id', 'd'], 0], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function completeWithdrawal(CommissionFinanceServices $services, $id)
    {
        $this->auth('yfth/commission/withdrawal/<id>/complete', 'POST');
        return app('json')->success($services->completeStoreWithdrawal(
            (int)$id, (int)$this->adminId, (string)$this->request->post('remark', '')
        ));
    }

    public function retry(AutomaticCommissionServices $services)
    {
        $this->auth('yfth/commission/retry', 'POST');
        return app('json')->success($services->processDue((int)$this->request->post('limit', 100)));
    }

    private function auth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($this->adminInfo ?: []);
    }
}
