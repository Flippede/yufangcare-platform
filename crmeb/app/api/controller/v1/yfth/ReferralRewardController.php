<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\ReferralRewardServices;

class ReferralRewardController
{
    public function createCode(Request $request, ReferralRewardServices $services)
    {
        $post = (array)$request->post();
        $data = $request->postMore([
            ['scene', 'package_5980'],
            ['expire_time', 0],
        ]);
        foreach (['owner_uid', 'owner_role_code', 'store_id', 'store_ids', 'referrer_uid', 'referrer_store_id', 'amount_cent', 'status'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $post[$field];
            }
        }
        return app('json')->success($services->userCreateCode($request, $data));
    }

    public function code(Request $request, ReferralRewardServices $services)
    {
        return app('json')->success($services->userCodeList($request, $request->getMore([
            ['scene', 'package_5980'],
        ])));
    }

    public function bind(Request $request, ReferralRewardServices $services)
    {
        $post = (array)$request->post();
        $data = $request->postMore([
            ['scene', 'package_5980'],
            ['code', ''],
        ]);
        foreach (['owner_uid', 'owner_role_code', 'store_id', 'store_ids', 'referrer_uid', 'referrer_store_id', 'amount_cent', 'status'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $post[$field];
            }
        }
        return app('json')->success($services->userBindCandidate($request, $data));
    }

    public function candidates(Request $request, ReferralRewardServices $services)
    {
        return app('json')->success($services->userCandidateList($request, $request->getMore([
            ['scene', ''],
        ])));
    }

    public function ledger(Request $request, ReferralRewardServices $services)
    {
        return app('json')->success($services->userLedgerList($request, $request->getMore([
            ['scene', ''],
        ])));
    }

    public function ledgerDetail(Request $request, ReferralRewardServices $services, $id)
    {
        return app('json')->success($services->userLedgerDetail($request, (int)$id));
    }
}
