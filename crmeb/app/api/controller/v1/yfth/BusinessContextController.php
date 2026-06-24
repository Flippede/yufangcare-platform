<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\CurrentBusinessContextServices;
use app\services\yfth\StoreCapabilityServices;
use app\services\yfth\UserIdentityServices;
use crmeb\exceptions\ApiException;

class BusinessContextController
{
    public function identities(Request $request, UserIdentityServices $services)
    {
        return app('json')->success($services->listUserIdentities((int)$request->uid()));
    }

    public function context(Request $request, CurrentBusinessContextServices $services)
    {
        return app('json')->success($services->fromRequest($request));
    }

    public function capability($capability, Request $request, CurrentBusinessContextServices $contextServices, StoreCapabilityServices $capabilityServices)
    {
        $context = $contextServices->fromRequest($request);
        if (empty($context['store_id'])) {
            throw new ApiException('门店能力校验必须指定门店');
        }
        return app('json')->success([
            'store_id' => (int)$context['store_id'],
            'capability_code' => (string)$capability,
            'allowed' => $capabilityServices->isAvailable((int)$context['store_id'], (string)$capability),
        ]);
    }
}
