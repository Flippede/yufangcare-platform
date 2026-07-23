<?php

use Phinx\Migration\AbstractMigration;

final class ExtendYfthPurchaseOrderCheckout extends AbstractMigration
{
    private const COLUMNS = [
        'address_id',
        'real_name',
        'user_phone',
        'user_address',
        'freight_price',
        'pay_type',
        'pay_status',
        'buyer_mark',
    ];

    public function up(): void
    {
        $table = $this->table('yfth_purchase_order');
        if (!$table->hasColumn('address_id')) {
            $table->addColumn('address_id', 'integer', ['signed' => false, 'default' => 0, 'after' => 'operator_role_code']);
        }
        if (!$table->hasColumn('real_name')) {
            $table->addColumn('real_name', 'string', ['limit' => 64, 'default' => '', 'after' => 'address_id']);
        }
        if (!$table->hasColumn('user_phone')) {
            $table->addColumn('user_phone', 'string', ['limit' => 32, 'default' => '', 'after' => 'real_name']);
        }
        if (!$table->hasColumn('user_address')) {
            $table->addColumn('user_address', 'string', ['limit' => 500, 'default' => '', 'after' => 'user_phone']);
        }
        if (!$table->hasColumn('freight_price')) {
            $table->addColumn('freight_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'after' => 'user_address']);
        }
        if (!$table->hasColumn('pay_type')) {
            $table->addColumn('pay_type', 'string', ['limit' => 24, 'default' => 'offline', 'after' => 'freight_price']);
        }
        if (!$table->hasColumn('pay_status')) {
            $table->addColumn('pay_status', 'string', ['limit' => 24, 'default' => 'pending', 'after' => 'pay_type']);
        }
        if (!$table->hasColumn('buyer_mark')) {
            $table->addColumn('buyer_mark', 'string', ['limit' => 255, 'default' => '', 'after' => 'pay_status']);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('yfth_purchase_order');
        foreach (array_reverse(self::COLUMNS) as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }
}
