<?php

use think\migration\Migrator;

class AddYfthPartnerRevokePermission extends Migrator
{
    private const AUTH = 'yfth-user-role-partner-revoke';

    public function up()
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $page = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote('yfth-user-role-management-index') . ' AND `is_del`=0 LIMIT 1'
        );
        if (!$page) {
            throw new RuntimeException('yfth_user_role_management_menu_required');
        }

        $row = [
            'pid' => (int)$page['id'],
            'icon' => '',
            'menu_name' => '撤销用户招商合伙人身份',
            'module' => 'admin',
            'controller' => 'v1.yfth.HqUserRole',
            'action' => '',
            'api_url' => 'yfth/user_role/user/<uid>/partner/revoke',
            'methods' => 'POST',
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$page['id'],
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => self::AUTH,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
        $existing = $this->getAdapter()->fetchAll('SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote(self::AUTH));
        if (count($existing) > 1) {
            throw new RuntimeException('yfth_partner_revoke_permission_duplicate');
        }
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field !== 'unique_auth') {
                    $sets[] = '`' . $field . '`=' . $this->quote($value);
                }
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE `id`=' . (int)$existing[0]['id']);
            return;
        }
        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
    }

    public function down()
    {
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote(self::AUTH));
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return 'NULL';
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
