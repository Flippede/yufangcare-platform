<?php

use think\migration\Migrator;

class UnifyYfthProcurementWithStoreOrders extends Migrator
{
    public function up()
    {
        $this->createNativeOrderSidecar();
        $this->createCatalogSkuPrices();
        $this->extendProfitSnapshot();
        $this->extendProfitLedger();
        $this->repairMenus();
    }

    public function down()
    {
        $this->restoreMenus();
        $this->restoreProfitLedger();
        $this->restoreProfitSnapshot();
        if ($this->hasTable('yfth_native_procurement_order')) {
            $this->table('yfth_native_procurement_order')->drop();
        }
        if ($this->hasTable('yfth_supply_catalog_sku')) {
            $this->table('yfth_supply_catalog_sku')->drop();
        }
    }

    private function restoreProfitSnapshot(): void
    {
        if (!$this->hasTable('yfth_procurement_profit_snapshot')) {
            return;
        }
        $name = '`' . $this->prefixed('yfth_procurement_profit_snapshot') . '`';
        $nativeCount = (int)($this->getAdapter()->fetchRow(
            'SELECT COUNT(*) AS `count` FROM ' . $name
            . ' WHERE `source_type`=' . $this->quote('store_order')
        )['count'] ?? 0);
        if ($nativeCount > 0) {
            throw new RuntimeException('yfth_native_procurement_snapshot_must_be_empty_before_rollback');
        }

        $table = $this->table('yfth_procurement_profit_snapshot');
        if ($table->hasIndex(['source_type', 'source_id'])) {
            $table->removeIndex(['source_type', 'source_id']);
        }
        if ($table->hasIndex(['store_order_id'])) {
            $table->removeIndex(['store_order_id']);
        }
        if (!$table->hasIndex(['purchase_order_id'])) {
            $table->addIndex(['purchase_order_id'], [
                'unique' => true,
                'name' => 'uniq_yfth_procurement_snapshot_order',
            ]);
        }
        foreach (['store_order_id', 'source_id', 'source_type'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }

    private function restoreProfitLedger(): void
    {
        if (!$this->hasTable('yfth_procurement_profit_ledger')) {
            return;
        }
        $name = '`' . $this->prefixed('yfth_procurement_profit_ledger') . '`';
        $nativeCount = (int)($this->getAdapter()->fetchRow(
            'SELECT COUNT(*) AS `count` FROM ' . $name
            . ' WHERE `source_type`=' . $this->quote('store_order')
        )['count'] ?? 0);
        if ($nativeCount > 0) {
            throw new RuntimeException('yfth_native_procurement_ledger_must_be_empty_before_rollback');
        }

        $table = $this->table('yfth_procurement_profit_ledger');
        foreach (['store_order_id', 'source_id', 'source_type'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }

    private function restoreMenus(): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $yfthRoot = $this->menu('yfth-foundation');
        $supplyPage = $this->menu('yfth-supply-chain-index');
        $userRole = $this->menu('yfth-user-role-management-index');
        $procurement = $this->menu('yfth-procurement-product-index');
        if (!$yfthRoot || !$supplyPage || !$userRole || !$procurement) {
            throw new RuntimeException('yfth_procurement_menu_rollback_parent_missing');
        }

        $this->execute(
            'UPDATE ' . $table . ' SET `pid`=' . (int)$yfthRoot['id']
            . ',`menu_path`=' . $this->quote('/yfth/user-role')
            . ',`path`=' . $this->quote((string)$yfthRoot['id'])
            . ',`header`=' . $this->quote('yfth')
            . ',`mark`=' . $this->quote('yfth')
            . ' WHERE `id`=' . (int)$userRole['id']
        );
        $this->execute(
            'UPDATE ' . $table . ' SET `is_show`=1,`is_show_path`=1'
            . ' WHERE `id`=' . (int)$supplyPage['id']
        );
        $this->execute(
            'UPDATE ' . $table . ' SET `pid`=' . (int)$supplyPage['id']
            . ',`path`=' . $this->quote((string)$supplyPage['id'])
            . ',`header`=' . $this->quote('yfth')
            . ',`mark`=' . $this->quote('yfth')
            . ' WHERE `unique_auth`=' . $this->quote('yfth-supply-catalog-import-visible')
        );
    }

    private function createNativeOrderSidecar(): void
    {
        if ($this->hasTable('yfth_native_procurement_order')) {
            return;
        }
        $this->table('yfth_native_procurement_order', [
            'engine' => 'InnoDB',
            'comment' => 'YFTH procurement metadata for native CRMEB store orders',
        ])
            ->addColumn('store_order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('order_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'created'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['store_order_id'], ['unique' => true, 'name' => 'uniq_yfth_native_procurement_order'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_native_procurement_store'])
            ->create();
    }

    private function createCatalogSkuPrices(): void
    {
        if ($this->hasTable('yfth_supply_catalog_sku')) {
            return;
        }
        $this->table('yfth_supply_catalog_sku', [
            'engine' => 'InnoDB',
            'comment' => 'Procurement price by catalog SKU',
        ])
            ->addColumn('catalog_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('sku_unique', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('purchase_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['catalog_id', 'sku_unique'], ['unique' => true, 'name' => 'uniq_yfth_catalog_sku'])
            ->addIndex(['product_id', 'sku_unique'], ['name' => 'idx_yfth_catalog_product_sku'])
            ->create();
    }

    private function extendProfitSnapshot(): void
    {
        if (!$this->hasTable('yfth_procurement_profit_snapshot')) {
            return;
        }
        $table = $this->table('yfth_procurement_profit_snapshot');
        if (!$table->hasColumn('source_type')) {
            $table->addColumn('source_type', 'string', ['limit' => 24, 'default' => 'legacy_purchase_order']);
        }
        if (!$table->hasColumn('source_id')) {
            $table->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0]);
        }
        if (!$table->hasColumn('store_order_id')) {
            $table->addColumn('store_order_id', 'integer', ['signed' => false, 'default' => 0]);
        }
        $table->update();

        $name = '`' . $this->prefixed('yfth_procurement_profit_snapshot') . '`';
        $this->execute(
            'UPDATE ' . $name . ' SET `source_type`=' . $this->quote('legacy_purchase_order')
            . ',`source_id`=`purchase_order_id` WHERE `source_id`=0'
        );

        $table = $this->table('yfth_procurement_profit_snapshot');
        if ($table->hasIndex(['purchase_order_id'])) {
            $table->removeIndex(['purchase_order_id']);
        }
        if (!$table->hasIndex(['source_type', 'source_id'])) {
            $table->addIndex(['source_type', 'source_id'], [
                'unique' => true,
                'name' => 'uniq_yfth_procurement_snapshot_source',
            ]);
        }
        if (!$table->hasIndex(['store_order_id'])) {
            $table->addIndex(['store_order_id'], ['name' => 'idx_yfth_procurement_snapshot_store_order']);
        }
        $table->update();
    }

    private function extendProfitLedger(): void
    {
        if (!$this->hasTable('yfth_procurement_profit_ledger')) {
            return;
        }
        $table = $this->table('yfth_procurement_profit_ledger');
        if (!$table->hasColumn('source_type')) {
            $table->addColumn('source_type', 'string', ['limit' => 24, 'default' => 'legacy_purchase_order']);
        }
        if (!$table->hasColumn('source_id')) {
            $table->addColumn('source_id', 'integer', ['signed' => false, 'default' => 0]);
        }
        if (!$table->hasColumn('store_order_id')) {
            $table->addColumn('store_order_id', 'integer', ['signed' => false, 'default' => 0]);
        }
        $table->update();
        $name = '`' . $this->prefixed('yfth_procurement_profit_ledger') . '`';
        $this->execute(
            'UPDATE ' . $name . ' SET `source_type`=' . $this->quote('legacy_purchase_order')
            . ',`source_id`=`purchase_order_id` WHERE `source_id`=0'
        );
    }

    private function repairMenus(): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $userRoot = $this->menu('admin-user');
        $userRole = $this->menu('yfth-user-role-management-index');
        $procurement = $this->menu('yfth-procurement-product-index');
        if (!$userRoot || !$userRole || !$procurement) {
            throw new RuntimeException('yfth_procurement_menu_parent_missing');
        }

        $this->execute(
            'UPDATE ' . $table . ' SET `pid`=' . (int)$userRoot['id']
            . ',`menu_path`=' . $this->quote('/user/yfth-user-role')
            . ',`path`=' . $this->quote((string)$userRoot['id'])
            . ',`header`=' . $this->quote((string)($userRoot['header'] ?? 'user'))
            . ',`mark`=' . $this->quote('user')
            . ',`menu_name`=' . $this->quote('用户经营身份')
            . ' WHERE `id`=' . (int)$userRole['id']
        );
        $this->execute(
            'UPDATE ' . $table . ' SET `menu_name`=' . $this->quote('采购商品管理')
            . ',`is_show`=1 WHERE `id`=' . (int)$procurement['id']
        );
        $this->execute(
            'UPDATE ' . $table . ' SET `is_show`=0,`is_show_path`=0'
            . ' WHERE `unique_auth`=' . $this->quote('yfth-supply-chain-index')
        );
        $this->execute(
            'UPDATE ' . $table . ' SET `pid`=' . (int)$procurement['id']
            . ',`path`=' . $this->quote((string)$procurement['id'])
            . ',`header`=' . $this->quote((string)($procurement['header'] ?? 'product'))
            . ',`mark`=' . $this->quote('product')
            . ' WHERE `unique_auth`=' . $this->quote('yfth-supply-catalog-import-visible')
        );
    }

    private function menu(string $auth): array
    {
        $row = $this->getAdapter()->fetchRow(
            'SELECT * FROM `' . $this->prefixed('system_menus') . '`'
            . ' WHERE `unique_auth`=' . $this->quote($auth) . ' AND `is_del`=0 LIMIT 1'
        );
        return is_array($row) ? $row : [];
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return 'NULL';
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
