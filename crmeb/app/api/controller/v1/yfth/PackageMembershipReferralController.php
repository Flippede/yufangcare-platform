<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\DirectReferralRewardServices;
use app\services\yfth\PackageMembershipReferralServices;
use crmeb\exceptions\ApiException;

class PackageMembershipReferralController
{
    public function me(Request $request, PackageMembershipReferralServices $services)
    {
        return app('json')->success($services->me((int)$request->uid()));
    }

    public function bindStoreFromQr(Request $request, PackageMembershipReferralServices $services)
    {
        foreach (['uid', 'source_type', 'source_id', 'source_unique_key'] as $field) {
            if ($request->post($field, null) !== null) {
                throw new ApiException('store_qr_binding_authority_field_forbidden');
            }
        }
        $data = $request->postMore([
            [['store_id', 'd'], 0],
            ['idempotency_key', ''],
            ['request_id', ''],
        ]);
        $data['idempotency_key'] = (string)$data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->bindStoreFromQr(
            (int)$request->uid(),
            (int)$data['store_id'],
            $data
        ));
    }

    public function issueInvite(Request $request, PackageMembershipReferralServices $services)
    {
        return app('json')->success($services->issueInvite((int)$request->uid(), $request->postMore([
            ['request_id', ''],
        ])));
    }

    public function acceptInvite(Request $request, PackageMembershipReferralServices $services)
    {
        foreach (['uid', 'owner_uid', 'referrer_uid', 'store_id', 'source_unique_key'] as $field) {
            if ($request->post($field, null) !== null) {
                throw new ApiException('direct_referral_client_authority_field_forbidden');
            }
        }
        $data = $request->postMore([
            ['invite_token', ''],
            ['idempotency_key', ''],
            ['request_id', ''],
        ]);
        $data['idempotency_key'] = (string)$data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->acceptInvite((int)$request->uid(), (string)$data['invite_token'], $data));
    }

    public function candidates(Request $request, DirectReferralRewardServices $services)
    {
        return app('json')->success($services->userCandidates((int)$request->uid()));
    }

    public function referrals(Request $request, PackageMembershipReferralServices $services)
    {
        [$page, $limit] = $request->getMore([
            ['page', 1],
            ['limit', 20],
        ], true);
        return app('json')->success($services->directReferrals(
            (int)$request->uid(),
            (int)$page,
            (int)$limit
        ));
    }
}
