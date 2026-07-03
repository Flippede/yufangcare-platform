<?php

use think\migration\Migrator;

class SeedYfthServiceWriteoffMenus extends Migrator
{
    private $menuKeys = [
        'yfth-service-writeoff-list',
        'yfth-service-writeoff-detail',
        'yfth-service-writeoff-precheck',
        'yfth-service-writeoff-token',
        'yfth-service-writeoff-digital',
        'yfth-service-writeoff-result',
        'yfth-service-writeoff-exception',
    ];

    public function up()
    {
        $page = $this->getAdapter()->fetchRow('SELECT `id` FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = ' . $this->quote('yfth-service-appointment-index') . ' LIMIT 1');
        $pageId = $page ? (int)$page['id'] : 0;
        if ($pageId <= 0) {
            return;
        }
        foreach ([
            $this->apiRow($pageId, 'Service writeoff list', 'yfth/service_appointment/writeoff', 'GET', 'yfth-service-writeoff-list'),
            $this->apiRow($pageId, 'Service writeoff detail', 'yfth/service_appointment/writeoff/record/<id>', 'GET', 'yfth-service-writeoff-detail'),
            $this->apiRow($pageId, 'Service writeoff precheck', 'yfth/service_appointment/writeoff/precheck', 'POST', 'yfth-service-writeoff-precheck'),
            $this->apiRow($pageId, 'Service QR writeoff', 'yfth/service_appointment/writeoff/token', 'POST', 'yfth-service-writeoff-token'),
            $this->apiRow($pageId, 'Service digital writeoff', 'yfth/service_appointment/writeoff/digital', 'POST', 'yfth-service-writeoff-digital'),
            $this->apiRow($pageId, 'Service writeoff result', 'yfth/service_appointment/writeoff/<id>', 'GET', 'yfth-service-writeoff-result'),
            $this->apiRow($pageId, 'Service exception writeoff', 'yfth/service_appointment/appointment/<id>/exception_writeoff', 'POST', 'yfth-service-writeoff-exception'),
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
            'controller' => 'v1.yfth.ServiceAppointment',
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
