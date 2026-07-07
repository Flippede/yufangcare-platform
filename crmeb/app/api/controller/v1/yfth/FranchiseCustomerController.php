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
        $data = $request->postMore([
            ['source', ''],
            [['reference_id', 'd'], 0],
            ['customer_status', 'potential'],
        ]);
        if ($request->post('uid', null) !== null || $request->post('owner_uid', null) !== null || $request->post('store_id', null) !== null) {
            $data['_direct_customer_field_submitted'] = true;
        }
        return app('json')->success($services->bindCustomer($request, $data));
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
