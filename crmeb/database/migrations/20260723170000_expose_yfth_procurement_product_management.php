<?php

use think\migration\Migrator;

class ExposeYfthProcurementProductManagement extends Migrator
{
    private const PAGE_AUTH = 'yfth-procurement-product-index';
    private const IMPORT_AUTH = 'yfth-supply-catalog-import-visible';

    public function up()
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $productRoot = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote('admin-product') . ' AND `is_del`=0 LIMIT 1'
        );
        $supplyPage = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote('yfth-supply-chain-index') . ' AND `is_del`=0 LIMIT 1'
        );
        if (!$productRoot || !$supplyPage) {
            throw new RuntimeException('yfth_procurement_product_menu_parent_missing');
        }

        $this->execute(
            'UPDATE ' . $table . ' SET `menu_name`=' . $this->quote('商城商品管理')
            . ' WHERE `unique_auth`=' . $this->quote('admin-store-storeProuduct-index') . ' AND `is_del`=0'
        );

        $pageId = $this->upsertMenu([
            'pid' => (int)$productRoot['id'],
            'icon' => 'md-cube',
            'menu_name' => '采购商品管理',
            'module' => 'admin',
            'controller' => 'v1.yfth.SupplyChain',
            'action' => 'index',
            'api_url' => '',
            'methods' => 'GET',
            'params' => '',
            'sort' => 2,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/product/procurement_products',
            'path' => (string)$productRoot['id'],
            'auth_type' => 1,
            'header' => (string)($productRoot['header'] ?? 'product'),
            'is_header' => 0,
            'unique_auth' => self::PAGE_AUTH,
            'is_del' => 0,
            'mark' => 'product',
        ]);

        $this->upsertMenu([
            'pid' => (int)$supplyPage['id'],
            'icon' => '',
            'menu_name' => '导入商城上架商品到采购目录',
            'module' => 'admin',
            'controller' => 'v1.yfth.SupplyChain',
            'action' => 'catalogImportVisible',
            'api_url' => 'yfth/supply_chain/catalog/import_visible',
            'methods' => 'POST',
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$supplyPage['id'],
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => self::IMPORT_AUTH,
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        if ($pageId <= 0) {
            throw new RuntimeException('yfth_procurement_product_menu_create_failed');
        }
    }

    public function down()
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $this->execute(
            'DELETE FROM ' . $table . ' WHERE `unique_auth` IN ('
            . $this->quote(self::PAGE_AUTH) . ',' . $this->quote(self::IMPORT_AUTH) . ')'
        );
        $this->execute(
            'UPDATE ' . $table . ' SET `menu_name`=' . $this->quote('商品管理')
            . ' WHERE `unique_auth`=' . $this->quote('admin-store-storeProuduct-index') . ' AND `is_del`=0'
        );
    }

    private function upsertMenu(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchAll(
            'SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth'])
        );
        if (count($existing) > 1) {
            throw new RuntimeException('yfth_procurement_product_menu_duplicate:' . $row['unique_auth']);
        }
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field !== 'unique_auth') {
                    $sets[] = '`' . $field . '`=' . $this->quote($value);
                }
            }
            $id = (int)$existing[0]['id'];
            $this->execute('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE `id`=' . $id);
            return $id;
        }
        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $inserted = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']) . ' LIMIT 1'
        );
        return (int)($inserted['id'] ?? 0);
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
