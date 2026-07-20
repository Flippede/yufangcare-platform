<?php

use think\migration\Migrator;

/**
 * Makes automatic commission execution independent from the legacy candidate
 * ledger and gives refunds/package sequences durable idempotency keys.
 */
class HardenYfthAutomaticCommissionExecutionV2 extends Migrator
{
    private const TABLES = [
        'yfth_commission_sequence_counter',
        'yfth_commission_refund_reversal',
        'yfth_commission_order_source',
    ];

    public function up()
    {
        if (!$this->hasTable('yfth_commission_accrual') || !$this->hasTable('yfth_commission_ledger')) {
            throw new RuntimeException('yfth_automatic_commission_v1_required');
        }
        $this->addAccrualFields();
        $this->createSequenceCounter();
        $this->createRefundReversal();
        $this->createOrderSource();
        $this->assertComplete();
    }

    public function down()
    {
        foreach (array_reverse(self::TABLES) as $name) {
            if ($this->hasTable($name)) $this->table($name)->drop();
        }
        if ($this->hasTable('yfth_commission_accrual')) {
            $table = $this->table('yfth_commission_accrual');
            if ($this->hasIndex('yfth_commission_accrual', 'uniq_yfth_commission_package_sequence')) {
                $table->removeIndexByName('uniq_yfth_commission_package_sequence')->update();
            }
            foreach (['package_sequence_key', 'package_sequence_no'] as $column) {
                if ($table->hasColumn($column)) $table->removeColumn($column)->update();
            }
        }
    }

    private function addAccrualFields(): void
    {
        $table = $this->table('yfth_commission_accrual');
        if (!$table->hasColumn('package_sequence_no')) {
            $table->addColumn('package_sequence_no', 'integer', [
                'signed' => false, 'default' => 0, 'after' => 'rule_version_id',
                'comment' => 'frozen 15/25/60 package sequence',
            ])->update();
        }
        if (!$table->hasColumn('package_sequence_key')) {
            $table->addColumn('package_sequence_key', 'string', [
                'limit' => 64, 'null' => true, 'default' => null, 'after' => 'package_sequence_no',
                'comment' => 'unique referrer and package sequence when applicable',
            ])->update();
        }
        if (!$this->hasIndex('yfth_commission_accrual', 'uniq_yfth_commission_package_sequence')) {
            $this->table('yfth_commission_accrual')->addIndex(['package_sequence_key'], [
                'unique' => true, 'name' => 'uniq_yfth_commission_package_sequence',
            ])->update();
        }
    }

    private function createSequenceCounter(): void
    {
        if ($this->hasTable('yfth_commission_sequence_counter')) return;
        $this->table('yfth_commission_sequence_counter', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Locked package reward sequence counter per C1')
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('last_package_sequence_no', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['referrer_uid'], ['unique' => true, 'name' => 'uniq_yfth_commission_sequence_referrer'])
            ->create();
    }

    private function createRefundReversal(): void
    {
        if ($this->hasTable('yfth_commission_refund_reversal')) return;
        $this->table('yfth_commission_refund_reversal', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Exact per-refund per-order-item commission reversal facts')
            ->addColumn('refund_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('order_item_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('accrual_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('refund_quantity', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('base_reversal_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('snapshot_json', 'text', ['null' => false])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['refund_id', 'order_item_id', 'accrual_id'], [
                'unique' => true, 'name' => 'uniq_yfth_commission_refund_item_accrual',
            ])
            ->addIndex(['accrual_id', 'id'], ['name' => 'idx_yfth_commission_refund_accrual'])
            ->addIndex(['order_id', 'id'], ['name' => 'idx_yfth_commission_refund_order'])
            ->create();
    }

    private function createOrderSource(): void
    {
        if ($this->hasTable('yfth_commission_order_source')) return;
        $this->table('yfth_commission_order_source', ['signed' => false])
            ->setEngine('InnoDB')->setComment('Explicit YFTH order source and CRMEB brokerage exclusion guard')
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 32, 'default' => 'normal_mall'])
            ->addColumn('legacy_brokerage_excluded', 'boolean', ['signed' => false, 'default' => 1])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['order_id'], ['unique' => true, 'name' => 'uniq_yfth_commission_order_source'])
            ->addIndex(['source_type', 'legacy_brokerage_excluded'], ['name' => 'idx_yfth_commission_order_source_type'])
            ->create();
    }

    private function assertComplete(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) throw new RuntimeException('yfth_automatic_commission_v2_forward_repair_required:' . $table);
        }
        $accrual = $this->table('yfth_commission_accrual');
        foreach (['package_sequence_no', 'package_sequence_key'] as $column) {
            if (!$accrual->hasColumn($column)) throw new RuntimeException('yfth_automatic_commission_v2_forward_repair_required:' . $column);
        }
        foreach ([
            ['yfth_commission_accrual', 'uniq_yfth_commission_package_sequence'],
            ['yfth_commission_sequence_counter', 'uniq_yfth_commission_sequence_referrer'],
            ['yfth_commission_refund_reversal', 'uniq_yfth_commission_refund_item_accrual'],
            ['yfth_commission_order_source', 'uniq_yfth_commission_order_source'],
        ] as $index) {
            if (!$this->hasIndex($index[0], $index[1])) {
                throw new RuntimeException('yfth_automatic_commission_v2_forward_repair_required:' . $index[1]);
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return $this->getAdapter()->hasIndexByName($table, $name);
    }
}
