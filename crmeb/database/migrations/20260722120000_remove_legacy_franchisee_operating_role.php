<?php

use think\migration\Migrator;

class RemoveLegacyFranchiseeOperatingRole extends Migrator
{
    public function up()
    {
        $table = '`' . $this->prefixed('yfth_user_store_role') . '`';
        $rows = $this->getAdapter()->fetchAll(
            'SELECT * FROM ' . $table . ' WHERE `role_code`=' . $this->quote('franchisee') . ' AND `status`=' . $this->quote('active') . ' ORDER BY `id` ASC'
        );
        $now = time();
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $storeId = (int)$row['store_id'];
            $manager = $this->getAdapter()->fetchRow(
                'SELECT `id` FROM ' . $table . ' WHERE `uid`=' . $uid . ' AND `store_id`=' . $storeId
                . ' AND `role_code`=' . $this->quote('store_manager') . ' AND `status`=' . $this->quote('active') . ' LIMIT 1'
            );
            if ($manager) {
                $this->execute(
                    'UPDATE ' . $table . ' SET `status`=' . $this->quote('revoked') . ',`active_key`=NULL,`end_time`=' . $now
                    . ',`update_time`=' . $now . ' WHERE `id`=' . (int)$row['id']
                );
                continue;
            }
            $this->execute(
                'UPDATE ' . $table . ' SET `role_code`=' . $this->quote('store_manager')
                . ',`active_key`=' . $this->quote($uid . ':' . $storeId . ':store_manager')
                . ',`update_time`=' . $now . ' WHERE `id`=' . (int)$row['id']
            );
        }

        $profiles = '`' . $this->prefixed('yfth_partner_profile') . '`';
        $this->execute('UPDATE ' . $profiles . ' SET `legacy_franchisee_role_id`=0 WHERE `legacy_franchisee_role_id`<>0');

        $menus = '`' . $this->prefixed('system_menus') . '`';
        $retiredAuths = [
            'yfth-permanent-membership-enrollment-create',
            'yfth-permanent-membership-enrollment-bind',
            'yfth-permanent-membership-payment-confirm',
            'yfth-permanent-membership-confirmation-code',
        ];
        foreach ($retiredAuths as $auth) {
            $this->execute(
                'UPDATE ' . $menus . ' SET `is_del`=1,`is_show`=0,`access`=0 WHERE `unique_auth`=' . $this->quote($auth)
            );
        }
    }

    public function down()
    {
        // This is a product identity correction. Restoring the removed role would recreate two live models.
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
