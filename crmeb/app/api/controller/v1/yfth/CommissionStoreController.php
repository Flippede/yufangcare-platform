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

    public function c1Withdrawals(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->storeUserWithdrawals($this->context($request), $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function completeC1Withdrawal(Request $request, CommissionFinanceServices $services, $id)
    {
        $data = $request->postMore([
            ['offline_ref_no', ''], ['proof_ref', ''], ['remark', ''], ['request_id', ''],
        ]);
        $data['request_id'] = $data['request_id'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->completeUserWithdrawal($this->context($request), (int)$id, $data));
    }

    public function settlementAccount(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->storeSummary($this->context($request))['settlement_account']);
    }

    public function saveSettlementAccount(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->saveSettlementAccount($this->context($request), $request->postMore([
            ['account_type', 'personal'], ['account_name', ''], ['account_no', ''], ['bank_name', ''],
            ['bank_branch', ''], ['reserved_phone', ''], ['contact_name', ''], ['contact_phone', ''],
        ])));
    }

    public function withdrawals(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->storeWithdrawals($this->context($request), $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function withdraw(Request $request, CommissionFinanceServices $services)
    {
        $data = $request->postMore([[['amount_cent', 'd'], 0], ['request_id', '']]);
        $data['request_id'] = $data['request_id'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->requestStoreWithdrawal(
            $this->context($request), (int)$data['amount_cent'], (string)$data['request_id']
        ));
    }
}
