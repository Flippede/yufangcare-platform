<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\HqAuthorityStoreReadServices;

class HqAuthorityStoreReadController
{
    public function index(Request $request, HqAuthorityStoreReadServices $services)
    {
        return app('json')->success($services->index($request, $request->getMore([
            ['status', ''],
            ['keyword', ''],
            [['page', 'd'], 1],
            [['limit', 'd'], 20],
        ])));
    }

    public function detail(Request $request, HqAuthorityStoreReadServices $services, $id)
    {
        return app('json')->success($services->detail($request, (int)$id));
    }
}
