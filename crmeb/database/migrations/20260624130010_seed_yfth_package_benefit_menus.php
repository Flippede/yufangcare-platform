<?php

use think\migration\Migrator;

class SeedYfthPackageBenefitMenus extends Migrator
{
    private $menuKeys = [
        'yfth-package-benefit-index',
        'yfth-package-template-list',
        'yfth-package-template-save',
        'yfth-package-rule-save',
        'yfth-package-binding-save',
        'yfth-benefit-template-list',
        'yfth-benefit-template-save',
        'yfth-monthly-rule-list',
        'yfth-monthly-rule-save',
        'yfth-package-purchase-list',
        'yfth-package-instance-list',
        'yfth-package-instance-detail',
        'yfth-package-instance-state',
        'yfth-benefit-plan-list',
        'yfth-benefit-period-open',
    ];

    public function up()
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-gift',
            'menu_name' => 'Package Benefits',
            'module' => 'admin',
            'controller' => 'v1.yfth.PackageBenefit',
            'action' => 'index',
            'api_url' => 'yfth/package_benefit/template',
            'methods' => 'GET',
            'params' => '',
            'sort' => 20,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/package-benefit',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-package-benefit-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, 'Template list', 'yfth/package_benefit/template', 'GET', 'yfth-package-template-list'),
            $this->apiRow($pageId, 'Template save', 'yfth/package_benefit/template/save', 'POST', 'yfth-package-template-save'),
            $this->apiRow($pageId, 'Rule save', 'yfth/package_benefit/rule/save', 'POST', 'yfth-package-rule-save'),
            $this->apiRow($pageId, 'Binding save', 'yfth/package_benefit/binding/save', 'POST', 'yfth-package-binding-save'),
            $this->apiRow($pageId, 'Benefit template list', 'yfth/package_benefit/benefit_template', 'GET', 'yfth-benefit-template-list'),
            $this->apiRow($pageId, 'Benefit template save', 'yfth/package_benefit/benefit_template/save', 'POST', 'yfth-benefit-template-save'),
            $this->apiRow($pageId, 'Monthly rule list', 'yfth/package_benefit/monthly_rule', 'GET', 'yfth-monthly-rule-list'),
            $this->apiRow($pageId, 'Monthly rule save', 'yfth/package_benefit/monthly_rule/save', 'POST', 'yfth-monthly-rule-save'),
            $this->apiRow($pageId, 'Purchase list', 'yfth/package_benefit/purchase', 'GET', 'yfth-package-purchase-list'),
            $this->apiRow($pageId, 'Instance list', 'yfth/package_benefit/instance', 'GET', 'yfth-package-instance-list'),
            $this->apiRow($pageId, 'Instance detail', 'yfth/package_benefit/instance/<id>', 'GET', 'yfth-package-instance-detail'),
            $this->apiRow($pageId, 'Instance state', 'yfth/package_benefit/instance/<id>/state', 'POST', 'yfth-package-instance-state'),
            $this->apiRow($pageId, 'Plan list', 'yfth/package_benefit/plan', 'GET', 'yfth-benefit-plan-list'),
            $this->apiRow($pageId, 'Open periods', 'yfth/package_benefit/period/open_due', 'POST', 'yfth-benefit-period-open'),
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

    private function ensureRoot(): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $root = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote('yfth-foundation') . ' LIMIT 1');
        if ($root) {
            return (int)$root['id'];
        }
        return $this->upsertMenu([
            'pid' => 0,
            'icon' => 'md-git-network',
            'menu_name' => 'YFTH',
            'module' => 'admin',
            'controller' => '',
            'action' => '',
            'api_url' => '',
            'methods' => 'GET',
            'params' => '',
            'sort' => 320,
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
