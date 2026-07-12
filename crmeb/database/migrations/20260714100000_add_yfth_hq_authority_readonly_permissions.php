<?php

use think\migration\Migrator;

class AddYfthHqAuthorityReadonlyPermissions extends Migrator
{
    private const AUTHS = [
        'yfth-hq-authority-readonly-index',
        'yfth-hq-authority-attribution-list',
        'yfth-hq-authority-attribution-detail',
        'yfth-hq-authority-referral-list',
        'yfth-hq-authority-referral-detail',
        'yfth-hq-authority-attribution-audit',
        'yfth-hq-authority-referral-audit',
    ];

    public function up()
    {
        $rootId = $this->rootId();
        $pageId = $this->upsert([
            'pid' => $rootId,
            'icon' => 'md-link',
            'menu_name' => '总部客户归属',
            'module' => 'admin',
            'controller' => 'v1.yfth.HqAuthorityRead',
            'action' => 'attributionList',
            'api_url' => 'yfth/hq_authority/attribution',
            'methods' => 'GET',
            'params' => '',
            'sort' => 0,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/hq-authority',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => self::AUTHS[0],
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->api($pageId, '查看客户归属', 'yfth/hq_authority/attribution', self::AUTHS[1]),
            $this->api($pageId, '查看归属详情', 'yfth/hq_authority/attribution/<id>', self::AUTHS[2]),
            $this->api($pageId, '查看推荐关系', 'yfth/hq_authority/referral', self::AUTHS[3]),
            $this->api($pageId, '查看推荐详情', 'yfth/hq_authority/referral/<id>', self::AUTHS[4]),
            $this->api($pageId, '审计归属事件', 'yfth/hq_authority/attribution/<id>/events', self::AUTHS[5]),
            $this->api($pageId, '审计推荐事件', 'yfth/hq_authority/referral/<id>/events', self::AUTHS[6]),
        ] as $row) {
            $this->upsert($row);
        }
    }

    public function down()
    {
        $quoted = array_map([$this, 'quote'], self::AUTHS);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');
    }

    private function rootId(): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $row = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote('yfth-foundation') . ' AND `is_del` = 0 LIMIT 1'
        );
        if (!$row) {
            throw new RuntimeException('yfth_foundation_menu_required');
        }
        return (int)$row['id'];
    }

    private function api(int $pid, string $name, string $url, string $auth): array
    {
        return [
            'pid' => $pid,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.HqAuthorityRead',
            'action' => '',
            'api_url' => $url,
            'methods' => 'GET',
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

    private function upsert(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1'
        );
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field !== 'unique_auth') {
                    $sets[] = '`' . $field . '` = ' . $this->quote($value);
                }
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return (int)$existing['id'];
        }
        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1'
        );
        return (int)$created['id'];
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

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
