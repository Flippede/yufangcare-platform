<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\MemberPointsServices;
use crmeb\exceptions\ApiException;

class CommissionAccountController
{
    public function summary(Request $request, MemberPointsServices $services)
    {
        return app('json')->success($services->summary((int)$request->uid()));
    }

    public function ledger(Request $request, MemberPointsServices $services)
    {
        return app('json')->success($services->ledger((int)$request->uid(), $request->getMore([
            ['source_type', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function settlements(Request $request)
    {
        return app('json')->success(['list' => [], 'count' => 0, 'retired' => true, 'notice' => 'C端现金结算已停用，奖励统一发放为积分。']);
    }

    public function settle(Request $request)
    {
        throw new ApiException('c1_cash_settlement_retired_use_points');
    }
}
