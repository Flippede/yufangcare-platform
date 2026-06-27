<?php

use think\migration\Migrator;

class CreateYfthAdminStoreScope extends Migrator
{
    public function change()
    {
        $this->table('yfth_admin_store_scope')
            ->setEngine('InnoDB')
            ->setComment('YFTH backend admin store scope')
            ->addColumn('admin_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'system_admin.id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'system_store.id, 0 means headquarter'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'headquarter_operator/franchisee/store_manager/store_staff'])
            ->addColumn('permission_scope', 'text', ['null' => true, 'comment' => 'reserved structured scope'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective time'])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('created_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'creator admin id'])
            ->addColumn('updated_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'last updater admin id'])
            ->addColumn('disabled_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'disabler admin id'])
            ->addColumn('disabled_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'disabled time'])
            ->addColumn('close_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'disable reason'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'one active admin role scope'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_admin_scope_active'])
            ->addIndex(['admin_id', 'role_code', 'status'], ['name' => 'idx_yfth_admin_scope_admin_role'])
            ->addIndex(['store_id', 'role_code', 'status'], ['name' => 'idx_yfth_admin_scope_store_role'])
            ->addIndex(['status', 'start_time', 'end_time'], ['name' => 'idx_yfth_admin_scope_active_window'])
            ->create();
    }
}
