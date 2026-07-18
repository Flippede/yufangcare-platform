<?php

namespace app\api\controller\v1\yfth;

use app\Request;
use app\services\yfth\SupplyChainServices;

class SupplyChainController
{
    public function catalog(Request $request, SupplyChainServices $services)
    {
        return app('json')->success($services->storeCatalogList($request, $request->getMore([
            ['keyword', ''],
        ])));
    }

    public function createOrder(Request $request, SupplyChainServices $services)
    {
        $post = (array)$request->post();
        $data = $request->postMore([
            ['items', []],
            [['supplier_subject_id', 'd'], 0],
            [['quota_amount_cent', 'd'], 0],
            ['idempotency_key', ''],
        ]);
        foreach (['store_id', 'store_ids', 'role_code', 'operator_uid', 'operator_role_code'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $post[$field];
            }
        }
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->createPurchaseOrder($request, $data));
    }

    public function orderList(Request $request, SupplyChainServices $services)
    {
        return app('json')->success($services->storePurchaseOrderList($request, $request->getMore([
            ['status', ''],
            ['keyword', ''],
        ])));
    }

    public function orderDetail(Request $request, SupplyChainServices $services, $id)
    {
        return app('json')->success($services->storePurchaseOrderDetail($request, (int)$id));
    }

    public function inTransit(Request $request, SupplyChainServices $services)
    {
        return app('json')->success($services->storeInTransitList($request, $request->getMore([
            ['keyword', ''],
        ])));
    }

    public function receive(Request $request, SupplyChainServices $services, $id)
    {
        $post = (array)$request->post();
        $data = $request->postMore([
            ['idempotency_key', ''],
        ]);
        foreach (['store_id', 'store_ids', 'role_code', 'operator_uid', 'operator_role_code'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $post[$field];
            }
        }
        $data['idempotency_key'] = $data['idempotency_key'] ?: (string)$request->header('Idempotency-Key', '');
        return app('json')->success($services->confirmReceipt($request, (int)$id, $data));
    }

    public function inventory(Request $request, SupplyChainServices $services)
    {
        return app('json')->success($services->storeInventoryList($request, $request->getMore([
            ['sku_unique', ''],
        ])));
    }

    public function ledger(Request $request, SupplyChainServices $services)
    {
        return app('json')->success($services->storeLedgerList($request, $request->getMore([
            ['sku_unique', ''],
        ])));
    }
}
