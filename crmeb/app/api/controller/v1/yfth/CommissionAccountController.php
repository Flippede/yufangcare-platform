<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\CommissionFinanceServices;

class CommissionAccountController
{
    public function summary(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->userSummary((int)$request->uid()));
    }

    public function ledger(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->userLedger((int)$request->uid(), $request->getMore([
            ['bucket', ''], ['source_type', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function settlements(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->userSettlements((int)$request->uid(), $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function settle(Request $request, CommissionFinanceServices $services)
    {
        $data = $request->postMore([[['amount_cent', 'd'], 0], ['request_id', '']]);
        $data['request_id'] = $data['request_id'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->requestUserSettlement(
            (int)$request->uid(), (int)$data['amount_cent'], (string)$data['request_id']
        ));
    }
}
