<?php

use think\migration\Migrator;

class AddYfthHqWorkbenchPermission extends Migrator
{
    private $uniqueAuth = 'yfth-hq-workbench-read';

    public function up()
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $parent = $this->getAdapter()->fetchRow(
            'SELECT `id`, `header`, `mark` FROM ' . $table .
            ' WHERE `unique_auth` = ' . $this->quote('admin-home') .
            ' AND `auth_type` = 1 AND `is_del` = 0 LIMIT 1'
        );
        if (!$parent) {
            throw new RuntimeException('admin-home menu is required for yfth headquarters workbench permission');
        }

        $row = [
            'pid' => (int)$parent['id'],
            'icon' => '',
            'menu_name' => '查看总部经营工作台',
            'module' => 'admin',
            'controller' => 'Common',
            'action' => 'yfthWorkbench',
            'api_url' => 'home/yfth',
            'methods' => 'GET',
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$parent['id'],
            'auth_type' => 2,
            'header' => (string)($parent['header'] ?: 'home'),
            'is_header' => 0,
            'unique_auth' => $this->uniqueAuth,
            'is_del' => 0,
            'mark' => (string)($parent['mark'] ?: ''),
        ];

        $existing = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table .
            ' WHERE `unique_auth` = ' . $this->quote($this->uniqueAuth) . ' LIMIT 1'
        );
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field === 'unique_auth') {
                    continue;
                }
                $sets[] = '`' . $field . '` = ' . $this->quote($value);
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return;
        }

        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map(function ($value) {
            return $this->quote($value);
        }, array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')');
    }

    public function down()
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $this->execute(
            'DELETE FROM ' . $table .
            ' WHERE `unique_auth` = ' . $this->quote($this->uniqueAuth) .
            ' AND `api_url` = ' . $this->quote('home/yfth') .
            ' AND `auth_type` = 2'
        );
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
