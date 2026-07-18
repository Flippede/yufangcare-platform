<?php

use Phinx\Migration\AbstractMigration;

class AddFranchiseApplicationApprovedStore extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('yfth_franchise_application')) {
            return;
        }
        $table = $this->table('yfth_franchise_application');
        if (!$table->hasColumn('approved_store_id')) {
            $table->addColumn('approved_store_id', 'integer', [
                'signed' => false,
                'default' => 0,
                'after' => 'assigned_uid',
                'comment' => 'store created or selected when headquarters approves',
            ])->addIndex(['approved_store_id'], ['name' => 'idx_yfth_franchise_app_store'])->update();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('yfth_franchise_application')) {
            $table = $this->table('yfth_franchise_application');
            if ($table->hasColumn('approved_store_id')) {
                $table->removeColumn('approved_store_id')->update();
            }
        }
    }
}
