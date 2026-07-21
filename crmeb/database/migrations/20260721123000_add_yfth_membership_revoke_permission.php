<?php

use think\migration\Migrator;

class AddYfthMembershipRevokePermission extends Migrator
{
    private const AUTH = 'yfth-user-role-membership-revoke';

    public function up()
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $page = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote('yfth-user-role-management-index') . ' AND `is_del`=0 LIMIT 1'
        );
        if (!$page) {
            throw new RuntimeException('yfth_user_role_management_menu_required');
        }
        $existing = $this->getAdapter()->fetchAll(
            'SELECT `id`,`api_url`,`methods` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote(self::AUTH)
        );
        if (count($existing) > 1) {
            throw new RuntimeException('yfth_membership_revoke_permission_duplicate');
        }
        if ($existing) {
            if ((string)$existing[0]['api_url'] !== 'yfth/user_role/user/<uid>/membership/revoke'
                || strtoupper((string)$existing[0]['methods']) !== 'POST') {
                throw new RuntimeException('yfth_membership_revoke_permission_forward_repair_required');
            }
            return;
        }

        $row = [
            'pid' => (int)$page['id'], 'icon' => '', 'menu_name' => '解除永久会员', 'module' => 'admin',
            'controller' => 'v1.yfth.HqUserRole', 'action' => 'revokeMembership',
            'api_url' => 'yfth/user_role/user/<uid>/membership/revoke', 'methods' => 'POST',
            'params' => '', 'sort' => 0, 'is_show' => 0, 'is_show_path' => 0, 'access' => 1,
            'menu_path' => '', 'path' => (string)$page['id'], 'auth_type' => 2,
            'header' => 'yfth', 'is_header' => 0, 'unique_auth' => self::AUTH, 'is_del' => 0, 'mark' => 'yfth',
        ];
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
    }

    public function down()
    {
        $this->execute(
            'DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote(self::AUTH)
        );
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) return (string)$value;
        if ($value === null) return 'NULL';
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
