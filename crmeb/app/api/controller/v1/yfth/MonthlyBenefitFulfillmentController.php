<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\MonthlyBenefitFulfillmentServices;

class MonthlyBenefitFulfillmentController
{
    public function current(Request $request, MonthlyBenefitFulfillmentServices $services)
    {
        $where = $request->getMore([
            [['package_instance_id', 'd'], 0],
        ]);
        $where = $this->withForbiddenParams($request, $where);
        return app('json')->success($services->current($request, $where));
    }

    public function history(Request $request, MonthlyBenefitFulfillmentServices $services)
    {
        $where = $request->getMore([
            ['status', ''],
        ]);
        $where = $this->withForbiddenParams($request, $where);
        return app('json')->success($services->history($request, $where));
    }

    public function detail(Request $request, MonthlyBenefitFulfillmentServices $services, $id)
    {
        return app('json')->success($services->detailForUser($request, (int)$id));
    }

    public function claim(Request $request, MonthlyBenefitFulfillmentServices $services)
    {
        $data = $request->postMore([
            [['benefit_item_id', 'd'], 0],
            ['fulfillment_method', 'express_delivery'],
            [['address_id', 'd'], 0],
            [['pickup_store_id', 'd'], 0],
            ['idempotency_key', ''],
            ['client_operation_key', ''],
        ]);
        $data = $this->withForbiddenParams($request, $data, ['uid', 'owner_uid', 'store_id', 'package_instance_id', 'benefit_plan_id', 'benefit_period_id', 'status', 'product_snapshot', 'quantity_used', 'active_key']);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->claim($request, $data));
    }

    public function cancel(Request $request, MonthlyBenefitFulfillmentServices $services, $id)
    {
        $data = $request->postMore([
            ['reason', ''],
            ['idempotency_key', ''],
            ['client_operation_key', ''],
        ]);
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->cancelByUser($request, (int)$id, $data));
    }

    private function withForbiddenParams(Request $request, array $data, array $fields = ['uid', 'owner_uid', 'store_id', 'operator_uid', 'idempotency_key', 'active_key']): array
    {
        $missing = '__yfth_missing__';
        foreach ($fields as $field) {
            if ($request->param($field, $missing) !== $missing) {
                $data[$field] = $request->param($field);
            }
        }
        return $data;
    }
}
