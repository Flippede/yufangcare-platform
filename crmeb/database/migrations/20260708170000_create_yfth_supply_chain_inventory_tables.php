<?php

use think\migration\Migrator;

class CreateYfthSupplyChainInventoryTables extends Migrator
{
    private $menuKeys = [
        'yfth-supply-chain-index',
        'yfth-supply-catalog-list',
        'yfth-supply-catalog-save',
        'yfth-supply-catalog-disable',
        'yfth-supply-product-search',
        'yfth-purchase-order-list',
        'yfth-purchase-order-detail',
        'yfth-purchase-order-audit',
        'yfth-purchase-order-ship',
        'yfth-purchase-shipment-list',
        'yfth-inventory-balance-list',
        'yfth-inventory-ledger-list',
        'yfth-inventory-alert-rule-list',
        'yfth-inventory-alert-rule-save',
    ];

    public function up()
    {
        $this->createSupplyCatalog();
        $this->createPurchaseOrder();
        $this->createPurchaseOrderItem();
        $this->createStockLocation();
        $this->createInventoryBalance();
        $this->createInventoryLedger();
        $this->createPurchaseShipment();
        $this->createPurchaseReceipt();
        $this->createInventoryAlertRule();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        foreach ([
            'yfth_inventory_alert_rule',
            'yfth_purchase_receipt',
            'yfth_purchase_shipment',
            'yfth_inventory_ledger',
            'yfth_inventory_balance',
            'yfth_stock_location',
            'yfth_purchase_order_item',
            'yfth_purchase_order',
            'yfth_supply_catalog',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createSupplyCatalog(): void
    {
        if ($this->hasTable('yfth_supply_catalog')) {
            return;
        }
        $this->table('yfth_supply_catalog')
            ->setEngine('InnoDB')
            ->setComment('YFTH HQ purchase catalog referencing CRMEB products')
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB store_product id'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('purchase_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'store purchase price'])
            ->addColumn('retail_reference_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'retail reference price'])
            ->addColumn('min_purchase_quantity', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'min purchase quantity'])
            ->addColumn('package_multiple', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'package multiple'])
            ->addColumn('allow_store_types', 'string', ['limit' => 255, 'default' => '', 'comment' => 'comma separated store types'])
            ->addColumn('qualification_requirement', 'string', ['limit' => 255, 'default' => '', 'comment' => 'qualification requirement'])
            ->addColumn('created_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'admin creator id'])
            ->addColumn('updated_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'admin updater id'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['product_id'], ['unique' => true, 'name' => 'uniq_yfth_supply_catalog_product'])
            ->addIndex(['status', 'product_id'], ['name' => 'idx_yfth_supply_catalog_status_product'])
            ->create();
    }

    private function createPurchaseOrder(): void
    {
        if ($this->hasTable('yfth_purchase_order')) {
            return;
        }
        $this->table('yfth_purchase_order')
            ->setEngine('InnoDB')
            ->setComment('YFTH store purchase orders independent from CRMEB sales orders')
            ->addColumn('purchase_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'purchase order number'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB store id'])
            ->addColumn('supplier_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'YFTH supplier subject id'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'submitted', 'comment' => 'submitted/approved/rejected/shipped/stocked/cancelled'])
            ->addColumn('audit_status', 'string', ['limit' => 32, 'default' => 'pending', 'comment' => 'pending/approved/rejected'])
            ->addColumn('amount_snapshot', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'total amount snapshot'])
            ->addColumn('quantity_total', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'total item quantity'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user uid'])
            ->addColumn('operator_role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'store operator role'])
            ->addColumn('audit_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'admin audit id'])
            ->addColumn('audit_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'audit time'])
            ->addColumn('audit_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'audit reason'])
            ->addColumn('ship_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'ship time'])
            ->addColumn('receive_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'receive time'])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'default' => '', 'comment' => 'client idempotency key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['purchase_no'], ['unique' => true, 'name' => 'uniq_yfth_purchase_order_no'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_purchase_order_store_status'])
            ->addIndex(['audit_status', 'create_time'], ['name' => 'idx_yfth_purchase_order_audit_time'])
            ->addIndex(['idempotency_key'], ['name' => 'idx_yfth_purchase_order_idem'])
            ->create();
    }

    private function createPurchaseOrderItem(): void
    {
        if ($this->hasTable('yfth_purchase_order_item')) {
            return;
        }
        $this->table('yfth_purchase_order_item')
            ->setEngine('InnoDB')
            ->setComment('YFTH purchase order items')
            ->addColumn('purchase_order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase order id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('sku_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('product_name_snapshot', 'string', ['limit' => 255, 'default' => '', 'comment' => 'product name snapshot'])
            ->addColumn('sku_name_snapshot', 'string', ['limit' => 255, 'default' => '', 'comment' => 'sku name snapshot'])
            ->addColumn('quantity', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'quantity'])
            ->addColumn('purchase_price_snapshot', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'purchase price snapshot'])
            ->addColumn('amount_snapshot', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'line amount snapshot'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['purchase_order_id'], ['name' => 'idx_yfth_purchase_item_order'])
            ->addIndex(['product_id', 'sku_unique'], ['name' => 'idx_yfth_purchase_item_product_sku'])
            ->create();
    }

    private function createStockLocation(): void
    {
        if ($this->hasTable('yfth_stock_location')) {
            return;
        }
        $this->table('yfth_stock_location')
            ->setEngine('InnoDB')
            ->setComment('YFTH stock locations')
            ->addColumn('location_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'unique location code'])
            ->addColumn('location_type', 'string', ['limit' => 32, 'default' => 'store', 'comment' => 'headquarter/store'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id, 0 for HQ'])
            ->addColumn('name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'location name'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['location_code'], ['unique' => true, 'name' => 'uniq_yfth_stock_location_code'])
            ->addIndex(['store_id', 'location_type', 'status'], ['name' => 'idx_yfth_stock_location_store_type'])
            ->create();
    }

    private function createInventoryBalance(): void
    {
        if ($this->hasTable('yfth_inventory_balance')) {
            return;
        }
        $this->table('yfth_inventory_balance')
            ->setEngine('InnoDB')
            ->setComment('YFTH inventory balance independent from CRMEB sales stock')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('location_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'stock location id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('sku_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('quantity', 'integer', ['default' => 0, 'comment' => 'current quantity'])
            ->addColumn('warning_quantity', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'warning quantity'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['location_id', 'sku_unique'], ['unique' => true, 'name' => 'uniq_yfth_inventory_balance_location_sku'])
            ->addIndex(['store_id', 'sku_unique'], ['name' => 'idx_yfth_inventory_balance_store_sku'])
            ->addIndex(['product_id'], ['name' => 'idx_yfth_inventory_balance_product'])
            ->create();
    }

    private function createInventoryLedger(): void
    {
        if ($this->hasTable('yfth_inventory_ledger')) {
            return;
        }
        $this->table('yfth_inventory_ledger')
            ->setEngine('InnoDB')
            ->setComment('YFTH immutable inventory ledger')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('location_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'stock location id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('sku_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('quantity_change', 'integer', ['default' => 0, 'comment' => 'quantity delta'])
            ->addColumn('balance_after', 'integer', ['default' => 0, 'comment' => 'balance after change'])
            ->addColumn('business_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'inbound/outbound/adjustment/return'])
            ->addColumn('business_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'business record id'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('operator_role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'operator role'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reason'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['store_id', 'sku_unique', 'add_time'], ['name' => 'idx_yfth_inventory_ledger_store_sku_time'])
            ->addIndex(['business_type', 'business_id'], ['name' => 'idx_yfth_inventory_ledger_business'])
            ->create();
    }

    private function createPurchaseShipment(): void
    {
        if ($this->hasTable('yfth_purchase_shipment')) {
            return;
        }
        $this->table('yfth_purchase_shipment')
            ->setEngine('InnoDB')
            ->setComment('YFTH purchase shipments')
            ->addColumn('purchase_order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase order id'])
            ->addColumn('shipment_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'shipment number'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'shipped', 'comment' => 'pending/shipped/in_transit/received'])
            ->addColumn('quantity_total', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'total shipment quantity'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'admin operator'])
            ->addColumn('shipped_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'shipped time'])
            ->addColumn('logistics_company', 'string', ['limit' => 64, 'default' => '', 'comment' => 'logistics company'])
            ->addColumn('logistics_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'logistics no'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['shipment_no'], ['unique' => true, 'name' => 'uniq_yfth_purchase_shipment_no'])
            ->addIndex(['purchase_order_id', 'status'], ['name' => 'idx_yfth_purchase_shipment_order_status'])
            ->create();
    }

    private function createPurchaseReceipt(): void
    {
        if ($this->hasTable('yfth_purchase_receipt')) {
            return;
        }
        $this->table('yfth_purchase_receipt')
            ->setEngine('InnoDB')
            ->setComment('YFTH purchase receipts')
            ->addColumn('purchase_order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase order id'])
            ->addColumn('shipment_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'shipment id'])
            ->addColumn('receipt_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'receipt number'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'stocked', 'comment' => 'received/checked/stocked'])
            ->addColumn('quantity_total', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'receipt quantity'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user uid'])
            ->addColumn('operator_role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'store operator role'])
            ->addColumn('received_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'received time'])
            ->addColumn('stocked_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'stocked time'])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'default' => '', 'comment' => 'client idempotency key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['receipt_no'], ['unique' => true, 'name' => 'uniq_yfth_purchase_receipt_no'])
            ->addIndex(['purchase_order_id', 'status'], ['name' => 'idx_yfth_purchase_receipt_order_status'])
            ->addIndex(['shipment_id'], ['name' => 'idx_yfth_purchase_receipt_shipment'])
            ->create();
    }

    private function createInventoryAlertRule(): void
    {
        if ($this->hasTable('yfth_inventory_alert_rule')) {
            return;
        }
        $this->table('yfth_inventory_alert_rule')
            ->setEngine('InnoDB')
            ->setComment('YFTH inventory alert rules')
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('location_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'location id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('sku_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('threshold_quantity', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'warning threshold'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['store_id', 'sku_unique'], ['unique' => true, 'name' => 'uniq_yfth_inventory_alert_store_sku'])
            ->addIndex(['status'], ['name' => 'idx_yfth_inventory_alert_status'])
            ->create();
    }

    private function seedMenus(): void
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-cube',
            'menu_name' => 'Supply Chain',
            'module' => 'admin',
            'controller' => 'v1.yfth.SupplyChain',
            'action' => 'index',
            'api_url' => 'yfth/supply_chain/catalog',
            'methods' => 'GET',
            'params' => '',
            'sort' => 4,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/supply-chain',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-supply-chain-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, 'Supply catalog list', 'yfth/supply_chain/catalog', 'GET', 'yfth-supply-catalog-list'),
            $this->apiRow($pageId, 'Supply catalog save', 'yfth/supply_chain/catalog/save', 'POST', 'yfth-supply-catalog-save'),
            $this->apiRow($pageId, 'Supply catalog disable', 'yfth/supply_chain/catalog/disable', 'POST', 'yfth-supply-catalog-disable'),
            $this->apiRow($pageId, 'Product search', 'yfth/supply_chain/product/search', 'GET', 'yfth-supply-product-search'),
            $this->apiRow($pageId, 'Purchase order list', 'yfth/supply_chain/purchase_order', 'GET', 'yfth-purchase-order-list'),
            $this->apiRow($pageId, 'Purchase order detail', 'yfth/supply_chain/purchase_order/<id>', 'GET', 'yfth-purchase-order-detail'),
            $this->apiRow($pageId, 'Purchase order audit', 'yfth/supply_chain/purchase_order/<id>/audit', 'POST', 'yfth-purchase-order-audit'),
            $this->apiRow($pageId, 'Purchase order ship', 'yfth/supply_chain/purchase_order/<id>/ship', 'POST', 'yfth-purchase-order-ship'),
            $this->apiRow($pageId, 'Shipment list', 'yfth/supply_chain/shipment', 'GET', 'yfth-purchase-shipment-list'),
            $this->apiRow($pageId, 'Inventory balance list', 'yfth/supply_chain/inventory', 'GET', 'yfth-inventory-balance-list'),
            $this->apiRow($pageId, 'Inventory ledger list', 'yfth/supply_chain/ledger', 'GET', 'yfth-inventory-ledger-list'),
            $this->apiRow($pageId, 'Inventory alert rule list', 'yfth/supply_chain/alert_rule', 'GET', 'yfth-inventory-alert-rule-list'),
            $this->apiRow($pageId, 'Inventory alert rule save', 'yfth/supply_chain/alert_rule/save', 'POST', 'yfth-inventory-alert-rule-save'),
        ] as $row) {
            $this->upsertMenu($row);
        }
    }

    private function ensureRoot(): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $root = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote('yfth-foundation') . ' LIMIT 1');
        if ($root) {
            return (int)$root['id'];
        }
        return $this->upsertMenu([
            'pid' => 0,
            'icon' => 'md-git-network',
            'menu_name' => 'YFTH',
            'module' => 'admin',
            'controller' => '',
            'action' => '',
            'api_url' => '',
            'methods' => 'GET',
            'params' => '',
            'sort' => 32,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth',
            'path' => '/yfth',
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 1,
            'unique_auth' => 'yfth-foundation',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pid,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.SupplyChain',
            'action' => '',
            'api_url' => $url,
            'methods' => $method,
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$pid,
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => $auth,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
    }

    private function upsertMenu(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field === 'unique_auth') {
                    continue;
                }
                $sets[] = '`' . $field . '` = ' . $this->quote($value);
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return (int)$existing['id'];
        }

        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map(function ($value) {
            return $this->quote($value);
        }, array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}

