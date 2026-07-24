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
use app\services\order\StoreCartServices;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class SupplyChainServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_supply_chain';
    private const STORE_READ_ROLES = ['store_manager', 'store_staff', 'county_partner', 'prefecture_partner', 'province_partner', 'regional_director', 'platform_director'];
    private const STORE_WRITE_ROLES = ['store_manager'];
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
        $id = (int)($data['id'] ?? 0);
        $before = $id > 0 ? $this->rowArray($this->dao->get($id)) : [];
        $payload = $this->normalizeCatalogPayload($data, $adminId, $before);

        $skuPrices = (array)($data['sku_prices'] ?? []);
        return Db::transaction(function () use ($id, $payload, $before, $adminId, $skuPrices) {
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
            $this->syncCatalogSkuPrices((int)$row['id'], (int)$payload['product_id'], $skuPrices);
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

    public function adminImportVisibleProducts(array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        $requestedIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['product_ids'] ?? [])))));
        $query = Db::name('store_product')
            ->where('is_del', 0)
            ->where('is_show', 1)
            ->where('is_virtual', 0);
        if ($requestedIds) {
            $query->whereIn('id', $requestedIds);
        }
        $products = $query
            ->field('id,store_name,price')
            ->order('id asc')
            ->select()
            ->toArray();
        if (!$products) {
            throw new ApiException('supply_catalog_no_visible_physical_products');
        }

        $existingIds = $this->dao->search([])
            ->whereIn('product_id', array_column($products, 'id'))
            ->column('product_id');
        $existingMap = array_fill_keys(array_map('intval', $existingIds), true);
        $imported = [];
        $skipped = [];
        $now = time();

        foreach ($products as $product) {
            $productId = (int)$product['id'];
            if (isset($existingMap[$productId])) {
                $skipped[] = $productId;
                continue;
            }
            $price = $this->normalizeMoney((string)$product['price']);
            if ($price === '') {
                $skipped[] = $productId;
                continue;
            }
            $payload = [
                'product_id' => $productId,
                'status' => 'active',
                'purchase_price' => $price,
                'retail_reference_price' => $price,
                'min_purchase_quantity' => 1,
                'package_multiple' => 1,
                'allow_store_types' => '',
                'qualification_requirement' => '',
                'created_uid' => $adminId,
                'updated_uid' => $adminId,
                'create_time' => $now,
                'update_time' => $now,
            ];
            try {
                $created = $this->dao->save($payload);
            } catch (\Throwable $e) {
                if ($this->dao->getOne(['product_id' => $productId])) {
                    $skipped[] = $productId;
                    continue;
                }
                throw $e;
            }
            $row = $this->rowArray($created);
            $this->audit('supply_catalog', (int)$row['id'], 'import_visible_product', [], $row, $adminId, 'headquarter_admin', 0, '');
            $imported[] = $this->formatCatalog($row, true, $product);
        }

        return [
            'imported_count' => count($imported),
            'skipped_count' => count($skipped),
            'imported' => $imported,
            'skipped_product_ids' => $skipped,
        ];
    }

    public function productSearch(array $where, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $keyword = trim((string)($where['keyword'] ?? ''));
        $query = Db::name('store_product')
            ->where('is_del', 0)
            ->where('is_virtual', 0);
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
        $buildQuery = function () use ($keyword) {
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
        throw new ApiException('procurement_legacy_runtime_disabled');
    }

    public function prepareNativeCheckout(Request $request, array $data): array
    {
        if ($this->hasForbiddenStoreFields($data)) {
            throw new ApiException('supply_purchase_store_field_forbidden');
        }
        $scope = $this->resolveStoreScope($request, true);
        $items = $this->normalizePurchaseItems((array)($data['items'] ?? []), '');
        $cartIds = [];
        $cartServices = app()->make(StoreCartServices::class);
        foreach ($items as $item) {
            $cartIds[] = (string)$cartServices->setCart(
                (int)$scope['operator_uid'],
                (int)$item['product_id'],
                (int)$item['quantity'],
                (string)$item['sku_unique'],
                0,
                true,
                0,
                0,
                0,
                0,
                [
                    'channel' => 'procurement',
                    'store_id' => (int)$scope['store_id'],
                    'catalog_id' => (int)$item['catalog_id'],
                    'unit_price' => (string)$item['purchase_price_snapshot'],
                ]
            );
        }
        return [
            'cart_ids' => implode(',', $cartIds),
            'order_confirm_url' => '/pages/goods/order_confirm/index?new=1&cartId=' . implode(',', $cartIds),
        ];
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
        throw new ApiException('procurement_legacy_runtime_disabled');
    }

    public function adminShipPurchaseOrder(int $id, array $data, int $adminId, array $adminInfo = []): array
    {
        $this->assertHeadquarterAdmin($adminInfo);
        throw new ApiException('procurement_legacy_runtime_disabled');
    }

    public function storeInTransitList(Request $request, array $where): array
    {
        $scope = $this->resolveStoreScope($request, false);
        $where['status'] = 'shipped';
        return $this->purchaseOrderList($where, (int)$scope['store_id']);
    }

    public function confirmReceipt(Request $request, int $orderId, array $data): array
    {
        throw new ApiException('procurement_legacy_runtime_disabled');
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
        throw new ApiException('procurement_legacy_runtime_disabled');
    }

    private function doCreatePurchaseOrder(array $scope, array $data): array
    {
        $items = $this->normalizePurchaseItems((array)($data['items'] ?? []), (string)($scope['context']['store_type'] ?? ''));
        $address = $this->requirePurchaseAddress((int)$scope['operator_uid'], (int)($data['address_id'] ?? 0));
        $now = time();
        $amountCents = 0;
        $quantityTotal = 0;
        foreach ($items as $item) {
            $amountCents += $this->decimalToCents((string)$item['amount_snapshot']);
            $quantityTotal += (int)$item['quantity'];
        }
        $amount = $this->centsToDecimal($amountCents);

        return Db::transaction(function () use ($scope, $items, $address, $now, $amount, $amountCents, $quantityTotal, $data) {
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
                'address_id' => (int)$address['id'],
                'real_name' => (string)$address['real_name'],
                'user_phone' => (string)$address['phone'],
                'user_address' => trim(implode(' ', array_filter([
                    (string)($address['province'] ?? ''),
                    (string)($address['city'] ?? ''),
                    (string)($address['district'] ?? ''),
                    (string)($address['detail'] ?? ''),
                ]))),
                'freight_price' => '0.00',
                'pay_type' => $this->normalizePurchasePayType((string)($data['pay_type'] ?? 'offline')),
                'pay_status' => 'pending',
                'buyer_mark' => substr(trim((string)($data['buyer_mark'] ?? '')), 0, 255),
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
            app()->make(ProductQuotaPurchaseServices::class)->reserve(
                (int)$order['id'], (int)$scope['store_id'], $amountCents,
                max(0, (int)($data['quota_amount_cent'] ?? 0)),
                trim((string)($data['idempotency_key'] ?? '')) . ':quota'
            );
            app()->make(ProcurementPartnerProfitServices::class)->freezeForPurchaseOrder($order, $amountCents);
            $detail = $this->purchaseOrderDetail((int)$order['id'], (int)$scope['store_id']);
            $this->audit('purchase_order', (int)$order['id'], 'submit', [], $detail['order'], (int)$scope['operator_uid'], (string)$scope['role_code'], (int)$scope['store_id'], '');
            return $detail;
        });
    }

    private function doConfirmReceipt(array $scope, int $orderId, array $data): array
    {
        return Db::transaction(function () use ($scope, $orderId, $data) {
            $order = $this->lockPurchaseOrder($orderId, (int)$scope['store_id']);
            if ((string)$order['status'] === 'stocked') {
                $existingReceipt = $this->rowArray(app()->make(YfthPurchaseReceiptDao::class)->getOne(['purchase_order_id' => $orderId]));
                return array_merge($this->purchaseOrderDetail($orderId, (int)$scope['store_id']), ['receipt' => $existingReceipt]);
            }
            if ((string)$order['status'] !== 'shipped') {
                throw new ApiException('purchase_order_receipt_status_invalid');
            }
            $shipment = app()->make(YfthPurchaseShipmentDao::class)->getOne(['purchase_order_id' => $orderId]);
            if (!$shipment) {
                throw new ApiException('purchase_shipment_not_found');
            }
            $shipment = $this->rowArray($shipment);
            if (app()->make(YfthPurchaseReceiptDao::class)->getOne(['purchase_order_id' => $orderId])) {
                throw new ApiException('purchase_order_already_stocked');
            }
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
            app()->make(ProductQuotaPurchaseServices::class)->useForStockIn($orderId);
            app()->make(ProcurementPartnerProfitServices::class)->recognizeForReceipt($orderId);
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
        $quotaPayment = Db::name('yfth_product_quota_reservation')->where('purchase_order_id', $id)->find() ?: [];
        $productMap = $this->productMap(array_column($items, 'product_id'));
        $formattedItems = array_map(function ($item) use ($productMap) {
            $formatted = $this->formatPurchaseItem($item);
            $product = $productMap[(int)$formatted['product_id']] ?? [];
            $formatted['product_image'] = (string)($product['image'] ?? '');
            return $formatted;
        }, $items);
        return [
            'order' => $this->formatPurchaseOrder($order, true),
            'items' => $formattedItems,
            'shipments' => array_map([$this, 'formatShipment'], $shipments),
            'receipts' => $receipts,
            'quota_payment' => $quotaPayment,
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
            $skuPrice = Db::name('yfth_supply_catalog_sku')
                ->where('catalog_id', (int)$catalog['id'])
                ->where('sku_unique', $skuUnique)
                ->value('purchase_price');
            $priceCents = $this->decimalToCents((string)($skuPrice ?: $catalog['purchase_price']));
            $price = $this->centsToDecimal($priceCents);
            $amount = $this->centsToDecimal($priceCents * $quantity);
            $result[] = [
                'product_id' => $productId,
                'catalog_id' => (int)$catalog['id'],
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
        return $catalog;
    }

    private function normalizeCatalogPayload(array $data, int $adminId, array $before = []): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new ApiException('supply_catalog_product_id_required');
        }
        $this->requireProduct($productId);
        $purchasePrice = $this->normalizeMoney((string)($data['purchase_price'] ?? ''));
        if ($purchasePrice === '') {
            throw new ApiException('supply_catalog_purchase_price_invalid');
        }
        $retailPrice = $this->normalizeMoney((string)($data['retail_reference_price'] ?? '0.00'));
        if ($retailPrice === '') {
            $retailPrice = '0.00';
        }
        $min = max(1, (int)($data['min_purchase_quantity'] ?? 1));
        $multiple = max(1, (int)($data['package_multiple'] ?? 1));
        if ($min % $multiple !== 0 && $min > $multiple) {
            throw new ApiException('supply_catalog_quantity_rule_invalid');
        }
        $now = time();
        return [
            'product_id' => $productId,
            'status' => $this->normalizeStatus((string)($data['status'] ?? 'active'), self::CATALOG_STATUSES, 'active'),
            'purchase_price' => $purchasePrice,
            'retail_reference_price' => $retailPrice,
            'min_purchase_quantity' => $min,
            'package_multiple' => $multiple,
            'allow_store_types' => '',
            'qualification_requirement' => '',
            'created_uid' => $before ? (int)($before['created_uid'] ?? 0) : $adminId,
            'updated_uid' => $adminId,
            'create_time' => $before ? (int)($before['create_time'] ?? 0) : $now,
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
        return [
            'context' => $context,
            'store_id' => $storeId,
            'role_code' => $roleCode,
            'operator_uid' => (int)($context['uid'] ?? 0),
        ];
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

    private function lockPurchaseOrder(int $id, int $storeId = 0): array
    {
        if ($id <= 0) {
            throw new ApiException('purchase_order_id_required');
        }
        $query = Db::name('yfth_purchase_order')->where('id', $id);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $row = $query->lock(true)->find();
        if (!$row) {
            throw new ApiException('purchase_order_not_found');
        }
        return $row;
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
        $skuPriceRows = Db::name('yfth_supply_catalog_sku')
            ->where('catalog_id', (int)($row['id'] ?? 0))
            ->column('purchase_price', 'sku_unique');
        $defaultPurchasePrice = (string)($row['purchase_price'] ?? '0.00');
        foreach ($skus as &$sku) {
            $unique = (string)($sku['sku_unique'] ?? '');
            $sku['purchase_price'] = (string)($skuPriceRows[$unique] ?? $defaultPurchasePrice);
        }
        unset($sku);
        $payload = [
            'id' => (int)($row['id'] ?? 0),
            'product_id' => (int)($row['product_id'] ?? 0),
            'product_name' => (string)($product['store_name'] ?? ''),
            'product_image' => (string)($product['image'] ?? ''),
            'slider_images' => $this->decodeProductImages($product['slider_image'] ?? ''),
            'product_info' => (string)($product['store_info'] ?? ''),
            'retail_price' => (string)($product['price'] ?? '0.00'),
            'stock' => (int)($product['stock'] ?? 0),
            'unit_name' => (string)($product['unit_name'] ?? ''),
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

    private function syncCatalogSkuPrices(int $catalogId, int $productId, array $skuPrices): void
    {
        $now = time();
        $rows = [];
        foreach ($skuPrices as $item) {
            $unique = trim((string)($item['sku_unique'] ?? ''));
            $price = $this->normalizeMoney((string)($item['purchase_price'] ?? ''));
            if ($unique === '' || $price === '') {
                throw new ApiException('supply_catalog_sku_price_invalid');
            }
            $this->requireSku($productId, $unique);
            $rows[] = [
                'catalog_id' => $catalogId,
                'product_id' => $productId,
                'sku_unique' => $unique,
                'purchase_price' => $price,
                'create_time' => $now,
                'update_time' => $now,
            ];
        }
        Db::name('yfth_supply_catalog_sku')->where('catalog_id', $catalogId)->delete();
        if ($rows) {
            Db::name('yfth_supply_catalog_sku')->insertAll($rows);
        }
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
            'real_name' => (string)($row['real_name'] ?? ''),
            'user_phone' => (string)($row['user_phone'] ?? ''),
            'user_address' => (string)($row['user_address'] ?? ''),
            'freight_price' => (string)($row['freight_price'] ?? '0.00'),
            'pay_type' => (string)($row['pay_type'] ?? ''),
            'pay_status' => (string)($row['pay_status'] ?? ''),
            'buyer_mark' => (string)($row['buyer_mark'] ?? ''),
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
            ->field('id,store_name,store_info,image,slider_image,price,ot_price,stock,sales,is_show,unit_name')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }
        return $map;
    }

    private function requirePurchaseAddress(int $uid, int $addressId): array
    {
        if ($addressId <= 0) {
            return [
                'id' => 0,
                'real_name' => '',
                'phone' => '',
                'province' => '',
                'city' => '',
                'district' => '',
                'detail' => '',
            ];
        }
        $address = Db::name('user_address')
            ->where('id', $addressId)
            ->where('uid', $uid)
            ->where('is_del', 0)
            ->find();
        if (!$address) {
            throw new ApiException('purchase_address_invalid');
        }
        return $address;
    }

    private function normalizePurchasePayType(string $payType): string
    {
        $payType = strtolower(trim($payType));
        return in_array($payType, ['weixin', 'yue', 'offline'], true) ? $payType : 'offline';
    }

    private function decodeProductImages($images): array
    {
        if (is_array($images)) {
            return array_values(array_filter(array_map('strval', $images)));
        }
        $decoded = json_decode((string)$images, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
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
            throw new ApiException('idempotency_key_required');
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
        return $key !== '' ? $key : $fallbackPrefix;
    }

    private function normalizeMoney(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return '';
        }
        return $this->centsToDecimal($this->decimalToCents($value));
    }

    private function decimalToCents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new ApiException('decimal_money_invalid');
        }
        [$yuan, $cent] = array_pad(explode('.', $value, 2), 2, '0');
        return ((int)$yuan * 100) + (int)str_pad(substr($cent, 0, 2), 2, '0');
    }

    private function centsToDecimal(int $cents): string
    {
        if ($cents < 0) {
            throw new ApiException('decimal_money_invalid');
        }
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
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
