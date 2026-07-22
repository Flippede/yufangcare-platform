<?php

use think\migration\Migrator;

class RepairYfthMemberPackageVirtualCheckout extends Migrator
{
    private const PRODUCT_BARCODE = 'YFTHPKG9800';

    public function up()
    {
        $productTable = $this->prefixed('store_product');
        $skuTable = $this->prefixed('store_product_attr_value');
        $product = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM `' . $productTable . '` WHERE `bar_code` = ' . $this->quote(self::PRODUCT_BARCODE) . ' LIMIT 1'
        );
        if (!$product) {
            return;
        }

        $productId = (int)$product['id'];
        $this->execute('UPDATE `' . $productTable . '` SET `is_virtual` = 1, `virtual_type` = 1 WHERE `id` = ' . $productId);
        $this->execute('UPDATE `' . $skuTable . '` SET `is_virtual` = 1 WHERE `product_id` = ' . $productId . ' AND `type` = 0');
    }

    public function down()
    {
        // Deliberately keep the package virtual: reverting would restore an invalid checkout contract.
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
