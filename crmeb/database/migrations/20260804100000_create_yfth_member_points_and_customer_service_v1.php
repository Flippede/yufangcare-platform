<?php

use think\migration\Migrator;

class CreateYfthMemberPointsAndCustomerServiceV1 extends Migrator
{
    public function up()
    {
        if (!$this->hasTable('yfth_member_points_account')) {
            $this->table('yfth_member_points_account', ['signed' => false])
                ->setEngine('InnoDB')->setComment('Authoritative C-end referral points account')
                ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('balance_point_cent', 'biginteger', ['default' => 0])
                ->addColumn('issued_integral', 'biginteger', ['default' => 0])
                ->addColumn('integral_debt', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('legacy_converted_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('version', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['uid'], ['unique' => true, 'name' => 'uniq_yfth_member_points_uid'])
                ->create();
        }

        if (!$this->hasTable('yfth_member_points_ledger')) {
            $this->table('yfth_member_points_ledger', ['signed' => false])
                ->setEngine('InnoDB')->setComment('Immutable C-end referral points ledger')
                ->addColumn('ledger_no', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('direction', 'string', ['limit' => 12, 'default' => 'credit'])
                ->addColumn('point_cent', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('balance_before_point_cent', 'biginteger', ['default' => 0])
                ->addColumn('balance_after_point_cent', 'biginteger', ['default' => 0])
                ->addColumn('integral_delta', 'biginteger', ['default' => 0])
                ->addColumn('integral_debt_after', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('source_type', 'string', ['limit' => 40, 'default' => ''])
                ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('source_order_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('ratio_bps', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('source_unique_key', 'char', ['limit' => 64, 'default' => ''])
                ->addColumn('snapshot_json', 'text', ['null' => false])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['ledger_no'], ['unique' => true, 'name' => 'uniq_yfth_member_points_ledger_no'])
                ->addIndex(['source_unique_key'], ['unique' => true, 'name' => 'uniq_yfth_member_points_source'])
                ->addIndex(['uid', 'id'], ['name' => 'idx_yfth_member_points_uid'])
                ->create();
        }

        if (!$this->hasTable('yfth_member_points_config')) {
            $this->table('yfth_member_points_config', ['signed' => false])
                ->setEngine('InnoDB')->setComment('C-end points redemption policy')
                ->addColumn('config_key', 'string', ['limit' => 32, 'default' => 'default'])
                ->addColumn('enabled', 'boolean', ['signed' => false, 'default' => 1])
                ->addColumn('point_yuan_cent_per_point', 'integer', ['signed' => false, 'default' => 100])
                ->addColumn('max_deduction_bps', 'integer', ['signed' => false, 'default' => 9900])
                ->addColumn('min_cash_cent', 'integer', ['signed' => false, 'default' => 10])
                ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['config_key'], ['unique' => true, 'name' => 'uniq_yfth_member_points_config'])
                ->create();
            $this->table('yfth_member_points_config')->insert([
                'config_key' => 'default', 'enabled' => 1, 'point_yuan_cent_per_point' => 100,
                'max_deduction_bps' => 9900, 'min_cash_cent' => 10,
                'operator_uid' => 0, 'add_time' => time(), 'update_time' => time(),
            ])->saveData();
        }

        if (!$this->hasTable('yfth_member_points_product_rule')) {
            $this->table('yfth_member_points_product_rule', ['signed' => false])
                ->setEngine('InnoDB')->setComment('Per-product points eligibility override')
                ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('enabled', 'boolean', ['signed' => false, 'default' => 1])
                ->addColumn('max_deduction_bps', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['product_id'], ['unique' => true, 'name' => 'uniq_yfth_member_points_product'])
                ->create();
        }

        if (!$this->hasTable('yfth_customer_service_store_binding')) {
            $this->table('yfth_customer_service_store_binding', ['signed' => false])
                ->setEngine('InnoDB')->setComment('One current customer service owner per B-end store')
                ->addColumn('customer_service_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('status', 'string', ['limit' => 16, 'default' => 'active'])
                ->addColumn('active_store_key', 'integer', ['signed' => false, 'null' => true, 'default' => null])
                ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('ended_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['active_store_key'], ['unique' => true, 'name' => 'uniq_yfth_customer_service_active_store'])
                ->addIndex(['customer_service_uid', 'status'], ['name' => 'idx_yfth_customer_service_uid'])
                ->create();
        }
    }

    public function down()
    {
        foreach (['yfth_customer_service_store_binding', 'yfth_member_points_product_rule', 'yfth_member_points_config', 'yfth_member_points_ledger', 'yfth_member_points_account'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }
}
