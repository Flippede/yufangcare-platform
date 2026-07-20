<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\CommissionFinanceServices;
use app\services\yfth\CurrentBusinessContextServices;

class CommissionStoreController
{
    private function context(Request $request): array
    {
        return app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
    }

    public function summary(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->storeSummary($this->context($request)));
    }

    public function ledger(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->storeLedger($this->context($request), $request->getMore([
            ['bucket', ''], ['source_type', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function c1Settlements(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->storeUserSettlements($this->context($request), $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function completeC1Settlement(Request $request, CommissionFinanceServices $services, $id)
    {
        $data = $request->postMore([
            ['offline_ref_no', ''], ['proof_ref', ''], ['remark', ''], ['request_id', ''],
        ]);
        $data['request_id'] = $data['request_id'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->completeUserSettlement($this->context($request), (int)$id, $data));
    }

    public function settlementBatches(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->storeSettlementBatches($this->context($request), $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }
}
