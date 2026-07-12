<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\HqAuthorityStoreReadServices;
use app\services\yfth\HqAuthorityReadParameterServices;

class HqAuthorityStoreReadController
{
    public function index(Request $request, HqAuthorityStoreReadServices $services, HqAuthorityReadParameterServices $parameters)
    {
        return app('json')->success($services->index($request, $parameters->storeFilters($request->get())));
    }

    public function detail(Request $request, HqAuthorityStoreReadServices $services, HqAuthorityReadParameterServices $parameters, $id)
    {
        $parameters->storeFilters($request->get());
        return app('json')->success($services->detail($request, $parameters->positiveId($id, 'attribution_id')));
    }
}
