<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\StoreAcquisitionServices;

class StoreAcquisitionController
{
    public function issue(Request $request, StoreAcquisitionServices $services)
    {
        return app('json')->success($services->issue($request, $request->postMore([
            ['request_id', ''],
        ])));
    }

    public function current(Request $request, StoreAcquisitionServices $services)
    {
        return app('json')->success($services->current($request));
    }

    public function resolve(Request $request, StoreAcquisitionServices $services)
    {
        return app('json')->success($services->resolve((string)$request->get('acquisition_token', '')));
    }

    public function accept(Request $request, StoreAcquisitionServices $services)
    {
        return app('json')->success($services->accept((int)$request->uid(), $request->postMore([
            ['acquisition_token', ''],
            ['request_id', ''],
            ['idempotency_key', ''],
        ])));
    }
}
