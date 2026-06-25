<?php

use think\migration\Migrator;

class SerializeYfthPackageIntentOrderingAndManualRecovery extends Migrator
{
    public function up()
    {
        $this->hardenIntentTable();
        $this->hardenPurchaseManualRecoveryFields();
    }

    public function down()
    {
        $this->dropPurchaseManualRecoveryFields();
        $this->dropIntentHardening();
    }

    private function hardenIntentTable(): void
    {
        if (!$this->hasTable('yfth_package_purchase_intent')) {
            return;
        }

        $table = $this->table('yfth_package_purchase_intent');
        $changed = false;
        foreach ([
            'creating_started_at' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'order creation claim start time']],
            'creating_request_id' => ['string', ['limit' => 64, 'default' => '', 'comment' => 'order creation claim request id']],
            'bound_order_id' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'bound CRMEB store_order.id']],
            'bound_order_sn' => ['string', ['limit' => 64, 'default' => '', 'comment' => 'bound CRMEB store_order.order_id']],
            'last_error_code' => ['string', ['limit' => 64, 'default' => '', 'comment' => 'last safe error code']],
            'last_error_message' => ['string', ['limit' => 255, 'default' => '', 'comment' => 'last safe error message']],
            'retry_count' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'order creation retry count']],
            'orphan_order_id' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'unbound order needing compensation']],
            'orphan_order_sn' => ['string', ['limit' => 64, 'default' => '', 'comment' => 'unbound order sn needing compensation']],
            'orphan_close_status' => ['string', ['limit' => 32, 'default' => '', 'comment' => 'orphan close status']],
            'orphan_close_error' => ['string', ['limit' => 255, 'default' => '', 'comment' => 'orphan close error']],
        ] as $name => $definition) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $definition[0], $definition[1]);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }

        $intent = '`' . $this->prefixed('yfth_package_purchase_intent') . '`';
        $this->execute('UPDATE ' . $intent . ' SET `bound_order_id` = `order_id` WHERE `order_id` > 0 AND `bound_order_id` = 0');
        $this->execute('UPDATE ' . $intent . ' SET `bound_order_sn` = `order_sn` WHERE `order_sn` <> \'\' AND `bound_order_sn` = \'\'');
        $this->execute('UPDATE ' . $intent . ' SET `status` = \'cancelled\' WHERE `status` = \'canceled\'');

        $table = $this->table('yfth_package_purchase_intent');
        $changed = false;
        foreach ([
            'idx_yfth_pkg_intent_claim' => ['uid', 'intent_no', 'status', 'purchase_id', 'bound_order_id'],
            'idx_yfth_pkg_intent_bound_order' => ['bound_order_id'],
            'idx_yfth_pkg_intent_orphan' => ['orphan_close_status', 'orphan_order_id'],
        ] as $indexName => $columns) {
            if (!$this->tableHasIndex('yfth_package_purchase_intent', $indexName)) {
                $table->addIndex($columns, ['name' => $indexName]);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }
    }

    private function hardenPurchaseManualRecoveryFields(): void
    {
        if (!$this->hasTable('yfth_package_purchase')) {
            return;
        }

        $table = $this->table('yfth_package_purchase');
        $changed = false;
        foreach ([
            'manual_retry_count' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'manual activation retry count']],
            'last_manual_retry_at' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'last manual retry time']],
            'last_manual_retry_operator' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'last manual retry admin id']],
            'manual_retry_reason' => ['string', ['limit' => 255, 'default' => '', 'comment' => 'last manual retry reason']],
            'manual_retry_request_id' => ['string', ['limit' => 64, 'default' => '', 'comment' => 'last manual retry request id']],
            'manual_retry_result' => ['string', ['limit' => 32, 'default' => '', 'comment' => 'last manual retry result']],
        ] as $name => $definition) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $definition[0], $definition[1]);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }
    }

    private function dropIntentHardening(): void
    {
        if (!$this->hasTable('yfth_package_purchase_intent')) {
            return;
        }
        $table = $this->table('yfth_package_purchase_intent');
        foreach ([
            'idx_yfth_pkg_intent_orphan',
            'idx_yfth_pkg_intent_bound_order',
            'idx_yfth_pkg_intent_claim',
        ] as $indexName) {
            if ($this->tableHasIndex('yfth_package_purchase_intent', $indexName)) {
                $table->removeIndexByName($indexName)->update();
                $table = $this->table('yfth_package_purchase_intent');
            }
        }
        foreach ([
            'orphan_close_error',
            'orphan_close_status',
            'orphan_order_sn',
            'orphan_order_id',
            'retry_count',
            'last_error_message',
            'last_error_code',
            'bound_order_sn',
            'bound_order_id',
            'creating_request_id',
            'creating_started_at',
        ] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }

    private function dropPurchaseManualRecoveryFields(): void
    {
        if (!$this->hasTable('yfth_package_purchase')) {
            return;
        }
        $table = $this->table('yfth_package_purchase');
        foreach ([
            'manual_retry_result',
            'manual_retry_request_id',
            'manual_retry_reason',
            'last_manual_retry_operator',
            'last_manual_retry_at',
            'manual_retry_count',
        ] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function tableHasIndex(string $table, string $indexName): bool
    {
        return $this->getAdapter()->hasIndexByName($table, $indexName);
    }
}
