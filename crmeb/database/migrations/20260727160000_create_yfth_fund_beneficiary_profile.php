<?php

use think\migration\Migrator;

class CreateYfthFundBeneficiaryProfile extends Migrator
{
    public function up()
    {
        if ($this->hasTable('yfth_fund_beneficiary_profile')) {
            return;
        }
        $this->table('yfth_fund_beneficiary_profile', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('YFTH user-owned withdrawal beneficiary profile')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('payout_method', 'string', ['limit' => 24, 'default' => 'bank'])
            ->addColumn('receiver_name_enc', 'text', ['null' => false])
            ->addColumn('receiver_name_masked', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('receiver_account_enc', 'text', ['null' => false])
            ->addColumn('receiver_account_masked', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('bank_name', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['uid'], ['unique' => true, 'name' => 'uniq_yfth_fund_beneficiary_uid'])
            ->addIndex(['status', 'update_time'], ['name' => 'idx_yfth_fund_beneficiary_status'])
            ->create();
    }

    public function down()
    {
        if ($this->hasTable('yfth_fund_beneficiary_profile')) {
            $this->table('yfth_fund_beneficiary_profile')->drop();
        }
    }
}
