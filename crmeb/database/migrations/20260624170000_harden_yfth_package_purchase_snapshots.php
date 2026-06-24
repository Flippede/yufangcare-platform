<?php

use think\migration\Migrator;

class HardenYfthPackagePurchaseSnapshots extends Migrator
{
    public function up()
    {
        $this->assertNoDuplicateBoundOrders();
        $this->createIntentTable();
        $this->createPurchaseSnapshotTable();
        $this->createBenefitSnapshotTable();
        $this->hardenPurchaseTable();
        $this->hardenIdempotencyTable();
        $this->hardenBenefitPeriodIndexes();
    }

    public function down()
    {
        $this->dropPurchaseHardening();
        $this->dropIdempotencyHardening();
        $this->dropBenefitPeriodIndexes();
        foreach ([
            'yfth_package_purchase_benefit_snapshot',
            'yfth_package_purchase_snapshot',
            'yfth_package_purchase_intent',
        ] as $tableName) {
            if ($this->hasTable($tableName)) {
                $this->table($tableName)->drop()->save();
            }
        }
    }

    private function baseTable(string $name, string $comment)
    {
        return $this->table($name)
            ->setEngine('InnoDB')
            ->setComment($comment)
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at']);
    }

    private function createIntentTable(): void
    {
        if ($this->hasTable('yfth_package_purchase_intent')) {
            return;
        }
        $this->baseTable('yfth_package_purchase_intent', 'YFTH package purchase intents')
            ->addColumn('intent_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'intent number'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service store id'])
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('product_attr_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('agreement_snapshot_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'agreement snapshot id'])
            ->addColumn('expected_pay_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'expected package price'])
            ->addColumn('month_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit month count'])
            ->addColumn('benefit_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'benefit snapshot hash'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'created', 'comment' => 'created/bound/expired/canceled'])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB store_order.id'])
            ->addColumn('order_sn', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB store_order.order_id'])
            ->addColumn('purchase_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package purchase id'])
            ->addColumn('expires_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'intent expiry time'])
            ->addColumn('source', 'string', ['limit' => 32, 'default' => 'mobile', 'comment' => 'source'])
            ->addColumn('validation_snapshot', 'text', ['null' => true, 'comment' => 'validated server snapshot json'])
            ->addColumn('fail_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'last failure reason'])
            ->addIndex(['intent_no'], ['unique' => true, 'name' => 'uniq_yfth_pkg_intent_no'])
            ->addIndex(['uid', 'status'], ['name' => 'idx_yfth_pkg_intent_uid_status'])
            ->addIndex(['order_id'], ['name' => 'idx_yfth_pkg_intent_order_id'])
            ->addIndex(['order_sn'], ['name' => 'idx_yfth_pkg_intent_order_sn'])
            ->create();
    }

    private function createPurchaseSnapshotTable(): void
    {
        if ($this->hasTable('yfth_package_purchase_snapshot')) {
            return;
        }
        $this->baseTable('yfth_package_purchase_snapshot', 'YFTH package purchase relational snapshots')
            ->addColumn('purchase_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase id'])
            ->addColumn('intent_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase intent id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service store id'])
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('rule_version_no', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version number'])
            ->addColumn('package_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'package code'])
            ->addColumn('package_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'package name'])
            ->addColumn('package_title', 'string', ['limit' => 255, 'default' => '', 'comment' => 'package title'])
            ->addColumn('package_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'package type'])
            ->addColumn('package_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'package price'])
            ->addColumn('currency', 'string', ['limit' => 8, 'default' => 'CNY', 'comment' => 'currency'])
            ->addColumn('month_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit month count'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('product_attr_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('product_name', 'string', ['limit' => 255, 'default' => '', 'comment' => 'product name snapshot'])
            ->addColumn('sku_name', 'string', ['limit' => 255, 'default' => '', 'comment' => 'SKU name snapshot'])
            ->addColumn('sku_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'SKU price snapshot'])
            ->addColumn('agreement_snapshot_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'agreement snapshot id'])
            ->addColumn('agreement_title', 'string', ['limit' => 128, 'default' => '', 'comment' => 'agreement title'])
            ->addColumn('agreement_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'agreement content hash'])
            ->addColumn('payment_scene', 'string', ['limit' => 48, 'default' => 'package_5980', 'comment' => 'payment scene'])
            ->addColumn('route_version_no', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'payment route version'])
            ->addColumn('route_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'payment route type'])
            ->addColumn('payment_route_ref', 'string', ['limit' => 128, 'default' => '', 'comment' => 'payment route ref'])
            ->addColumn('sales_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'sales subject id'])
            ->addColumn('payment_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'payment subject id'])
            ->addColumn('fulfillment_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'fulfillment subject id'])
            ->addColumn('invoice_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'invoice subject id'])
            ->addColumn('refund_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'refund subject id'])
            ->addColumn('available_store_ids', 'text', ['null' => true, 'comment' => 'available service store ids json'])
            ->addColumn('validation_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'server validation hash'])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB store_order.id'])
            ->addColumn('order_sn', 'string', ['limit' => 64, 'default' => '', 'comment' => 'CRMEB store_order.order_id'])
            ->addColumn('order_pay_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'order pay price snapshot'])
            ->addColumn('paid_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'paid time'])
            ->addColumn('snapshot_payload', 'text', ['null' => true, 'comment' => 'auxiliary immutable snapshot json'])
            ->addIndex(['purchase_id'], ['unique' => true, 'name' => 'uniq_yfth_pkg_snapshot_purchase'])
            ->addIndex(['intent_id'], ['name' => 'idx_yfth_pkg_snapshot_intent'])
            ->addIndex(['order_id'], ['name' => 'idx_yfth_pkg_snapshot_order_id'])
            ->create();
    }

    private function createBenefitSnapshotTable(): void
    {
        if ($this->hasTable('yfth_package_purchase_benefit_snapshot')) {
            return;
        }
        $this->baseTable('yfth_package_purchase_benefit_snapshot', 'YFTH package benefit relational snapshots')
            ->addColumn('purchase_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase id'])
            ->addColumn('snapshot_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase snapshot id'])
            ->addColumn('intent_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase intent id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('month_no', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'month number'])
            ->addColumn('source_rule_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'monthly rule id'])
            ->addColumn('benefit_template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit template id'])
            ->addColumn('benefit_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'benefit code'])
            ->addColumn('benefit_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'benefit name'])
            ->addColumn('benefit_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'benefit type'])
            ->addColumn('fulfillment_type', 'string', ['limit' => 48, 'default' => '', 'comment' => 'fulfillment type'])
            ->addColumn('unit', 'string', ['limit' => 32, 'default' => '', 'comment' => 'unit'])
            ->addColumn('quantity', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'quantity'])
            ->addColumn('per_limit', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'per fulfillment limit'])
            ->addColumn('available_offset_days', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'available offset days'])
            ->addColumn('expire_offset_days', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire offset days'])
            ->addColumn('service_capability', 'string', ['limit' => 64, 'default' => '', 'comment' => 'required service capability'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'related product id'])
            ->addColumn('product_attr_unique', 'string', ['limit' => 64, 'default' => '', 'comment' => 'related SKU unique'])
            ->addColumn('service_ref', 'string', ['limit' => 128, 'default' => '', 'comment' => 'service ref'])
            ->addColumn('fulfillment_rule', 'text', ['null' => true, 'comment' => 'fulfillment rule json'])
            ->addColumn('open_rule', 'text', ['null' => true, 'comment' => 'open rule json'])
            ->addColumn('expire_rule', 'text', ['null' => true, 'comment' => 'expire rule json'])
            ->addColumn('available_store_ids', 'text', ['null' => true, 'comment' => 'available store ids json'])
            ->addColumn('snapshot_payload', 'text', ['null' => true, 'comment' => 'auxiliary immutable snapshot json'])
            ->addIndex(['purchase_id', 'source_rule_id'], ['unique' => true, 'name' => 'uniq_yfth_pkg_benefit_snapshot_rule'])
            ->addIndex(['snapshot_id', 'month_no'], ['name' => 'idx_yfth_pkg_benefit_snapshot_month'])
            ->addIndex(['benefit_template_id'], ['name' => 'idx_yfth_pkg_benefit_snapshot_tpl'])
            ->create();
    }

    private function hardenPurchaseTable(): void
    {
        if (!$this->hasTable('yfth_package_purchase')) {
            return;
        }
        $table = $this->table('yfth_package_purchase');
        $changed = false;
        foreach ([
            'intent_id' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase intent id']],
            'snapshot_id' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase snapshot id']],
            'order_unique_key' => ['string', ['limit' => 64, 'null' => true, 'default' => null, 'comment' => 'nullable unique bound order id']],
            'order_sn_unique_key' => ['string', ['limit' => 64, 'null' => true, 'default' => null, 'comment' => 'nullable unique bound order sn']],
            'activation_attempt_count' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'activation attempt count']],
            'last_activation_error' => ['string', ['limit' => 255, 'default' => '', 'comment' => 'last activation error']],
            'activation_retry_at' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'next activation retry time']],
        ] as $name => $definition) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $definition[0], $definition[1]);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }

        $purchase = '`' . $this->prefixed('yfth_package_purchase') . '`';
        $this->execute('UPDATE ' . $purchase . ' SET `order_unique_key` = CAST(`order_id` AS CHAR) WHERE `order_id` > 0 AND (`order_unique_key` IS NULL OR `order_unique_key` = \'\')');
        $this->execute('UPDATE ' . $purchase . ' SET `order_sn_unique_key` = `order_sn` WHERE `order_sn` <> \'\' AND (`order_sn_unique_key` IS NULL OR `order_sn_unique_key` = \'\')');

        $table = $this->table('yfth_package_purchase');
        $changed = false;
        foreach ([
            'idx_yfth_pkg_purchase_intent' => ['intent_id'],
            'idx_yfth_pkg_purchase_snapshot' => ['snapshot_id'],
            'uniq_yfth_pkg_purchase_order_key' => ['order_unique_key', true],
            'uniq_yfth_pkg_purchase_order_sn_key' => ['order_sn_unique_key', true],
        ] as $indexName => $columns) {
            $unique = false;
            if (end($columns) === true) {
                array_pop($columns);
                $unique = true;
            }
            if (!$table->hasIndexByName($indexName)) {
                $table->addIndex($columns, ['unique' => $unique, 'name' => $indexName]);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }
    }

    private function hardenIdempotencyTable(): void
    {
        if (!$this->hasTable('yfth_idempotency_record')) {
            return;
        }
        $table = $this->table('yfth_idempotency_record');
        $changed = false;
        foreach ([
            'attempt_count' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'attempt count']],
            'max_attempts' => ['integer', ['signed' => false, 'default' => 5, 'comment' => 'max attempts']],
            'last_error_code' => ['string', ['limit' => 64, 'default' => '', 'comment' => 'last error code']],
            'last_failed_at' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'last failed time']],
            'processing_started_at' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'processing started time']],
            'next_retry_at' => ['integer', ['signed' => false, 'default' => 0, 'comment' => 'next retry time']],
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

    private function hardenBenefitPeriodIndexes(): void
    {
        if (!$this->hasTable('yfth_benefit_period')) {
            return;
        }
        $table = $this->table('yfth_benefit_period');
        $changed = false;
        foreach ([
            'idx_yfth_benefit_period_open_guard' => ['status', 'open_at', 'plan_id', 'package_instance_id'],
            'idx_yfth_benefit_period_expire_guard' => ['status', 'expire_at', 'plan_id', 'package_instance_id'],
        ] as $indexName => $columns) {
            if (!$table->hasIndexByName($indexName)) {
                $table->addIndex($columns, ['name' => $indexName]);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }
    }

    private function dropPurchaseHardening(): void
    {
        if (!$this->hasTable('yfth_package_purchase')) {
            return;
        }
        $table = $this->table('yfth_package_purchase');
        foreach ([
            'uniq_yfth_pkg_purchase_order_key',
            'uniq_yfth_pkg_purchase_order_sn_key',
            'idx_yfth_pkg_purchase_snapshot',
            'idx_yfth_pkg_purchase_intent',
        ] as $indexName) {
            if ($table->hasIndexByName($indexName)) {
                $table->removeIndexByName($indexName)->update();
                $table = $this->table('yfth_package_purchase');
            }
        }
        $table = $this->table('yfth_package_purchase');
        foreach ([
            'activation_retry_at',
            'last_activation_error',
            'activation_attempt_count',
            'order_sn_unique_key',
            'order_unique_key',
            'snapshot_id',
            'intent_id',
        ] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }

    private function dropIdempotencyHardening(): void
    {
        if (!$this->hasTable('yfth_idempotency_record')) {
            return;
        }
        $table = $this->table('yfth_idempotency_record');
        foreach ([
            'next_retry_at',
            'processing_started_at',
            'last_failed_at',
            'last_error_code',
            'max_attempts',
            'attempt_count',
        ] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }

    private function dropBenefitPeriodIndexes(): void
    {
        if (!$this->hasTable('yfth_benefit_period')) {
            return;
        }
        $table = $this->table('yfth_benefit_period');
        foreach ([
            'idx_yfth_benefit_period_expire_guard',
            'idx_yfth_benefit_period_open_guard',
        ] as $indexName) {
            if ($table->hasIndexByName($indexName)) {
                $table->removeIndexByName($indexName)->update();
                $table = $this->table('yfth_benefit_period');
            }
        }
    }

    private function assertNoDuplicateBoundOrders(): void
    {
        if (!$this->hasTable('yfth_package_purchase')) {
            return;
        }
        $table = '`' . $this->prefixed('yfth_package_purchase') . '`';
        $duplicateOrderIds = $this->fetchAll('SELECT `order_id`, GROUP_CONCAT(`id` ORDER BY `id`) AS ids, COUNT(*) AS cnt FROM ' . $table . ' WHERE `order_id` > 0 GROUP BY `order_id` HAVING cnt > 1');
        $duplicateOrderSns = $this->fetchAll('SELECT `order_sn`, GROUP_CONCAT(`id` ORDER BY `id`) AS ids, COUNT(*) AS cnt FROM ' . $table . ' WHERE `order_sn` <> \'\' GROUP BY `order_sn` HAVING cnt > 1');
        if (!$duplicateOrderIds && !$duplicateOrderSns) {
            return;
        }
        $parts = [];
        foreach ($duplicateOrderIds as $row) {
            $parts[] = 'order_id=' . $row['order_id'] . ' ids=' . $row['ids'];
        }
        foreach ($duplicateOrderSns as $row) {
            $parts[] = 'order_sn=' . $row['order_sn'] . ' ids=' . $row['ids'];
        }
        throw new RuntimeException('Duplicate YFTH package purchases detected before unique indexes. Resolve manually without deleting production data blindly: ' . implode('; ', $parts));
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
