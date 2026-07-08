<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\FranchiseOpeningServices;
use crmeb\exceptions\ApiException;

class FranchiseOpeningController
{
    public function my(Request $request, FranchiseOpeningServices $services)
    {
        return app('json')->success($services->myOpening($request));
    }

    public function contractDetail(Request $request, FranchiseOpeningServices $services, $id)
    {
        return app('json')->success($services->userContractDetail($request, (int)$id));
    }

    public function contractConfirm(Request $request, FranchiseOpeningServices $services, $id)
    {
        $this->assertNoForbiddenUserFields($request);
        return app('json')->success($services->userConfirmContract($request, (int)$id));
    }

    public function paymentProof(Request $request, FranchiseOpeningServices $services, $id)
    {
        $this->assertNoForbiddenUserFields($request);
        $post = (array)$request->post();
        $data = $request->postMore([
            ['attachment_ids', ''],
            ['proof_attachment_id', ''],
            ['amount_snapshot', '0.00'],
        ]);
        foreach (['uid', 'applicant_uid', 'status', 'store_id', 'finance_uid'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $post[$field];
            }
        }
        return app('json')->success($services->userUploadPaymentProof($request, (int)$id, $data));
    }

    public function tasks(Request $request, FranchiseOpeningServices $services)
    {
        return app('json')->success($services->userTaskList($request));
    }

    public function taskSubmit(Request $request, FranchiseOpeningServices $services, $id)
    {
        $this->assertNoForbiddenUserFields($request);
        $post = (array)$request->post();
        $data = $request->postMore([
            ['content', ''],
            ['attachment_ids', ''],
            [['purchase_order_id', 'd'], 0],
        ]);
        foreach (['uid', 'applicant_uid', 'status', 'store_id', 'verified_uid'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $post[$field];
            }
        }
        return app('json')->success($services->userSubmitTask($request, (int)$id, $data));
    }

    public function acceptance(Request $request, FranchiseOpeningServices $services)
    {
        return app('json')->success($services->userAcceptance($request));
    }

    public function acceptanceSubmit(Request $request, FranchiseOpeningServices $services)
    {
        $this->assertNoForbiddenUserFields($request);
        return app('json')->success($services->userSubmitAcceptance($request, $request->postMore([
            ['reason', ''],
        ])));
    }

    private function assertNoForbiddenUserFields(Request $request): void
    {
        $post = (array)$request->post();
        foreach (['uid', 'applicant_uid', 'status', 'store_id', 'system_store_id', 'finance_uid', 'verified_uid', 'grant_uid'] as $field) {
            if (array_key_exists($field, $post)) {
                throw new ApiException('franchise_opening_user_field_forbidden');
            }
        }
    }
}
