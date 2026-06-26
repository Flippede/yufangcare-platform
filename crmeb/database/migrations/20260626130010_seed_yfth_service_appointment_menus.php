<?php

use think\migration\Migrator;

class SeedYfthServiceAppointmentMenus extends Migrator
{
    private $menuKeys = [
        'yfth-service-appointment-index',
        'yfth-service-project-list',
        'yfth-service-project-save',
        'yfth-service-project-disable',
        'yfth-store-service-list',
        'yfth-store-service-save',
        'yfth-store-service-disable',
        'yfth-service-schedule-list',
        'yfth-service-schedule-save',
        'yfth-service-schedule-disable',
        'yfth-service-special-day-list',
        'yfth-service-special-day-save',
        'yfth-service-special-day-disable',
        'yfth-service-slot-preview',
    ];

    public function up()
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-calendar',
            'menu_name' => 'Service Appointment',
            'module' => 'admin',
            'controller' => 'v1.yfth.ServiceAppointment',
            'action' => 'index',
            'api_url' => 'yfth/service_appointment/project',
            'methods' => 'GET',
            'params' => '',
            'sort' => 30,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/service-appointment',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-service-appointment-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, 'Project list', 'yfth/service_appointment/project', 'GET', 'yfth-service-project-list'),
            $this->apiRow($pageId, 'Project save', 'yfth/service_appointment/project/save', 'POST', 'yfth-service-project-save'),
            $this->apiRow($pageId, 'Project disable', 'yfth/service_appointment/project/disable', 'POST', 'yfth-service-project-disable'),
            $this->apiRow($pageId, 'Store service list', 'yfth/service_appointment/store_service', 'GET', 'yfth-store-service-list'),
            $this->apiRow($pageId, 'Store service save', 'yfth/service_appointment/store_service/save', 'POST', 'yfth-store-service-save'),
            $this->apiRow($pageId, 'Store service disable', 'yfth/service_appointment/store_service/disable', 'POST', 'yfth-store-service-disable'),
            $this->apiRow($pageId, 'Schedule list', 'yfth/service_appointment/schedule_rule', 'GET', 'yfth-service-schedule-list'),
            $this->apiRow($pageId, 'Schedule save', 'yfth/service_appointment/schedule_rule/save', 'POST', 'yfth-service-schedule-save'),
            $this->apiRow($pageId, 'Schedule disable', 'yfth/service_appointment/schedule_rule/disable', 'POST', 'yfth-service-schedule-disable'),
            $this->apiRow($pageId, 'Special day list', 'yfth/service_appointment/special_day', 'GET', 'yfth-service-special-day-list'),
            $this->apiRow($pageId, 'Special day save', 'yfth/service_appointment/special_day/save', 'POST', 'yfth-service-special-day-save'),
            $this->apiRow($pageId, 'Special day disable', 'yfth/service_appointment/special_day/disable', 'POST', 'yfth-service-special-day-disable'),
            $this->apiRow($pageId, 'Slot preview', 'yfth/service_appointment/slot_preview', 'GET', 'yfth-service-slot-preview'),
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
