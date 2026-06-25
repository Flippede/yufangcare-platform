<?php

use think\migration\Migrator;

class SeedYfthFoundationMenus extends Migrator
{
    private $menuKeys = [
        'yfth-foundation',
        'yfth-foundation-index',
        'yfth-foundation-identity-list',
        'yfth-foundation-store-role-list',
        'yfth-foundation-subject-list',
        'yfth-foundation-subject-save',
        'yfth-foundation-store-subject-list',
        'yfth-foundation-store-subject-save',
        'yfth-foundation-store-subject-disable',
        'yfth-foundation-qualification-list',
        'yfth-foundation-qualification-save',
        'yfth-foundation-qualification-audit',
        'yfth-foundation-capability-list',
        'yfth-foundation-payment-route-list',
        'yfth-foundation-payment-route-save',
        'yfth-foundation-payment-route-disable',
        'yfth-foundation-payment-route-resolve',
        'yfth-foundation-audit-event-list',
    ];

    public function up()
    {
        $rootId = $this->upsertMenu([
            'pid' => 0,
            'icon' => 'md-git-network',
            'menu_name' => 'YFTH',
            'module' => 'admin',
            'controller' => '',
            'action' => '',
            'api_url' => '',
            'methods' => 'GET',
            'params' => '',
            'sort' => 32,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth',
            'path' => '/yfth',
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 1,
            'unique_auth' => 'yfth-foundation',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-business',
            'menu_name' => 'Foundation',
            'module' => 'admin',
            'controller' => 'v1.yfth.Foundation',
            'action' => 'index',
            'api_url' => 'yfth/foundation/identity',
            'methods' => 'GET',
            'params' => '',
            'sort' => 10,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/foundation',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-foundation-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, 'Identity list', 'yfth/foundation/identity', 'GET', 'yfth-foundation-identity-list'),
            $this->apiRow($pageId, 'Store role list', 'yfth/foundation/store_role', 'GET', 'yfth-foundation-store-role-list'),
            $this->apiRow($pageId, 'Subject list', 'yfth/foundation/subject', 'GET', 'yfth-foundation-subject-list'),
            $this->apiRow($pageId, 'Subject save', 'yfth/foundation/subject/save', 'POST', 'yfth-foundation-subject-save'),
            $this->apiRow($pageId, 'Store subject list', 'yfth/foundation/store_subject', 'GET', 'yfth-foundation-store-subject-list'),
            $this->apiRow($pageId, 'Store subject save', 'yfth/foundation/store_subject/save', 'POST', 'yfth-foundation-store-subject-save'),
            $this->apiRow($pageId, 'Store subject disable', 'yfth/foundation/store_subject/disable', 'POST', 'yfth-foundation-store-subject-disable'),
            $this->apiRow($pageId, 'Qualification list', 'yfth/foundation/qualification', 'GET', 'yfth-foundation-qualification-list'),
            $this->apiRow($pageId, 'Qualification save', 'yfth/foundation/qualification/save', 'POST', 'yfth-foundation-qualification-save'),
            $this->apiRow($pageId, 'Qualification audit', 'yfth/foundation/qualification/audit', 'POST', 'yfth-foundation-qualification-audit'),
            $this->apiRow($pageId, 'Capability list', 'yfth/foundation/capability', 'GET', 'yfth-foundation-capability-list'),
            $this->apiRow($pageId, 'Payment route list', 'yfth/foundation/payment_route', 'GET', 'yfth-foundation-payment-route-list'),
            $this->apiRow($pageId, 'Payment route save', 'yfth/foundation/payment_route/save', 'POST', 'yfth-foundation-payment-route-save'),
            $this->apiRow($pageId, 'Payment route disable', 'yfth/foundation/payment_route/disable', 'POST', 'yfth-foundation-payment-route-disable'),
            $this->apiRow($pageId, 'Payment route resolve', 'yfth/foundation/payment_route/resolve', 'GET', 'yfth-foundation-payment-route-resolve'),
            $this->apiRow($pageId, 'Audit event list', 'yfth/foundation/audit_event', 'GET', 'yfth-foundation-audit-event-list'),
        ] as $row) {
            $this->upsertMenu($row);
        }
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pid,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.Foundation',
            'action' => '',
            'api_url' => $url,
            'methods' => $method,
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$pid,
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => $auth,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
    }

    private function upsertMenu(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field === 'unique_auth') {
                    continue;
                }
                $sets[] = '`' . $field . '` = ' . $this->quote($value);
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return (int)$existing['id'];
        }

        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map(function ($value) {
            return $this->quote($value);
        }, array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
