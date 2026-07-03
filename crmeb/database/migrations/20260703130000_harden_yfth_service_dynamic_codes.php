<?php

use think\migration\Migrator;

class HardenYfthServiceDynamicCodes extends Migrator
{
    public function up()
    {
        $codeTable = $this->table('yfth_service_dynamic_code');
        if (!$codeTable->hasColumn('digital_active_key')) {
            $codeTable
                ->addColumn('digital_active_key', 'string', [
                    'limit' => 191,
                    'null' => true,
                    'default' => null,
                    'after' => 'active_key',
                    'comment' => 'one active digital code per store',
                ])
                ->addIndex(['digital_active_key'], [
                    'unique' => true,
                    'name' => 'uniq_yfth_svc_code_store_digital_active',
                ])
                ->addIndex(['store_id', 'digital_code_hash', 'status'], [
                    'name' => 'idx_yfth_svc_code_store_digital',
                ])
                ->update();
        }

        $recordTable = $this->table('yfth_service_writeoff_record');
        if (!$recordTable->hasColumn('reason')) {
            $recordTable
                ->addColumn('reason', 'string', [
                    'limit' => 200,
                    'default' => '',
                    'after' => 'status',
                    'comment' => 'writeoff reason',
                ])
                ->update();
        }
    }

    public function down()
    {
        $recordTable = $this->table('yfth_service_writeoff_record');
        if ($recordTable->hasColumn('reason')) {
            $recordTable
                ->removeColumn('reason')
                ->update();
        }

        $codeTable = $this->table('yfth_service_dynamic_code');
        if ($codeTable->hasColumn('digital_active_key')) {
            $codeTable
                ->removeIndexByName('uniq_yfth_svc_code_store_digital_active')
                ->removeIndexByName('idx_yfth_svc_code_store_digital')
                ->removeColumn('digital_active_key')
                ->update();
        }
    }
}
