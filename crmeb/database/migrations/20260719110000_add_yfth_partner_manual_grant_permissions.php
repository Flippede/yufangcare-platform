<?php

use think\migration\Migrator;

class AddYfthPartnerManualGrantPermissions extends Migrator
{
    private const AUTHS = [
        'yfth-user-role-partner-grant-options',
        'yfth-user-role-partner-grant',
    ];

    public function up()
    {
        $page = $this->menu('yfth-user-role-management-index');
        if (!$page) {
            throw new RuntimeException('yfth_user_role_management_menu_required');
        }
        $this->ensure($this->api(
            (int)$page['id'],
            '查询合伙人授予上级候选',
            'yfth/user_role/partner/grant_options',
            'GET',
            self::AUTHS[0]
        ));
        $this->ensure($this->api(
            (int)$page['id'],
            '授予用户招商合伙人身份',
            'yfth/user_role/user/<uid>/partner/grant',
            'POST',
            self::AUTHS[1]
        ));
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], self::AUTHS);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
    }

    private function api(int $pageId, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pageId,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.HqUserRole',
            'action' => '',
            'api_url' => $url,
            'methods' => $method,
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$pageId,
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => $auth,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
    }

    private function ensure(array $row): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchAll('SELECT * FROM ' . $table . ' WHERE `unique_auth`=' . $this->quote($row['unique_auth']));
        if (count($existing) > 1) {
            throw new RuntimeException('yfth_partner_manual_grant_permission_duplicate:' . $row['unique_auth']);
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

    private function menu(string $auth): array
    {
        return $this->getAdapter()->fetchRow(
            'SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth) . ' AND `is_del`=0 LIMIT 1'
        ) ?: [];
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
