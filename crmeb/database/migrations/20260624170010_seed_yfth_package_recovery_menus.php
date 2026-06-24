<?php

use think\migration\Migrator;

class SeedYfthPackageRecoveryMenus extends Migrator
{
    private $menuKeys = [
        'yfth-package-rule-copy',
        'yfth-package-activation-recover',
        'yfth-package-activation-retry',
        'yfth-package-lifecycle-state',
    ];

    public function up()
    {
        $page = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = ' . $this->quote('yfth-package-benefit-index') . ' LIMIT 1'
        );
        if (!$page) {
            return;
        }
        $pageId = (int)$page['id'];
        foreach ([
            $this->apiRow($pageId, 'Copy rule version', 'yfth/package_benefit/rule/<id>/copy', 'POST', 'yfth-package-rule-copy'),
            $this->apiRow($pageId, 'Recover paid packages', 'yfth/package_benefit/activation/recover', 'POST', 'yfth-package-activation-recover'),
            $this->apiRow($pageId, 'Retry package activation', 'yfth/package_benefit/purchase/<id>/activation_retry', 'POST', 'yfth-package-activation-retry'),
            $this->apiRow($pageId, 'Lifecycle state change', 'yfth/package_benefit/instance/<id>/lifecycle', 'POST', 'yfth-package-lifecycle-state'),
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
            'controller' => 'v1.yfth.PackageBenefit',
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
