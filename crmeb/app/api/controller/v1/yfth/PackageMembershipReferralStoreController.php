<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\DirectReferralRewardServices;
use app\services\yfth\PackageMembershipReferralServices;
use app\services\yfth\PackageMembershipServices;

class PackageMembershipReferralStoreController
{
    public function members(Request $request, PackageMembershipReferralServices $access, PackageMembershipServices $services)
    {
        $context = $access->storeContext($request);
        return app('json')->success($services->storeList((int)$context['store_id'], $request->getMore([
            [['uid', 'd'], 0],
            ['status', ''],
        ])));
    }

    public function candidates(Request $request, PackageMembershipReferralServices $access, DirectReferralRewardServices $services)
    {
        $context = $access->storeContext($request);
        return app('json')->success($services->storeCandidates((int)$context['store_id'], [
            'referrer_uid' => (int)$request->get('referrer_uid', 0),
            'referred_uid' => (int)$request->get('referred_uid', 0),
            'candidate_type' => (string)$request->get('candidate_type', ''),
            'status' => (string)$request->get('status', ''),
        ]));
    }
}
