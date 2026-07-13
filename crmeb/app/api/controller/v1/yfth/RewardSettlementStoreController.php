<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\DirectReferralRewardSettlementServices;
use app\services\yfth\PackageMembershipReferralServices;
use crmeb\exceptions\ApiException;

class RewardSettlementStoreController
{
    public function candidates(Request $request, PackageMembershipReferralServices $access, DirectReferralRewardSettlementServices $services)
    {
        $context = $access->storeContext($request);
        return app('json')->success($services->storeCandidates((int)$context['store_id'], $request->getMore([
            [['referrer_uid', 'd'], 0],
            [['referred_uid', 'd'], 0],
            ['candidate_type', ''],
            ['status', ''],
        ])));
    }

    public function confirm(Request $request, PackageMembershipReferralServices $access, DirectReferralRewardSettlementServices $services, $id)
    {
        $this->assertClientAuthorityFieldsAbsent($request);
        $context = $access->storeContext($request);
        return app('json')->success($services->confirmByStore((int)$id, $context, $request->postMore([
            ['request_id', ''],
            ['remark', ''],
        ])));
    }

    public function settle(Request $request, PackageMembershipReferralServices $access, DirectReferralRewardSettlementServices $services, $id)
    {
        $this->assertClientAuthorityFieldsAbsent($request);
        $context = $access->storeContext($request);
        return app('json')->success($services->settleByStore((int)$id, $context, $request->postMore([
            ['request_id', ''],
            ['offline_ref_no', ''],
            ['proof_ref', ''],
            ['remark', ''],
        ])));
    }

    private function assertClientAuthorityFieldsAbsent(Request $request): void
    {
        foreach (['store_id', 'uid', 'operator_uid', 'referrer_uid', 'referred_uid', 'reward_amount_cent', 'status'] as $field) {
            if ($request->post($field, null) !== null) {
                throw new ApiException('reward_candidate_client_authority_field_forbidden');
            }
        }
    }
}
