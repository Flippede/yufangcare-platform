<?php

use think\migration\Migrator;

class AddYfthUserRoleManagementPermissions extends Migrator
{
    private const AUTHS = [
        'yfth-user-role-management-index',
        'yfth-user-role-management-list',
        'yfth-user-role-management-detail',
        'yfth-user-role-management-grant',
        'yfth-user-role-management-revoke',
    ];

    public function up()
    {
        $rootId = $this->rootId();
        $page = $this->page($rootId);
        $pageId = $this->ensure($page);
        $rows = [
            $this->api($pageId, '查询用户经营身份', 'yfth/user_role/user', 'GET', self::AUTHS[1]),
            $this->api($pageId, '查看用户经营身份详情', 'yfth/user_role/user/<uid>', 'GET', self::AUTHS[2]),
            $this->api($pageId, '授予用户经营身份', 'yfth/user_role/user/<uid>/grant', 'POST', self::AUTHS[3]),
            $this->api($pageId, '撤销用户经营身份', 'yfth/user_role/role/<id>/revoke', 'POST', self::AUTHS[4]),
        ];
        foreach ($rows as $row) {
            $this->ensure($row);
        }
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], self::AUTHS);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
    }

    private function page(int $rootId): array
    {
        return [
            'pid' => $rootId, 'icon' => 'md-people', 'menu_name' => '用户经营身份', 'module' => 'admin',
            'controller' => 'v1.yfth.HqUserRole', 'action' => 'users', 'api_url' => 'yfth/user_role/user',
            'methods' => 'GET', 'params' => '', 'sort' => 0, 'is_show' => 1, 'is_show_path' => 1,
            'access' => 1, 'menu_path' => '/yfth/user-role', 'path' => (string)$rootId, 'auth_type' => 1,
            'header' => 'yfth', 'is_header' => 0, 'unique_auth' => self::AUTHS[0], 'is_del' => 0, 'mark' => 'yfth',
        ];
    }

    private function api(int $pageId, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pageId, 'icon' => '', 'menu_name' => $name, 'module' => 'admin',
            'controller' => 'v1.yfth.HqUserRole', 'action' => '', 'api_url' => $url, 'methods' => $method,
            'params' => '', 'sort' => 0, 'is_show' => 0, 'is_show_path' => 0, 'access' => 1,
            'menu_path' => '', 'path' => (string)$pageId, 'auth_type' => 2, 'header' => 'yfth',
            'is_header' => 0, 'unique_auth' => $auth, 'is_del' => 0, 'mark' => 'yfth',
        ];
    }

    private function ensure(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchAll('SELECT * FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']));
        if (count($existing) > 1) {
            throw new RuntimeException('yfth_user_role_permission_duplicate:' . $row['unique_auth']);
        }
        if ($existing) {
            foreach ($row as $field => $value) {
                if ((string)$existing[0][$field] !== (string)$value) {
                    throw new RuntimeException('yfth_user_role_permission_forward_repair_required:' . $row['unique_auth'] . ':' . $field);
                }
            }
            return (int)$existing[0]['id'];
        }
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function rootId(): int
    {
        $row = $this->getAdapter()->fetchRow('SELECT `id` FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = ' . $this->quote('yfth-foundation') . ' AND `is_del` = 0 LIMIT 1');
        if (!$row) {
            throw new RuntimeException('yfth_foundation_menu_required');
        }
        return (int)$row['id'];
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
