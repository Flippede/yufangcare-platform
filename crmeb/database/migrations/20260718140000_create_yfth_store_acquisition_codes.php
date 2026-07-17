<?php

use think\migration\Migrator;

class CreateYfthStoreAcquisitionCodes extends Migrator
{
    public function up()
    {
        if (!$this->hasTable('yfth_store_acquisition_code')) {
            $this->table('yfth_store_acquisition_code', ['signed' => false])
                ->setEngine('InnoDB')
                ->setComment('YFTH store staff acquisition QR codes')
                ->addColumn('code_no', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('token_hash', 'char', ['limit' => 64, 'default' => ''])
                ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('issuer_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('issuer_role_code', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
                ->addColumn('issued_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('expires_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('invalidated_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('active_key', 'string', ['limit' => 96, 'null' => true, 'default' => null])
                ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['code_no'], ['unique' => true, 'name' => 'uniq_yfth_acquisition_code_no'])
                ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_yfth_acquisition_token_hash'])
                ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_acquisition_active'])
                ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_acquisition_store_status'])
                ->create();
        }

        if (!$this->hasTable('yfth_store_acquisition_acceptance')) {
            $this->table('yfth_store_acquisition_acceptance', ['signed' => false])
                ->setEngine('InnoDB')
                ->setComment('YFTH store acquisition acceptance facts')
                ->addColumn('acceptance_no', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('code_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('customer_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('issuer_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('issuer_role_code', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('attribution_current_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('customer_relation_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'accepted'])
                ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
                ->addColumn('accepted_at', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['acceptance_no'], ['unique' => true, 'name' => 'uniq_yfth_acquisition_acceptance_no'])
                ->addIndex(['customer_uid'], ['unique' => true, 'name' => 'uniq_yfth_acquisition_customer'])
                ->addIndex(['request_id'], ['unique' => true, 'name' => 'uniq_yfth_acquisition_request'])
                ->addIndex(['store_id', 'accepted_at'], ['name' => 'idx_yfth_acquisition_acceptance_store'])
                ->create();
        }
    }

    public function down()
    {
        if ($this->hasTable('yfth_store_acquisition_acceptance')) {
            $this->table('yfth_store_acquisition_acceptance')->drop();
        }
        if ($this->hasTable('yfth_store_acquisition_code')) {
            $this->table('yfth_store_acquisition_code')->drop();
        }
    }
}
