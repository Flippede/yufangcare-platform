<?php

use think\migration\Migrator;

class CreateYfthAccountClosureV2 extends Migrator
{
    public function up()
    {
        if (!$this->hasTable('yfth_account_closure_subject')) {
            $this->table('yfth_account_closure_subject')
                ->setEngine('InnoDB')
                ->setComment('Irreversible YFTH account closure subjects')
                ->addColumn('closure_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'random closure identifier'])
                ->addColumn('subject_uid', 'biginteger', ['signed' => false, 'default' => 0, 'comment' => 'non-user anonymous history subject'])
                ->addColumn('former_uid_digest', 'string', ['limit' => 64, 'default' => '', 'comment' => 'one-way digest for idempotency'])
                ->addColumn('source', 'string', ['limit' => 32, 'default' => 'customer_self'])
                ->addColumn('operator_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('operator_role', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'closed'])
                ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('statistics', 'text', ['null' => true, 'comment' => 'non-identifying processing counts'])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['closure_no'], ['unique' => true, 'name' => 'uniq_yfth_account_closure_no'])
                ->addIndex(['subject_uid'], ['unique' => true, 'name' => 'uniq_yfth_account_closure_subject'])
                ->addIndex(['former_uid_digest'], ['unique' => true, 'name' => 'uniq_yfth_account_closure_digest'])
                ->addIndex(['status', 'add_time'], ['name' => 'idx_yfth_account_closure_status'])
                ->create();
        }

        if (!$this->hasTable('yfth_account_closure_history_link')) {
            $this->table('yfth_account_closure_history_link')
                ->setEngine('InnoDB')
                ->setComment('Compliance-only links from anonymous closure subjects to retained history')
                ->addColumn('closure_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('closure_no', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('business_domain', 'string', ['limit' => 48, 'default' => ''])
                ->addColumn('table_name', 'string', ['limit' => 96, 'default' => ''])
                ->addColumn('record_id', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('relation_field', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['closure_id', 'business_domain'], ['name' => 'idx_yfth_closure_history_domain'])
                ->addIndex(['table_name', 'record_id'], ['name' => 'idx_yfth_closure_history_record'])
                ->create();
        }
    }

    public function down()
    {
        if ($this->hasTable('yfth_account_closure_history_link')) {
            $this->table('yfth_account_closure_history_link')->drop();
        }
        if ($this->hasTable('yfth_account_closure_subject')) {
            $this->table('yfth_account_closure_subject')->drop();
        }
    }
}
