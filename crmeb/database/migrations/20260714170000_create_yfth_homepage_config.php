<?php

use think\migration\Migrator;

class CreateYfthHomepageConfig extends Migrator
{
    private $keys = ['yfth-homepage-index', 'yfth-homepage-config-read', 'yfth-homepage-config-save'];

    public function up()
    {
        if (!$this->hasTable('yfth_homepage_config')) {
            $this->table('yfth_homepage_config', ['comment' => 'YFTH fixed customer homepage display configuration'])
                ->addColumn('config_json', 'text', ['null' => true, 'comment' => 'Display-only homepage configuration'])
                ->addColumn('version', 'integer', ['default' => 1, 'signed' => false])
                ->addColumn('add_time', 'integer', ['default' => 0, 'signed' => false])
                ->addColumn('update_time', 'integer', ['default' => 0, 'signed' => false])
                ->create();
        }

        $root = $this->menu('yfth-foundation');
        if (!$root) {
            throw new RuntimeException('yfth root menu is required for homepage configuration');
        }
        $pageId = $this->upsert([
            'pid' => (int)$root['id'], 'icon' => 'md-home', 'menu_name' => '首页配置', 'module' => 'admin',
            'controller' => 'v1.yfth.Homepage', 'action' => 'config', 'api_url' => 'yfth/homepage/config', 'methods' => 'GET',
            'params' => '', 'sort' => 2, 'is_show' => 1, 'is_show_path' => 1, 'access' => 1,
            'menu_path' => '/yfth/homepage', 'path' => (string)$root['id'], 'auth_type' => 1, 'header' => 'yfth',
            'is_header' => 0, 'unique_auth' => 'yfth-homepage-index', 'is_del' => 0, 'mark' => 'yfth',
        ]);
        $this->upsert($this->apiRow($pageId, 'Homepage configuration read', 'yfth/homepage/config', 'GET', 'yfth-homepage-config-read'));
        $this->upsert($this->apiRow($pageId, 'Homepage configuration save', 'yfth/homepage/config', 'POST', 'yfth-homepage-config-save'));
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], $this->keys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
        if ($this->hasTable('yfth_homepage_config')) {
            $this->table('yfth_homepage_config')->drop()->save();
        }
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return ['pid' => $pid, 'icon' => '', 'menu_name' => $name, 'module' => 'admin', 'controller' => 'v1.yfth.Homepage',
            'action' => '', 'api_url' => $url, 'methods' => $method, 'params' => '', 'sort' => 0, 'is_show' => 0,
            'is_show_path' => 0, 'access' => 1, 'menu_path' => '', 'path' => (string)$pid, 'auth_type' => 2,
            'header' => 'yfth', 'is_header' => 0, 'unique_auth' => $auth, 'is_del' => 0, 'mark' => 'yfth'];
    }

    private function menu(string $auth)
    {
        return $this->getAdapter()->fetchRow('SELECT `id` FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = ' . $this->quote($auth) . ' AND `is_del` = 0 LIMIT 1');
    }

    private function upsert(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field !== 'unique_auth') $sets[] = '`' . $field . '` = ' . $this->quote($value);
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return (int)$existing['id'];
        }
        $fields = array_map(static function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) return (string)$value;
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $prefix = method_exists($this->getAdapter(), 'getOption') ? (string)$this->getAdapter()->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
