<?php

use think\migration\Migrator;

class AddYfthFranchiseFollowVisibility extends Migrator
{
    public function up()
    {
        if (!$this->hasTable('yfth_franchise_follow_record')) {
            return;
        }

        $table = $this->table('yfth_franchise_follow_record');
        if (!$table->hasColumn('visible_type')) {
            $table
                ->addColumn('visible_type', 'string', [
                    'limit' => 24,
                    'default' => 'internal',
                    'after' => 'content',
                    'comment' => 'public/internal visibility',
                ])
                ->addIndex(['application_id', 'visible_type', 'create_time'], [
                    'name' => 'idx_yfth_franchise_follow_visible_time',
                ])
                ->update();
        }
    }

    public function down()
    {
        if (!$this->hasTable('yfth_franchise_follow_record')) {
            return;
        }

        $table = $this->table('yfth_franchise_follow_record');
        if ($table->hasColumn('visible_type')) {
            if ($this->getAdapter()->hasIndexByName('yfth_franchise_follow_record', 'idx_yfth_franchise_follow_visible_time')) {
                $table->removeIndexByName('idx_yfth_franchise_follow_visible_time');
            }
            $table->removeColumn('visible_type')->update();
        }
    }
}
