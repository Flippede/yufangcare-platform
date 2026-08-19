<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\CustomerServiceServices;

class CustomerServiceController
{
    public function storeContact(Request $request, CustomerServiceServices $services)
    {
        return app('json')->success($services->storeContact($request));
    }

    public function workbench(Request $request, CustomerServiceServices $services)
    {
        return app('json')->success($services->workbench((int)$request->uid()));
    }
}
