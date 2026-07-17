<?php

use think\migration\Migrator;

class AddYfthMembershipAndDebugPurgePermissions extends Migrator
{
    private const AUTHS = [
        'yfth-user-role-membership-grant',
        'yfth-user-debug-purge-preflight',
        'yfth-user-debug-purge-execute',
    ];

    public function up()
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $page = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote('yfth-user-role-management-index') . ' AND `is_del`=0 LIMIT 1'
        );
        if (!$page) {
            throw new RuntimeException('yfth_user_role_management_menu_required');
        }
        $rows = [
            ['授予永久会员', 'yfth/user_role/user/<uid>/membership/grant', 'POST', self::AUTHS[0]],
            ['预检调试用户删除', 'yfth/user_role/user/<uid>/purge/preflight', 'GET', self::AUTHS[1]],
            ['执行调试用户删除', 'yfth/user_role/user/<uid>/purge', 'DELETE', self::AUTHS[2]],
        ];
        foreach ($rows as $row) {
            $existing = $this->getAdapter()->fetchAll(
                'SELECT `id` FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row[3])
            );
            if (count($existing) > 1) {
                throw new RuntimeException('yfth_user_role_permission_duplicate:' . $row[3]);
            }
            if ($existing) {
                continue;
            }
            $this->insertPermission($table, (int)$page['id'], $row[0], $row[1], $row[2], $row[3]);
        }
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], self::AUTHS);
        $this->execute(
            'DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')'
        );
    }

    private function insertPermission(string $table, int $pageId, string $name, string $api, string $method, string $auth): void
    {
        $row = [
            'pid' => $pageId, 'icon' => '', 'menu_name' => $name, 'module' => 'admin',
            'controller' => 'v1.yfth.HqUserRole', 'action' => '', 'api_url' => $api,
            'methods' => $method, 'params' => '', 'sort' => 0, 'is_show' => 0, 'is_show_path' => 0,
            'access' => 1, 'menu_path' => '', 'path' => (string)$pageId, 'auth_type' => 2,
            'header' => 'yfth', 'is_header' => 0, 'unique_auth' => $auth, 'is_del' => 0, 'mark' => 'yfth',
        ];
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
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
