<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\FranchiseApplicationServices;

class FranchiseApplicationController
{
    public function submit(Request $request, FranchiseApplicationServices $services)
    {
        $data = $request->postMore([
            ['name', ''],
            ['phone', ''],
            ['city', ''],
            ['region', ''],
            ['intention_area', ''],
            ['budget', 0],
            ['remark', ''],
            ['partner_invite', ''],
        ]);
        foreach (['uid', 'applicant_uid', 'assigned_uid', 'status', 'store_id'] as $field) {
            if ($request->post($field, null) !== null) {
                $data['_forbidden_user_fields_submitted'] = true;
                break;
            }
        }
        return app('json')->success($services->submit($request, $data));
    }

    public function myList(Request $request, FranchiseApplicationServices $services)
    {
        return app('json')->success($services->myList($request, $request->getMore([
            ['status', ''],
        ])));
    }

    public function detail(Request $request, FranchiseApplicationServices $services, $id)
    {
        return app('json')->success($services->myDetail($request, (int)$id));
    }
}
