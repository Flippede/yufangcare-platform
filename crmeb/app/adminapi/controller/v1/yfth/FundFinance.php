<?php

namespace app\adminapi\controller\v1\yfth;

use app\Request;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\AdminStoreContextServices;
use app\services\yfth\FundWithdrawalServices;

class FundFinance
{
    public function index(Request $request, FundWithdrawalServices $services)
    {
        $this->auth($request, 'yfth/fund_finance/withdrawal', 'GET');
        return app('json')->success($services->financeList($request->getMore([
            ['owner_type', ''], ['status', ''], ['rank_code', ''], ['keyword', ''],
            [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function detail(Request $request, FundWithdrawalServices $services, int $id)
    {
        $this->auth($request, 'yfth/fund_finance/withdrawal/' . $id, 'GET');
        return app('json')->success($services->financeDetail($id));
    }

    public function review(Request $request, FundWithdrawalServices $services, int $id)
    {
        $this->auth($request, 'yfth/fund_finance/withdrawal/' . $id . '/review', 'POST');
        $data = $request->postMore([['action', ''], ['reason', '']]);
        return app('json')->success($services->review(
            $id,
            (string)$data['action'],
            (string)$data['reason'],
            (int)$request->adminId()
        ));
    }

    public function paid(Request $request, FundWithdrawalServices $services, int $id)
    {
        $this->auth($request, 'yfth/fund_finance/withdrawal/' . $id . '/paid', 'POST');
        $data = $request->postMore([['pay_reference', ''], ['remark', '']]);
        return app('json')->success($services->confirmPaid(
            $id,
            (string)$data['pay_reference'],
            (string)$data['remark'],
            (int)$request->adminId()
        ));
    }

    private function auth(Request $request, string $path, string $method): void
    {
        $adminInfo = $request->adminInfo();
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($adminInfo, $path, $method);
    }
}
