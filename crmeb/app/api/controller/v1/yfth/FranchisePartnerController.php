<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\FranchisePartnerServices;

class FranchisePartnerController
{
    public function workbench(Request $request, FranchisePartnerServices $services)
    {
        return app('json')->success($services->myWorkbench($request));
    }

    public function createInvite(Request $request, FranchisePartnerServices $services)
    {
        return app('json')->success($services->createInvite($request));
    }

    public function team(Request $request, FranchisePartnerServices $services)
    {
        return app('json')->success($services->myTeam($request));
    }

    public function rewards(Request $request, FranchisePartnerServices $services)
    {
        return app('json')->success($services->myRewards($request, $request->getMore([
            ['status', ''], [['page', 'd'], 1], [['limit', 'd'], 20],
        ])));
    }

    public function promotionApply(Request $request, FranchisePartnerServices $services)
    {
        return app('json')->success($services->applyPromotion($request, $request->postMore([['reason', '']])));
    }
}
