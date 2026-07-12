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
        $recorded = $this->migrationRecordExists();
        $rootId = $this->rootId();
        $page = [
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
        ];
        $pageRows = $this->rowsByAuth(self::AUTHS[0]);
        $this->assertNotDuplicate($pageRows, self::AUTHS[0]);
        if (!$pageRows) {
            foreach (array_slice(self::AUTHS, 1) as $auth) {
                if ($this->rowsByAuth($auth)) {
                    throw new RuntimeException('yfth_hq_authority_readonly_forward_repair_required');
                }
            }
            if ($recorded) {
                throw new RuntimeException('yfth_hq_authority_readonly_forward_repair_required');
            }
            $pageId = $this->insertPermission($page);
        } else {
            $this->assertSignature($pageRows[0], $page, $recorded);
            $pageId = (int)$pageRows[0]['id'];
        }

        $apiRows = [
            $this->api($pageId, '查看客户归属', 'yfth/hq_authority/attribution', self::AUTHS[1]),
            $this->api($pageId, '查看归属详情', 'yfth/hq_authority/attribution/<id>', self::AUTHS[2]),
            $this->api($pageId, '查看推荐关系', 'yfth/hq_authority/referral', self::AUTHS[3]),
            $this->api($pageId, '查看推荐详情', 'yfth/hq_authority/referral/<id>', self::AUTHS[4]),
            $this->api($pageId, '审计归属事件', 'yfth/hq_authority/attribution/<id>/events', self::AUTHS[5]),
            $this->api($pageId, '审计推荐事件', 'yfth/hq_authority/referral/<id>/events', self::AUTHS[6]),
        ];
        $missing = [];
        foreach ($apiRows as $row) {
            $existing = $this->rowsByAuth($row['unique_auth']);
            $this->assertNotDuplicate($existing, $row['unique_auth']);
            if (!$existing) {
                if ($recorded) {
                    throw new RuntimeException('yfth_hq_authority_readonly_forward_repair_required');
                }
                $missing[] = $row;
                continue;
            }
            $this->assertSignature($existing[0], $row, $recorded);
        }
        foreach ($missing as $row) {
            $this->insertPermission($row);
        }
    }

    public function down()
    {
        $rootId = $this->rootId();
        $pageRows = $this->rowsByAuth(self::AUTHS[0]);
        $this->assertNotDuplicate($pageRows, self::AUTHS[0]);
        if (!$pageRows) {
            throw new RuntimeException('yfth_hq_authority_readonly_down_signature_ambiguous');
        }
        $pageId = (int)$pageRows[0]['id'];
        $expected = $this->expectedRows($rootId, $pageId);
        $ids = [];
        foreach ($expected as $row) {
            $existing = $this->rowsByAuth($row['unique_auth']);
            $this->assertNotDuplicate($existing, $row['unique_auth']);
            if (!$existing) {
                throw new RuntimeException('yfth_hq_authority_readonly_down_signature_ambiguous');
            }
            $this->assertSignature($existing[0], $row, false, 'yfth_hq_authority_readonly_down_signature_ambiguous');
            $ids[] = (int)$existing[0]['id'];
        }
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `id` IN (' . implode(',', $ids) . ')');
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

    private function insertPermission(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
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

    private function expectedRows(int $rootId, int $pageId): array
    {
        $page = [
            'pid' => $rootId, 'icon' => 'md-link', 'menu_name' => '总部客户归属', 'module' => 'admin',
            'controller' => 'v1.yfth.HqAuthorityRead', 'action' => 'attributionList',
            'api_url' => 'yfth/hq_authority/attribution', 'methods' => 'GET', 'params' => '', 'sort' => 0,
            'is_show' => 1, 'is_show_path' => 1, 'access' => 1, 'menu_path' => '/yfth/hq-authority',
            'path' => (string)$rootId, 'auth_type' => 1, 'header' => 'yfth', 'is_header' => 0,
            'unique_auth' => self::AUTHS[0], 'is_del' => 0, 'mark' => 'yfth',
        ];
        return [
            $page,
            $this->api($pageId, '查看客户归属', 'yfth/hq_authority/attribution', self::AUTHS[1]),
            $this->api($pageId, '查看归属详情', 'yfth/hq_authority/attribution/<id>', self::AUTHS[2]),
            $this->api($pageId, '查看推荐关系', 'yfth/hq_authority/referral', self::AUTHS[3]),
            $this->api($pageId, '查看推荐详情', 'yfth/hq_authority/referral/<id>', self::AUTHS[4]),
            $this->api($pageId, '审计归属事件', 'yfth/hq_authority/attribution/<id>/events', self::AUTHS[5]),
            $this->api($pageId, '审计推荐事件', 'yfth/hq_authority/referral/<id>/events', self::AUTHS[6]),
        ];
    }

    private function rowsByAuth(string $auth): array
    {
        return $this->getAdapter()->fetchAll(
            'SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = ' . $this->quote($auth) . ' ORDER BY `id` ASC'
        );
    }

    private function assertNotDuplicate(array $rows, string $auth): void
    {
        if (count($rows) > 1) {
            throw new RuntimeException('yfth_hq_authority_readonly_permission_duplicate:' . $auth);
        }
    }

    private function assertSignature(
        array $actual,
        array $expected,
        bool $recorded,
        string $error = 'yfth_hq_authority_readonly_permission_signature_mismatch'
    ): void {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual) || (string)$actual[$field] !== (string)$value) {
                throw new RuntimeException($recorded ? 'yfth_hq_authority_readonly_forward_repair_required' : $error . ':' . $expected['unique_auth'] . ':' . $field);
            }
        }
    }

    private function migrationRecordExists(): bool
    {
        $table = $this->prefixed('migrations');
        $exists = $this->getAdapter()->fetchRow(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->quote($table) . ' LIMIT 1'
        );
        if (!$exists) {
            return false;
        }
        return (bool)$this->getAdapter()->fetchRow(
            'SELECT 1 FROM `' . $table . '` WHERE `version` = ' . $this->quote((string)$this->getVersion()) . ' LIMIT 1'
        );
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
