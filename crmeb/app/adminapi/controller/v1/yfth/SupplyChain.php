<?php

namespace app\adminapi\controller\v1\yfth;

use app\adminapi\controller\AuthController;
use app\services\system\admin\SystemRoleServices;
use app\services\yfth\SupplyChainServices;

class SupplyChain extends AuthController
{
    public function catalogList(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/catalog', 'GET');
        return app('json')->success($services->adminCatalogList($this->request->getMore([
            ['keyword', ''],
            ['status', ''],
        ]), $this->adminInfo ?: []));
    }

    public function catalogSave(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/catalog/save', 'POST');
        return app('json')->success($services->adminCatalogSave($this->request->postMore([
            [['id', 'd'], 0],
            [['product_id', 'd'], 0],
            ['status', 'active'],
            ['purchase_price', '0.00'],
            ['retail_reference_price', '0.00'],
            [['min_purchase_quantity', 'd'], 1],
            [['package_multiple', 'd'], 1],
            ['allow_store_types', ''],
            ['qualification_requirement', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function catalogDisable(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/catalog/disable', 'POST');
        return app('json')->success($services->adminCatalogDisable($this->request->postMore([
            [['id', 'd'], 0],
            ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function catalogImportVisible(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/catalog/import_visible', 'POST');
        return app('json')->success($services->adminImportVisibleProducts($this->request->postMore([
            ['product_ids', []],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function productSearch(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/product/search', 'GET');
        return app('json')->success($services->productSearch($this->request->getMore([
            ['keyword', ''],
        ]), $this->adminInfo ?: []));
    }

    public function purchaseOrderList(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/purchase_order', 'GET');
        return app('json')->success($services->adminPurchaseOrderList($this->request->getMore([
            ['status', ''],
            ['keyword', ''],
            [['store_id', 'd'], 0],
        ]), $this->adminInfo ?: []));
    }

    public function purchaseOrderDetail(SupplyChainServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/purchase_order/<id>', 'GET');
        return app('json')->success($services->adminPurchaseOrderDetail((int)$id, $this->adminInfo ?: []));
    }

    public function purchaseOrderAudit(SupplyChainServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/purchase_order/<id>/audit', 'POST');
        return app('json')->success($services->adminAuditPurchaseOrder((int)$id, $this->request->postMore([
            ['action', 'approve'],
            ['reason', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function purchaseOrderShip(SupplyChainServices $services, $id)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/purchase_order/<id>/ship', 'POST');
        return app('json')->success($services->adminShipPurchaseOrder((int)$id, $this->request->postMore([
            ['logistics_company', ''],
            ['logistics_no', ''],
        ]), (int)$this->adminId, $this->adminInfo ?: []));
    }

    public function shipmentList(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/shipment', 'GET');
        return app('json')->success($services->shipmentList($this->request->getMore([
            ['status', ''],
            [['purchase_order_id', 'd'], 0],
        ]), $this->adminInfo ?: []));
    }

    public function inventoryList(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/inventory', 'GET');
        return app('json')->success($services->adminInventoryList($this->request->getMore([
            [['store_id', 'd'], 0],
            ['sku_unique', ''],
        ]), $this->adminInfo ?: []));
    }

    public function ledgerList(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/ledger', 'GET');
        return app('json')->success($services->adminLedgerList($this->request->getMore([
            [['store_id', 'd'], 0],
            ['sku_unique', ''],
        ]), $this->adminInfo ?: []));
    }

    public function alertRuleList(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/alert_rule', 'GET');
        return app('json')->success($services->alertRuleList($this->request->getMore([
            [['store_id', 'd'], 0],
        ]), $this->adminInfo ?: []));
    }

    public function alertRuleSave(SupplyChainServices $services)
    {
        $this->assertAdminApiAuth('yfth/supply_chain/alert_rule/save', 'POST');
        return app('json')->success($services->alertRuleSave($this->request->postMore([
            [['store_id', 'd'], 0],
            ['sku_unique', ''],
            [['threshold_quantity', 'd'], 0],
            ['status', 'active'],
        ]), $this->adminInfo ?: []));
    }

    private function assertAdminApiAuth(string $rule, string $method): void
    {
        app()->make(SystemRoleServices::class)->assertApiAuthForAdmin($this->adminInfo ?: [], $rule, $method);
    }
}
