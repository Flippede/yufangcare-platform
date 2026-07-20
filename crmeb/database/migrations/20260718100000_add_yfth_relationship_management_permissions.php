<?php

use think\migration\Migrator;

class AddYfthRelationshipManagementPermissions extends Migrator
{
    private const PAGE_AUTH = 'yfth-hq-authority-readonly-index';
    private const AUTHS = [
        'yfth-hq-relationship-user-hierarchy',
        'yfth-hq-relationship-store-hierarchy',
        'yfth-hq-relationship-parent-revoke',
    ];

    public function up(): void
    {
        $page = $this->rowByAuth(self::PAGE_AUTH);
        if (!$page) {
            throw new RuntimeException('yfth_relationship_management_parent_menu_required');
        }
        $rows = [
            $this->api((int)$page['id'], '查看用户关系层级', 'userHierarchy', 'yfth/relationship_management/user_hierarchy', 'GET', self::AUTHS[0]),
            $this->api((int)$page['id'], '查看门店加盟合伙人', 'storeHierarchy', 'yfth/relationship_management/store_hierarchy', 'GET', self::AUTHS[1]),
            $this->api((int)$page['id'], '撤销用户上级关系', 'revokeParent', 'yfth/relationship_management/user/<id>/revoke_parent', 'POST', self::AUTHS[2]),
        ];
        foreach ($rows as $row) {
            $existing = $this->rowsByAuth($row['unique_auth']);
            if (count($existing) > 1) {
                throw new RuntimeException('yfth_relationship_management_permission_duplicate:' . $row['unique_auth']);
            }
            if ($existing) {
                $this->assertSignature($existing[0], $row);
                continue;
            }
            $this->insertRow($row);
        }
    }

    public function down(): void
    {
        $quoted = array_map([$this, 'quote'], self::AUTHS);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
    }

    private function api(int $pid, string $name, string $action, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pid, 'icon' => '', 'menu_name' => $name, 'module' => 'admin',
            'controller' => 'v1.yfth.RelationshipManagement', 'action' => $action,
            'api_url' => $url, 'methods' => $method, 'params' => '', 'sort' => 0,
            'is_show' => 0, 'is_show_path' => 0, 'access' => 1, 'menu_path' => '',
            'path' => (string)$pid, 'auth_type' => 2, 'header' => 'yfth', 'is_header' => 0,
            'unique_auth' => $auth, 'is_del' => 0, 'mark' => 'yfth',
        ];
    }

    private function rowByAuth(string $auth): array
    {
        $rows = $this->rowsByAuth($auth);
        if (count($rows) > 1) {
            throw new RuntimeException('yfth_relationship_management_permission_duplicate:' . $auth);
        }
        return $rows[0] ?? [];
    }

    private function rowsByAuth(string $auth): array
    {
        return $this->getAdapter()->fetchAll(
            'SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth`=' . $this->quote($auth) . ' ORDER BY `id` ASC'
        );
    }

    private function assertSignature(array $actual, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual) || (string)$actual[$field] !== (string)$value) {
                throw new RuntimeException('yfth_relationship_management_permission_signature_mismatch:' . $expected['unique_auth'] . ':' . $field);
            }
        }
    }

    private function insertRow(array $row): void
    {
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO `' . $this->prefixed('system_menus') . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) return (string)$value;
        if ($value === null) return 'NULL';
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
