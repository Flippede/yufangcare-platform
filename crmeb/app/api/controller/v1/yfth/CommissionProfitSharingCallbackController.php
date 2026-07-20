<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\CommissionFinanceServices;

/** Trusted-provider callback endpoint. It deliberately has no user/admin token. */
class CommissionProfitSharingCallbackController
{
    public function receive(Request $request, CommissionFinanceServices $services)
    {
        return app('json')->success($services->handleTrustedSettlementCallback(
            (array)$request->header(), (string)$request->getContent()
        ));
    }
}
