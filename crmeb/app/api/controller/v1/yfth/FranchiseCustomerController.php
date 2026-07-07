<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\FranchiseCustomerServices;

class FranchiseCustomerController
{
    public function customerList(Request $request, FranchiseCustomerServices $services)
    {
        return app('json')->success($services->customerList($request, $request->getMore([
            ['keyword', ''],
            ['source', ''],
            ['customer_status', ''],
        ])));
    }

    public function detail(Request $request, FranchiseCustomerServices $services, $id)
    {
        return app('json')->success($services->customerDetail($request, (int)$id));
    }

    public function bind(Request $request, FranchiseCustomerServices $services)
    {
        return app('json')->success($services->bindCustomer($request, $request->postMore([
            [['uid', 'd'], 0],
            ['source', 'store_visit'],
            ['customer_status', 'potential'],
        ])));
    }

    public function follow(Request $request, FranchiseCustomerServices $services, $id)
    {
        return app('json')->success($services->addFollow($request, (int)$id, $request->postMore([
            ['follow_type', 'other'],
            ['content', ''],
            ['next_follow_time', 0],
        ])));
    }
}
