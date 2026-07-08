<?php

namespace app\services\yfth;

use app\Request;
use app\dao\yfth\YfthInventoryAlertRuleDao;
use app\dao\yfth\YfthInventoryBalanceDao;
use app\dao\yfth\YfthInventoryLedgerDao;
use app\dao\yfth\YfthPurchaseOrderDao;
use app\dao\yfth\YfthPurchaseOrderItemDao;
use app\dao\yfth\YfthPurchaseReceiptDao;
use app\dao\yfth\YfthPurchaseShipmentDao;
use app\dao\yfth\YfthStockLocationDao;
use app\dao\yfth\YfthSupplyCatalogDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class SupplyChainServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_supply_chain';
    private const STORE_READ_ROLES = ['franchisee', 'store_manager', 'store_staff'];
    private const STORE_WRITE_ROLES = ['franchisee', 'store_manager'];
    private const CATALOG_STATUSES = ['active', 'disabled'];
    private const ORDER_STATUSES = ['submitted', 'approved', 'rejected', 'shipped', 'stocked', 'cancelled'];

    public function __construct(YfthSupplyCatalogDao $dao)
    {
        $this->dao = $dao;
    }

    public function adminCatalogList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $status = $this->normalizeStatus((string)($where['status'] ?? ''), self::CATALOG_STATUSES, '');
        $keyword = trim((string)($where['keyword'] ?? ''));

        $buildQuery = function () use ($status, $keyword) {
            $query = $this->dao->search([]);
            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($keyword !== '') {
                if (ctype_digit($keyword)) {
                    $query->where('product_id', (int)$keyword);
                } else {
                    $ids = Db::name('store_product')
                        ->where('is_del', 0)
                        ->whereLike('store_name|keyword|store_info', '%' . $keyword . '%')
                        ->column('id');
                    $query->whereIn('product_id', $ids ?: [0]);
                }
            }
            return $query;
        };

        $count = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->page($page, $limit)
            ->order('id desc')
            ->select()
            ->toArray();
        return [
            'list' => $this->formatCatalogRows($rows, true),
            'count' => $count,
        ];
    }

    public function adminCatalogSave(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $payload = $this->normalizeCatalogPayload($data, $adminId);
        $id = (int)($data['id'] ?? 0);
        $before = $id > 0 ? $this->rowArray($this->dao->get($id)) : [];

        return Db::transaction(function () use ($id, $payload, $before, $adminId) {
            if ($id > 0) {
                if (!$before) {
                    throw new ApiException('supply_catalog_not_found');
                }
                $this->dao->update($id, $payload);
                $row = array_merge($before, $payload);
                $this->audit('supply_catalog', $id, 'update', $before, $row, $adminId, 'headquarter_admin', 0, '');
            } else {
                if ($this->dao->getOne(['product_id' => (int)$payload['product_id']])) {
                    throw new ApiException('supply_catalog_product_exists');
                }
                $created = $this->dao->save($payload);
                $row = $this->rowArray($created);
                $this->audit('supply_catalog', (int)$row['id'], 'create', [], $row, $adminId, 'headquarter_admin', 0, '');
            }
            return ['catalog' => $this->formatCatalog($row, true)];
        });
    }

    public function adminCatalogDisable(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            throw new ApiException('supply_catalog_id_required');
        }
        $before = $this->rowArray($this->dao->get($id));
        if (!$before) {
            throw new ApiException('supply_catalog_not_found');
        }
        $after = array_merge($before, [
            'status' => 'disabled',
            'updated_uid' => $adminId,
            'update_time' => time(),
        ]);
        $this->dao->update($id, [
            'status' => 'disabled',
            'updated_uid' => $adminId,
            'update_time' => $after['update_time'],
        ]);
        $this->audit('supply_catalog', $id, 'disable', $before, $after, $adminId, 'headquarter_admin', 0, (string)($data['reason'] ?? ''));
        return ['catalog' => $this->formatCatalog($after, true)];
    }

    public function productSearch(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $keyword = trim((string)($where['keyword'] ?? ''));
        $query = Db::name('store_product')->where('is_del', 0);
        if ($keyword !== '') {
            if (ctype_digit($keyword)) {
                $query->where('id', (int)$keyword);
            } else {
                $query->whereLike('store_name|keyword|store_info', '%' . $keyword . '%');
            }
        }
        $count = (int)(clone $query)->count();
        $rows = $query
            ->field('id,store_name,image,price,ot_price,stock,sales,is_show')
            ->page($page, $limit)
            ->order('id desc')
            ->select()
            ->toArray();
        $skuMap = $this->skuMap(array_column($rows, 'id'));
        return [
            'list' => array_map(function ($row) use ($skuMap) {
                $row['id'] = (int)$row['id'];
                $row['skus'] = $skuMap[(int)$row['id']] ?? [];
                return $row;
            }, $rows),
            'count' => $count,
        ];
    }

    public function storeCatalogList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request, false);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $keyword = trim((string)($where['keyword'] ?? ''));
        $storeType = (string)($scope['context']['store_type'] ?? '');

        $buildQuery = function () use ($keyword, $storeType) {
            $query = $this->dao->search([])->where('status', 'active');
            if ($keyword !== '') {
                if (ctype_digit($keyword)) {
                    $query->where('product_id', (int)$keyword);
                } else {
                    $ids = Db::name('store_product')
                        ->where('is_del', 0)
                        ->whereLike('store_name|keyword|store_info', '%' . $keyword . '%')
                        ->column('id');
                    $query->whereIn('product_id', $ids ?: [0]);
                }
            }
            if ($storeType !== '') {
                $query->where(function ($query) use ($storeType) {
                    $query->where('allow_store_types', '')
                        ->whereOr('allow_store_types', 'like', '%' . $storeType . '%');
                });
            }
            return $query;
        };

        $count = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->page($page, $limit)
            ->order('id desc')
            ->select()
            ->toArray();
        return [
            'list' => $this->formatCatalogRows($rows, false),
            'count' => $count,
        ];
    }

    public function createPurchaseOrder(Request $request, array $data): array
    {
        if ($this->hasForbiddenStoreFields($data)) {
            throw new ApiException('supply_purchase_store_field_forbidden');
        }
        $scope = $this->resolveStoreScope($request, true);
        $key = $this->idempotencyKey($request, $data, 'purchase_create');
        return $this->withIdempotency('purchase_create', $key, $data, '', function () use ($scope, $data) {
            return $this->doCreatePurchaseOrder($scope, $data);
        });
    }

    public function storePurchaseOrderList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request, false);
        return $this->purchaseOrderList($where, (int)$scope['store_id']);
    }

    public function storePurchaseOrderDetail(Request $request, int $id): array
    {
        $scope = $this->resolveStoreScope($request, false);
        return $this->purchaseOrderDetail($id, (int)$scope['store_id']);
    }

    public function adminPurchaseOrderList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $storeId = (int)($where['store_id'] ?? 0);
        return $this->purchaseOrderList($where, $storeId);
    }

    public function adminPurchaseOrderDetail(int $id, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return $this->purchaseOrderDetail($id, 0);
    }

    public function adminAuditPurchaseOrder(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $action = (string)($data['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new ApiException('purchase_audit_action_invalid');
        }
        return Db::transaction(function () use ($id, $data, $adminId, $action) {
            $before = $this->requirePurchaseOrder($id, 0);
            if ((string)$before['status'] !== 'submitted') {
                throw new ApiException('purchase_order_audit_status_invalid');
            }
            $now = time();
            $after = $before;
            $after['status'] = $action === 'approve' ? 'approved' : 'rejected';
            $after['audit_status'] = $action === 'approve' ? 'approved' : 'rejected';
            $after['audit_uid'] = $adminId;
            $after['audit_time'] = $now;
            $after['audit_reason'] = trim((string)($data['reason'] ?? ''));
            $after['update_time'] = $now;
            app()->make(YfthPurchaseOrderDao::class)->update($id, [
                'status' => $after['status'],
                'audit_status' => $after['audit_status'],
                'audit_uid' => $adminId,
                'audit_time' => $now,
                'audit_reason' => $after['audit_reason'],
                'update_time' => $now,
            ]);
            $this->audit('purchase_order', $id, $action, $before, $after, $adminId, 'headquarter_admin', (int)$before['store_id'], $after['audit_reason']);
            return $this->purchaseOrderDetail($id, 0);
        });
    }

    public function adminShipPurchaseOrder(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return Db::transaction(function () use ($id, $data, $adminId) {
            $before = $this->requirePurchaseOrder($id, 0);
            if ((string)$before['status'] !== 'approved') {
                throw new ApiException('purchase_order_ship_status_invalid');
            }
            $shipmentDao = app()->make(YfthPurchaseShipmentDao::class);
            if ($shipmentDao->getOne(['purchase_order_id' => $id])) {
                throw new ApiException('purchase_order_already_shipped');
            }
            $now = time();
            $shipment = $shipmentDao->save([
                'purchase_order_id' => $id,
                'shipment_no' => $this->makeNo('SH'),
                'status' => 'shipped',
                'quantity_total' => (int)$before['quantity_total'],
                'operator_uid' => $adminId,
                'shipped_time' => $now,
                'logistics_company' => trim((string)($data['logistics_company'] ?? '')),
                'logistics_no' => trim((string)($data['logistics_no'] ?? '')),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $shipment = $this->rowArray($shipment);
            $after = array_merge($before, [
                'status' => 'shipped',
                'ship_time' => $now,
                'update_time' => $now,
            ]);
            app()->make(YfthPurchaseOrderDao::class)->update($id, [
                'status' => 'shipped',
                'ship_time' => $now,
                'update_time' => $now,
            ]);
            $this->audit('purchase_order', $id, 'ship', $before, $after, $adminId, 'headquarter_admin', (int)$before['store_id'], '');
            $this->audit('purchase_shipment', (int)$shipment['id'], 'create', [], $shipment, $adminId, 'headquarter_admin', (int)$before['store_id'], '');
            return array_merge($this->purchaseOrderDetail($id, 0), ['shipment' => $this->formatShipment($shipment)]);
        });
    }

    public function storeInTransitList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request, false);
        $where['status'] = 'shipped';
        return $this->purchaseOrderList($where, (int)$scope['store_id']);
    }

    public function confirmReceipt(Request $request, int $orderId, array $data): array
    {
        if ($this->hasForbiddenStoreFields($data)) {
            throw new ApiException('supply_receipt_store_field_forbidden');
        }
        $scope = $this->resolveStoreScope($request, true);
        $key = $this->idempotencyKey($request, $data, 'purchase_receipt_' . $orderId);
        return $this->withIdempotency('purchase_receipt', $key, $data, (string)$orderId, function () use ($scope, $orderId, $data) {
            return $this->doConfirmReceipt($scope, $orderId, $data);
        });
    }

    public function storeInventoryList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request, false);
        return $this->inventoryList($where, (int)$scope['store_id']);
    }

    public function storeLedgerList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request, false);
        return $this->ledgerList($where, (int)$scope['store_id']);
    }

    public function adminInventoryList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return $this->inventoryList($where, (int)($where['store_id'] ?? 0));
    }

    public function adminLedgerList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        return $this->ledgerList($where, (int)($where['store_id'] ?? 0));
    }

    public function shipmentList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = app()->make(YfthPurchaseShipmentDao::class)->search([]);
        $status = trim((string)($where['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $orderId = (int)($where['purchase_order_id'] ?? 0);
        if ($orderId > 0) {
            $query->where('purchase_order_id', $orderId);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map([$this, 'formatShipment'], $rows),
            'count' => $count,
        ];
    }

    public function alertRuleList(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = app()->make(YfthInventoryAlertRuleDao::class)->search([]);
        $storeId = (int)($where['store_id'] ?? 0);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => $this->withProductSnapshots($rows, 'product_id'),
            'count' => $count,
        ];
    }

    public function alertRuleSave(array $data, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $storeId = (int)($data['store_id'] ?? 0);
        $skuUnique = trim((string)($data['sku_unique'] ?? ''));
        $threshold = max(0, (int)($data['threshold_quantity'] ?? 0));
        if ($storeId <= 0 || $skuUnique === '') {
            throw new ApiException('inventory_alert_rule_store_sku_required');
        }
        $sku = $this->requireSku(0, $skuUnique);
        $location = $this->ensureStoreLocation($storeId);
        $dao = app()->make(YfthInventoryAlertRuleDao::class);
        $existing = $dao->getOne(['store_id' => $storeId, 'sku_unique' => $skuUnique]);
        $payload = [
            'store_id' => $storeId,
            'location_id' => (int)$location['id'],
            'product_id' => (int)$sku['product_id'],
            'sku_unique' => $skuUnique,
            'threshold_quantity' => $threshold,
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'active'), self::CATALOG_STATUSES, 'active'),
            'update_time' => time(),
        ];
        if ($existing) {
            $dao->update((int)$existing['id'], $payload);
            $row = array_merge($this->rowArray($existing), $payload);
        } else {
            $payload['create_time'] = time();
            $row = $this->rowArray($dao->save($payload));
        }
        return ['alert_rule' => $row];
    }

    private function doCreatePurchaseOrder(array $scope, array $data): array
    {
        $items = $this->normalizePurchaseItems((array)($data['items'] ?? []), (string)($scope['context']['store_type'] ?? ''));
        $now = time();
        $amount = '0.00';
        $quantityTotal = 0;
        foreach ($items as $item) {
            $amount = sprintf('%.2f', (float)$amount + (float)$item['amount_snapshot']);
            $quantityTotal += (int)$item['quantity'];
        }

        return Db::transaction(function () use ($scope, $items, $now, $amount, $quantityTotal, $data) {
            $orderDao = app()->make(YfthPurchaseOrderDao::class);
            $order = $orderDao->save([
                'purchase_no' => $this->makeNo('PO'),
                'store_id' => (int)$scope['store_id'],
                'supplier_subject_id' => (int)($data['supplier_subject_id'] ?? 0),
                'status' => 'submitted',
                'audit_status' => 'pending',
                'amount_snapshot' => $amount,
                'quantity_total' => $quantityTotal,
                'operator_uid' => (int)$scope['operator_uid'],
                'operator_role_code' => (string)$scope['role_code'],
                'idempotency_key' => trim((string)($data['idempotency_key'] ?? '')),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $order = $this->rowArray($order);
            $itemDao = app()->make(YfthPurchaseOrderItemDao::class);
            foreach ($items as $item) {
                $itemDao->save(array_merge($item, [
                    'purchase_order_id' => (int)$order['id'],
                    'create_time' => $now,
                ]));
            }
            $detail = $this->purchaseOrderDetail((int)$order['id'], (int)$scope['store_id']);
            $this->audit('purchase_order', (int)$order['id'], 'submit', [], $detail['order'], (int)$scope['operator_uid'], (string)$scope['role_code'], (int)$scope['store_id'], '');
            return $detail;
        });
    }

    private function doConfirmReceipt(array $scope, int $orderId, array $data): array
    {
        return Db::transaction(function () use ($scope, $orderId, $data) {
            $order = $this->requirePurchaseOrder($orderId, (int)$scope['store_id']);
            if ((string)$order['status'] === 'stocked') {
                return $this->purchaseOrderDetail($orderId, (int)$scope['store_id']);
            }
            if ((string)$order['status'] !== 'shipped') {
                throw new ApiException('purchase_order_receipt_status_invalid');
            }
            $shipment = app()->make(YfthPurchaseShipmentDao::class)->getOne(['purchase_order_id' => $orderId]);
            if (!$shipment) {
                throw new ApiException('purchase_shipment_not_found');
            }
            $shipment = $this->rowArray($shipment);
            $items = app()->make(YfthPurchaseOrderItemDao::class)->search([])
                ->where('purchase_order_id', $orderId)
                ->select()
                ->toArray();
            $now = time();
            $receipt = app()->make(YfthPurchaseReceiptDao::class)->save([
                'purchase_order_id' => $orderId,
                'shipment_id' => (int)$shipment['id'],
                'receipt_no' => $this->makeNo('RC'),
                'status' => 'stocked',
                'quantity_total' => (int)$order['quantity_total'],
                'operator_uid' => (int)$scope['operator_uid'],
                'operator_role_code' => (string)$scope['role_code'],
                'received_time' => $now,
                'stocked_time' => $now,
                'idempotency_key' => trim((string)($data['idempotency_key'] ?? '')),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $receipt = $this->rowArray($receipt);
            $location = $this->ensureStoreLocation((int)$scope['store_id']);
            foreach ($items as $item) {
                $this->increaseStoreInventory($location, $item, (int)$receipt['id'], $scope);
            }
            app()->make(YfthPurchaseShipmentDao::class)->update((int)$shipment['id'], [
                'status' => 'received',
                'update_time' => $now,
            ]);
            app()->make(YfthPurchaseOrderDao::class)->update($orderId, [
                'status' => 'stocked',
                'receive_time' => $now,
                'update_time' => $now,
            ]);
            $after = array_merge($order, ['status' => 'stocked', 'receive_time' => $now, 'update_time' => $now]);
            $this->audit('purchase_receipt', (int)$receipt['id'], 'stock_in', [], $receipt, (int)$scope['operator_uid'], (string)$scope['role_code'], (int)$scope['store_id'], '');
            $this->audit('purchase_order', $orderId, 'stocked', $order, $after, (int)$scope['operator_uid'], (string)$scope['role_code'], (int)$scope['store_id'], '');
            return array_merge($this->purchaseOrderDetail($orderId, (int)$scope['store_id']), ['receipt' => $receipt]);
        });
    }

    private function purchaseOrderList(array $where, int $storeId = 0): array
    {
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = app()->make(YfthPurchaseOrderDao::class)->search([]);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $status = $this->normalizeStatus((string)($where['status'] ?? ''), self::ORDER_STATUSES, '');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $keyword = trim((string)($where['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('purchase_no', '%' . $keyword . '%');
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => array_map([$this, 'formatPurchaseOrder'], $rows),
            'count' => $count,
        ];
    }

    private function purchaseOrderDetail(int $id, int $storeId = 0): array
    {
        $order = $this->requirePurchaseOrder($id, $storeId);
        $items = app()->make(YfthPurchaseOrderItemDao::class)->search([])
            ->where('purchase_order_id', $id)
            ->order('id asc')
            ->select()
            ->toArray();
        $shipments = app()->make(YfthPurchaseShipmentDao::class)->search([])
            ->where('purchase_order_id', $id)
            ->order('id desc')
            ->select()
            ->toArray();
        $receipts = app()->make(YfthPurchaseReceiptDao::class)->search([])
            ->where('purchase_order_id', $id)
            ->order('id desc')
            ->select()
            ->toArray();
        return [
            'order' => $this->formatPurchaseOrder($order, true),
            'items' => array_map([$this, 'formatPurchaseItem'], $items),
            'shipments' => array_map([$this, 'formatShipment'], $shipments),
            'receipts' => $receipts,
        ];
    }

    private function inventoryList(array $where, int $storeId = 0): array
    {
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = app()->make(YfthInventoryBalanceDao::class)->search([]);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $sku = trim((string)($where['sku_unique'] ?? ''));
        if ($sku !== '') {
            $query->where('sku_unique', $sku);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => $this->withProductSnapshots($rows, 'product_id'),
            'count' => $count,
        ];
    }

    private function ledgerList(array $where, int $storeId = 0): array
    {
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $query = app()->make(YfthInventoryLedgerDao::class)->search([]);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $sku = trim((string)($where['sku_unique'] ?? ''));
        if ($sku !== '') {
            $query->where('sku_unique', $sku);
        }
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        return [
            'list' => $this->withProductSnapshots($rows, 'product_id'),
            'count' => $count,
        ];
    }

    private function increaseStoreInventory(array $location, array $item, int $receiptId, array $scope): void
    {
        $now = time();
        $where = [
            'location_id' => (int)$location['id'],
            'sku_unique' => (string)$item['sku_unique'],
        ];
        $balance = Db::name('yfth_inventory_balance')->where($where)->lock(true)->find();
        $quantity = (int)$item['quantity'];
        if ($quantity <= 0) {
            throw new ApiException('inventory_quantity_invalid');
        }
        if ($balance) {
            $afterQty = (int)$balance['quantity'] + $quantity;
            Db::name('yfth_inventory_balance')->where('id', (int)$balance['id'])->update([
                'quantity' => $afterQty,
                'update_time' => $now,
            ]);
        } else {
            $afterQty = $quantity;
            Db::name('yfth_inventory_balance')->insert([
                'store_id' => (int)$scope['store_id'],
                'location_id' => (int)$location['id'],
                'product_id' => (int)$item['product_id'],
                'sku_unique' => (string)$item['sku_unique'],
                'quantity' => $afterQty,
                'warning_quantity' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        app()->make(YfthInventoryLedgerDao::class)->save([
            'store_id' => (int)$scope['store_id'],
            'location_id' => (int)$location['id'],
            'product_id' => (int)$item['product_id'],
            'sku_unique' => (string)$item['sku_unique'],
            'quantity_change' => $quantity,
            'balance_after' => $afterQty,
            'business_type' => 'purchase_inbound',
            'business_id' => $receiptId,
            'operator_uid' => (int)$scope['operator_uid'],
            'operator_role_code' => (string)$scope['role_code'],
            'reason' => 'purchase_receipt_stock_in',
            'add_time' => $now,
        ]);
    }

    private function normalizePurchaseItems(array $items, string $storeType): array
    {
        if (!$items) {
            throw new ApiException('purchase_items_required');
        }
        $result = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $skuUnique = trim((string)($item['sku_unique'] ?? ''));
            $quantity = (int)($item['quantity'] ?? 0);
            if ($productId <= 0 || $skuUnique === '' || $quantity <= 0) {
                throw new ApiException('purchase_item_invalid');
            }
            $catalog = $this->activeCatalogForStore($productId, $storeType);
            $sku = $this->requireSku($productId, $skuUnique);
            $product = $this->requireProduct($productId);
            $min = max(1, (int)$catalog['min_purchase_quantity']);
            $multiple = max(1, (int)$catalog['package_multiple']);
            if ($quantity < $min || $quantity % $multiple !== 0) {
                throw new ApiException('purchase_quantity_rule_invalid');
            }
            $price = sprintf('%.2f', (float)$catalog['purchase_price']);
            $amount = sprintf('%.2f', (float)$price * $quantity);
            $result[] = [
                'product_id' => $productId,
                'sku_unique' => $skuUnique,
                'product_name_snapshot' => (string)($product['store_name'] ?? ''),
                'sku_name_snapshot' => (string)($sku['suk'] ?? ''),
                'quantity' => $quantity,
                'purchase_price_snapshot' => $price,
                'amount_snapshot' => $amount,
            ];
        }
        return $result;
    }

    private function activeCatalogForStore(int $productId, string $storeType): array
    {
        $catalog = $this->dao->getOne(['product_id' => $productId, 'status' => 'active']);
        if (!$catalog) {
            throw new ApiException('purchase_catalog_not_available');
        }
        $catalog = $this->rowArray($catalog);
        $allowed = array_filter(array_map('trim', explode(',', (string)($catalog['allow_store_types'] ?? ''))));
        if ($allowed && $storeType !== '' && !in_array($storeType, $allowed, true)) {
            throw new ApiException('purchase_catalog_store_type_forbidden');
        }
        return $catalog;
    }

    private function normalizeCatalogPayload(array $data, int $adminId): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new ApiException('supply_catalog_product_id_required');
        }
        $this->requireProduct($productId);
        $purchasePrice = is_numeric($data['purchase_price'] ?? null) ? (float)$data['purchase_price'] : -1;
        if ($purchasePrice < 0) {
            throw new ApiException('supply_catalog_purchase_price_invalid');
        }
        $retailPrice = is_numeric($data['retail_reference_price'] ?? null) ? (float)$data['retail_reference_price'] : 0;
        $min = max(1, (int)($data['min_purchase_quantity'] ?? 1));
        $multiple = max(1, (int)($data['package_multiple'] ?? 1));
        if ($min % $multiple !== 0 && $min > $multiple) {
            throw new ApiException('supply_catalog_quantity_rule_invalid');
        }
        $now = time();
        return [
            'product_id' => $productId,
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'active'), self::CATALOG_STATUSES, 'active'),
            'purchase_price' => sprintf('%.2f', $purchasePrice),
            'retail_reference_price' => sprintf('%.2f', max(0, $retailPrice)),
            'min_purchase_quantity' => $min,
            'package_multiple' => $multiple,
            'allow_store_types' => $this->normalizeCsv((string)($data['allow_store_types'] ?? '')),
            'qualification_requirement' => substr(trim((string)($data['qualification_requirement'] ?? '')), 0, 255),
            'created_uid' => (int)($data['id'] ?? 0) > 0 ? (int)($data['created_uid'] ?? 0) : $adminId,
            'updated_uid' => $adminId,
            'create_time' => (int)($data['id'] ?? 0) > 0 ? (int)($data['create_time'] ?? 0) : $now,
            'update_time' => $now,
        ];
    }

    private function resolveStoreScope(Request $request, bool $write): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $roleCode = (string)($context['role_code'] ?? '');
        $allowed = $write ? self::STORE_WRITE_ROLES : self::STORE_READ_ROLES;
        if (!in_array($roleCode, $allowed, true)) {
            throw new ApiException($write ? 'supply_store_write_role_forbidden' : 'supply_store_read_role_forbidden');
        }
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new ApiException('supply_store_id_required');
        }
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        if ($write && !$this->hasStorePurchaseCapability($context)) {
            throw new ApiException('supply_store_purchase_capability_required');
        }
        return [
            'context' => $context,
            'store_id' => $storeId,
            'role_code' => $roleCode,
            'operator_uid' => (int)($context['uid'] ?? 0),
        ];
    }

    private function hasStorePurchaseCapability(array $context): bool
    {
        $capabilities = (array)($context['capabilities'] ?? []);
        return !$capabilities || in_array('store_purchase', $capabilities, true);
    }

    private function requirePurchaseOrder(int $id, int $storeId = 0): array
    {
        if ($id <= 0) {
            throw new ApiException('purchase_order_id_required');
        }
        $query = app()->make(YfthPurchaseOrderDao::class)->search([])->where('id', $id);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $row = $query->find();
        if (!$row) {
            throw new ApiException('purchase_order_not_found');
        }
        return $this->rowArray($row);
    }

    private function requireProduct(int $productId): array
    {
        $product = Db::name('store_product')
            ->where('id', $productId)
            ->where('is_del', 0)
            ->field('id,store_name,image,price,ot_price,stock,sales,is_show')
            ->find();
        if (!$product) {
            throw new ApiException('crmeb_product_not_found');
        }
        return $product;
    }

    private function requireSku(int $productId, string $skuUnique): array
    {
        $query = Db::name('store_product_attr_value')
            ->where('unique', $skuUnique)
            ->where('type', 0);
        if ($productId > 0) {
            $query->where('product_id', $productId);
        }
        $sku = $query->field('id,product_id,suk,unique,price,stock,sales,bar_code')->find();
        if (!$sku) {
            throw new ApiException('crmeb_sku_not_found');
        }
        return $sku;
    }

    private function ensureStoreLocation(int $storeId): array
    {
        $code = 'STORE-' . $storeId;
        $dao = app()->make(YfthStockLocationDao::class);
        $row = $dao->getOne(['location_code' => $code]);
        if ($row) {
            return $this->rowArray($row);
        }
        $now = time();
        return $this->rowArray($dao->save([
            'location_code' => $code,
            'location_type' => 'store',
            'store_id' => $storeId,
            'name' => 'Store warehouse ' . $storeId,
            'status' => 'active',
            'create_time' => $now,
            'update_time' => $now,
        ]));
    }

    private function formatCatalogRows(array $rows, bool $admin): array
    {
        $productMap = $this->productMap(array_column($rows, 'product_id'));
        $skuMap = $this->skuMap(array_column($rows, 'product_id'));
        return array_map(function ($row) use ($productMap, $skuMap, $admin) {
            $productId = (int)($row['product_id'] ?? 0);
            return $this->formatCatalog($row, $admin, $productMap[$productId] ?? [], $skuMap[$productId] ?? []);
        }, $rows);
    }

    private function formatCatalog(array $row, bool $admin, array $product = [], array $skus = []): array
    {
        if (!$product && !empty($row['product_id'])) {
            $product = $this->productMap([(int)$row['product_id']])[(int)$row['product_id']] ?? [];
        }
        if (!$skus && !empty($row['product_id'])) {
            $skus = $this->skuMap([(int)$row['product_id']])[(int)$row['product_id']] ?? [];
        }
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'product_id' => (int)($row['product_id'] ?? 0),
            'product_name' => (string)($product['store_name'] ?? ''),
            'product_image' => (string)($product['image'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'purchase_price' => (string)($row['purchase_price'] ?? '0.00'),
            'retail_reference_price' => (string)($row['retail_reference_price'] ?? '0.00'),
            'min_purchase_quantity' => (int)($row['min_purchase_quantity'] ?? 1),
            'package_multiple' => (int)($row['package_multiple'] ?? 1),
            'allow_store_types' => (string)($row['allow_store_types'] ?? ''),
            'qualification_requirement' => (string)($row['qualification_requirement'] ?? ''),
            'skus' => $skus,
        ];
        if ($admin) {
            $payload['created_uid'] = (int)($row['created_uid'] ?? 0);
            $payload['updated_uid'] = (int)($row['updated_uid'] ?? 0);
            $payload['create_time'] = (int)($row['create_time'] ?? 0);
            $payload['update_time'] = (int)($row['update_time'] ?? 0);
        }
        return $payload;
    }

    private function formatPurchaseOrder(array $row, bool $detail = false): array
    {
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'purchase_no' => (string)($row['purchase_no'] ?? ''),
            'store_id' => (int)($row['store_id'] ?? 0),
            'supplier_subject_id' => (int)($row['supplier_subject_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'status_text' => $this->orderStatusText((string)($row['status'] ?? '')),
            'audit_status' => (string)($row['audit_status'] ?? ''),
            'amount_snapshot' => (string)($row['amount_snapshot'] ?? '0.00'),
            'quantity_total' => (int)($row['quantity_total'] ?? 0),
            'operator_uid' => (int)($row['operator_uid'] ?? 0),
            'operator_role_code' => (string)($row['operator_role_code'] ?? ''),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
        if ($detail) {
            $payload['audit_uid'] = (int)($row['audit_uid'] ?? 0);
            $payload['audit_time'] = (int)($row['audit_time'] ?? 0);
            $payload['audit_reason'] = (string)($row['audit_reason'] ?? '');
            $payload['ship_time'] = (int)($row['ship_time'] ?? 0);
            $payload['receive_time'] = (int)($row['receive_time'] ?? 0);
        }
        return $payload;
    }

    private function formatPurchaseItem(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'purchase_order_id' => (int)($row['purchase_order_id'] ?? 0),
            'product_id' => (int)($row['product_id'] ?? 0),
            'sku_unique' => (string)($row['sku_unique'] ?? ''),
            'product_name_snapshot' => (string)($row['product_name_snapshot'] ?? ''),
            'sku_name_snapshot' => (string)($row['sku_name_snapshot'] ?? ''),
            'quantity' => (int)($row['quantity'] ?? 0),
            'purchase_price_snapshot' => (string)($row['purchase_price_snapshot'] ?? '0.00'),
            'amount_snapshot' => (string)($row['amount_snapshot'] ?? '0.00'),
        ];
    }

    private function formatShipment(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'purchase_order_id' => (int)($row['purchase_order_id'] ?? 0),
            'shipment_no' => (string)($row['shipment_no'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'quantity_total' => (int)($row['quantity_total'] ?? 0),
            'operator_uid' => (int)($row['operator_uid'] ?? 0),
            'shipped_time' => (int)($row['shipped_time'] ?? 0),
            'logistics_company' => (string)($row['logistics_company'] ?? ''),
            'logistics_no' => (string)($row['logistics_no'] ?? ''),
        ];
    }

    private function withProductSnapshots(array $rows, string $productField): array
    {
        $productMap = $this->productMap(array_column($rows, $productField));
        return array_map(function ($row) use ($productMap, $productField) {
            $product = $productMap[(int)($row[$productField] ?? 0)] ?? [];
            $row['product_name'] = (string)($product['store_name'] ?? '');
            $row['product_image'] = (string)($product['image'] ?? '');
            return $row;
        }, $rows);
    }

    private function productMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = Db::name('store_product')
            ->whereIn('id', $ids)
            ->field('id,store_name,image,price,ot_price,stock,sales,is_show')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }
        return $map;
    }

    private function skuMap(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $rows = Db::name('store_product_attr_value')
            ->whereIn('product_id', $productIds)
            ->where('type', 0)
            ->field('product_id,suk,unique,price,stock,sales,bar_code')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['product_id']][] = [
                'sku_unique' => (string)$row['unique'],
                'sku_name' => (string)$row['suk'],
                'price' => (string)$row['price'],
            ];
        }
        return $map;
    }

    private function assertHeadquarterAdmin(array $adminInfo): void
    {
        if (!$adminInfo || (int)($adminInfo['id'] ?? 0) <= 0) {
            throw new ApiException('headquarter_admin_required');
        }
        app()->make(AdminStoreContextServices::class)->assertHeadquarterScope($adminInfo);
    }

    private function audit(string $objectType, int $objectId, string $action, array $before, array $after, int $operatorUid, string $roleCode, int $storeId, string $reason): void
    {
        app()->make(AuditEventServices::class)->recordSafely(
            self::DOMAIN,
            $objectType,
            (string)$objectId,
            $action,
            $this->sanitizeState($before),
            $this->sanitizeState($after),
            $operatorUid,
            $roleCode,
            $storeId,
            $reason,
            ''
        );
    }

    private function withIdempotency(string $action, string $key, array $payload, string $objectId, callable $callback): array
    {
        if ($key === '') {
            return $callback();
        }
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin(self::DOMAIN, $action, $key, $payload, $objectId, 600);
        if (!$begin['acquired']) {
            if (($begin['status'] ?? '') === 'succeeded') {
                return (array)($begin['result_summary'] ?? []);
            }
            if (($begin['can_retry'] ?? false) === true) {
                $begin = $idempotency->tryReacquire($begin['record'], 600);
            }
            if (!$begin['acquired']) {
                throw new ApiException('idempotency_request_processing');
            }
        }
        $recordId = (int)$begin['record']['id'];
        try {
            $result = $callback();
            $idempotency->complete($recordId, $result);
            return $result;
        } catch (\Throwable $e) {
            $idempotency->fail($recordId, $e->getMessage());
            throw $e;
        }
    }

    private function idempotencyKey(Request $request, array $data, string $fallbackPrefix): string
    {
        $key = trim((string)($data['idempotency_key'] ?? ''));
        if ($key === '') {
            $key = trim((string)$request->header('Idempotency-Key', ''));
        }
        return $key !== '' ? $key : '';
    }

    private function hasForbiddenStoreFields(array $data): bool
    {
        foreach (['store_id', 'store_ids', 'operator_uid', 'operator_role_code', 'role_code'] as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }
        return false;
    }

    private function rowArray($row): array
    {
        if (!$row) {
            return [];
        }
        return is_array($row) ? $row : $row->toArray();
    }

    private function normalizeStatus(string $status, array $allowed, string $default): string
    {
        $status = trim($status);
        return in_array($status, $allowed, true) ? $status : $default;
    }

    private function normalizeCsv(string $value): string
    {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
        return implode(',', $items);
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function orderStatusText(string $status): string
    {
        $map = [
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'shipped' => 'Shipped',
            'stocked' => 'Stocked',
            'cancelled' => 'Cancelled',
        ];
        return $map[$status] ?? $status;
    }
}
