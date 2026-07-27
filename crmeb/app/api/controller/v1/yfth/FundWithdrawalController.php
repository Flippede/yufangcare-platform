<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\FundWithdrawalServices;

class FundWithdrawalController
{
    public function beneficiary(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->beneficiaryProfile($request));
    }

    public function saveBeneficiary(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->saveBeneficiaryProfile($request, $request->postMore([
            ['receiver_name', ''],
            ['receiver_account', ''],
            ['bank_name', ''],
        ])));
    }

    public function partnerSummary(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->partnerSummary($request));
    }

    public function partnerRequests(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->partnerRequests($request, $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function partnerCreate(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->createPartnerRequest($request, $this->requestData($request)));
    }

    public function storeSummary(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->storeSummary($request));
    }

    public function storeRequests(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->storeRequests($request, $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function storeCreate(Request $request, FundWithdrawalServices $services)
    {
        return app('json')->success($services->createStoreRequest($request, $this->requestData($request)));
    }

    private function requestData(Request $request): array
    {
        $data = $request->postMore([
            [['amount_cent', 'd'], 0],
            ['remark', ''],
            ['request_id', ''],
        ]);
        if (trim((string)$data['request_id']) === '') {
            $data['request_id'] = trim((string)$request->header('Idempotency-Key', ''));
        }
        return $data;
    }
}
