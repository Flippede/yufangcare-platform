<?php

use think\migration\Migrator;

class CreateYfthPermanentMembershipTables extends Migrator
{
    private const TABLES = [
        'yfth_permanent_membership_enrollment',
        'yfth_permanent_membership',
        'yfth_permanent_membership_event',
        'yfth_business_dynamic_code',
        'yfth_membership_reward_candidate',
    ];

    private const AUTHS = [
        'yfth-permanent-membership-index',
        'yfth-permanent-membership-enrollment-read',
        'yfth-permanent-membership-member-read',
        'yfth-permanent-membership-enrollment-create',
        'yfth-permanent-membership-enrollment-bind',
        'yfth-permanent-membership-payment-confirm',
        'yfth-permanent-membership-confirmation-code',
    ];

    public function up()
    {
        $recorded = $this->migrationRecordExists();
        if ($recorded) {
            try {
                $this->assertSchemaComplete();
                $this->assertPermissionsComplete();
            } catch (Throwable $e) {
                throw new RuntimeException('yfth_permanent_membership_forward_repair_required');
            }
            return;
        }

        $this->preflightTables();
        $this->preflightPermissions();
        $this->createEnrollment();
        $this->createMembership();
        $this->createMembershipEvent();
        $this->createDynamicCode();
        $this->createRewardCandidate();
        foreach (self::TABLES as $table) {
            $this->assertTableColumns($table);
            $this->ensureIndexes($table);
        }
        $this->seedPermissions();
        $this->assertSchemaComplete();
        $this->assertPermissionsComplete();
    }

    public function down()
    {
        try {
            $this->assertSchemaComplete();
            $permissionIds = $this->assertPermissionsComplete();
        } catch (Throwable $e) {
            throw new RuntimeException('yfth_permanent_membership_down_signature_ambiguous');
        }
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `id` IN (' . implode(',', $permissionIds) . ')');
        foreach ([
            'yfth_membership_reward_candidate',
            'yfth_business_dynamic_code',
            'yfth_permanent_membership_event',
            'yfth_permanent_membership',
            'yfth_permanent_membership_enrollment',
        ] as $table) {
            $this->table($table)->drop();
        }
    }

    private function createEnrollment(): void
    {
        if ($this->hasTable('yfth_permanent_membership_enrollment')) return;
        $this->table('yfth_permanent_membership_enrollment', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH offline permanent membership enrollment')
            ->addColumn('enrollment_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('target_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 40, 'default' => 'draft'])
            ->addColumn('amount_cents', 'biginteger', ['signed' => false, 'default' => 980000])
            ->addColumn('payment_status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('target_bound_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('payment_confirmed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('activated_member_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('activated_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('created_by_type', 'string', ['limit' => 24, 'default' => 'store_user'])
            ->addColumn('created_by_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('created_by_role', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('active_target_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['enrollment_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_enrollment_no'])
            ->addIndex(['active_target_key'], ['unique' => true, 'name' => 'uniq_yfth_pm_enrollment_target'])
            ->addIndex(['store_id', 'status', 'add_time'], ['name' => 'idx_yfth_pm_enrollment_store'])
            ->addIndex(['target_uid', 'status'], ['name' => 'idx_yfth_pm_enrollment_uid'])
            ->create();
    }

    private function createMembership(): void
    {
        if ($this->hasTable('yfth_permanent_membership')) return;
        $this->table('yfth_permanent_membership', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH permanent membership authority')
            ->addColumn('membership_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('enrollment_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('amount_cents', 'biginteger', ['signed' => false, 'default' => 980000])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('activated_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['membership_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_no'])
            ->addIndex(['uid'], ['unique' => true, 'name' => 'uniq_yfth_pm_uid'])
            ->addIndex(['enrollment_id'], ['unique' => true, 'name' => 'uniq_yfth_pm_enrollment'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_pm_store'])
            ->create();
    }

    private function createMembershipEvent(): void
    {
        if ($this->hasTable('yfth_permanent_membership_event')) return;
        $this->table('yfth_permanent_membership_event', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH permanent membership authority events')
            ->addColumn('event_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('membership_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('membership_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('event_type', 'string', ['limit' => 48, 'default' => 'membership_activated'])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('operator_role_code', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['event_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_event_no'])
            ->addIndex(['membership_id', 'authority_version'], ['unique' => true, 'name' => 'uniq_yfth_pm_event_version'])
            ->addIndex(['uid', 'add_time'], ['name' => 'idx_yfth_pm_event_uid'])
            ->create();
    }

    private function createDynamicCode(): void
    {
        if ($this->hasTable('yfth_business_dynamic_code')) return;
        $this->table('yfth_business_dynamic_code', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH business-scoped single-use dynamic codes')
            ->addColumn('code_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('scene', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('enrollment_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('target_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'issued'])
            ->addColumn('issued_by_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('issued_by_role', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('used_by_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('used_by_role', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('issued_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('used_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('invalidated_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 128, 'null' => true, 'default' => null])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['code_no'], ['unique' => true, 'name' => 'uniq_yfth_business_code_no'])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uniq_yfth_business_code_hash'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_business_code_active'])
            ->addIndex(['target_uid', 'scene', 'status'], ['name' => 'idx_yfth_business_code_uid'])
            ->addIndex(['enrollment_id', 'scene', 'status'], ['name' => 'idx_yfth_business_code_enrollment'])
            ->create();
        $this->execute('ALTER TABLE `' . $this->prefixed('yfth_business_dynamic_code') . '` MODIFY `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ' . $this->quote(''));
    }

    private function createRewardCandidate(): void
    {
        if ($this->hasTable('yfth_membership_reward_candidate')) return;
        $this->table('yfth_membership_reward_candidate', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH amount-free permanent membership reward candidate fact')
            ->addColumn('candidate_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('business_type', 'string', ['limit' => 48, 'default' => 'permanent_membership_activated'])
            ->addColumn('membership_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('enrollment_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('target_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('unique_key', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->addIndex(['candidate_no'], ['unique' => true, 'name' => 'uniq_yfth_pm_candidate_no'])
            ->addIndex(['unique_key'], ['unique' => true, 'name' => 'uniq_yfth_pm_candidate_key'])
            ->addIndex(['store_id', 'status'], ['name' => 'idx_yfth_pm_candidate_store'])
            ->create();
    }

    private function preflightTables(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $this->assertTableEngine($table);
            $this->assertTableColumns($table);
            foreach ($this->expectedIndexes()[$table] as $name => $definition) {
                if ($this->indexExists($table, $name)) {
                    $this->assertIndexSignature($table, $name, $definition['columns'], $definition['unique']);
                }
            }
        }
    }

    private function preflightPermissions(): void
    {
        $rootId = $this->rootId();
        $pageRows = $this->rowsByAuth(self::AUTHS[0]);
        $this->assertNotDuplicate($pageRows, self::AUTHS[0]);
        if (!$pageRows) {
            foreach (array_slice(self::AUTHS, 1) as $auth) {
                if ($this->rowsByAuth($auth)) {
                    throw new RuntimeException('yfth_permanent_membership_permission_partial_incompatible');
                }
            }
            return;
        }
        $page = $this->menuRow($rootId, '永久会员办理', 'yfth/permanent-membership', self::AUTHS[0]);
        $this->assertPermissionSignature($pageRows[0], $page);
        foreach ($this->expectedPermissionRows($rootId, (int)$pageRows[0]['id']) as $row) {
            $existing = $this->rowsByAuth($row['unique_auth']);
            $this->assertNotDuplicate($existing, $row['unique_auth']);
            if ($existing) {
                $this->assertPermissionSignature($existing[0], $row);
            }
        }
    }

    private function seedPermissions(): void
    {
        $rootId = $this->rootId();
        $pageRows = $this->rowsByAuth(self::AUTHS[0]);
        $this->assertNotDuplicate($pageRows, self::AUTHS[0]);
        $page = $this->menuRow($rootId, '永久会员办理', 'yfth/permanent-membership', self::AUTHS[0]);
        if (!$pageRows) {
            $pageId = $this->insertPermission($page);
        } else {
            $this->assertPermissionSignature($pageRows[0], $page);
            $pageId = (int)$pageRows[0]['id'];
        }
        foreach ($this->expectedPermissionRows($rootId, $pageId) as $row) {
            $existing = $this->rowsByAuth($row['unique_auth']);
            $this->assertNotDuplicate($existing, $row['unique_auth']);
            if (!$existing) {
                $this->insertPermission($row);
                continue;
            }
            $this->assertPermissionSignature($existing[0], $row);
        }
    }

    private function assertPermissionsComplete(): array
    {
        $rootId = $this->rootId();
        $pageRows = $this->rowsByAuth(self::AUTHS[0]);
        $this->assertNotDuplicate($pageRows, self::AUTHS[0]);
        if (!$pageRows) {
            throw new RuntimeException('yfth_permanent_membership_permission_missing:' . self::AUTHS[0]);
        }
        $pageId = (int)$pageRows[0]['id'];
        $ids = [];
        foreach ($this->expectedPermissionRows($rootId, $pageId) as $row) {
            $existing = $this->rowsByAuth($row['unique_auth']);
            $this->assertNotDuplicate($existing, $row['unique_auth']);
            if (!$existing) {
                throw new RuntimeException('yfth_permanent_membership_permission_missing:' . $row['unique_auth']);
            }
            $this->assertPermissionSignature($existing[0], $row);
            $ids[] = (int)$existing[0]['id'];
        }
        return $ids;
    }

    private function expectedPermissionRows(int $rootId, int $pageId): array
    {
        return [
            $this->menuRow($rootId, '永久会员办理', 'yfth/permanent-membership', self::AUTHS[0]),
            $this->apiRow($pageId, '查看会员办理', 'yfth/permanent_membership/enrollment', 'GET', self::AUTHS[1]),
            $this->apiRow($pageId, '查看永久会员', 'yfth/permanent_membership/member', 'GET', self::AUTHS[2]),
            $this->apiRow($pageId, '创建会员办理', 'yfth/permanent_membership/enrollment', 'POST', self::AUTHS[3]),
            $this->apiRow($pageId, '绑定办理顾客', 'yfth/permanent_membership/enrollment/<id>/bind', 'POST', self::AUTHS[4]),
            $this->apiRow($pageId, '确认线下收款', 'yfth/permanent_membership/enrollment/<id>/payment', 'POST', self::AUTHS[5]),
            $this->apiRow($pageId, '生成会员确认码', 'yfth/permanent_membership/enrollment/<id>/confirmation_code', 'POST', self::AUTHS[6]),
        ];
    }

    private function rootId(): int
    {
        $rows = $this->getAdapter()->fetchAll(
            'SELECT `id` FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = '
            . $this->quote('yfth-foundation') . ' AND `is_del` = 0 ORDER BY `id` ASC'
        );
        if (count($rows) !== 1) {
            throw new RuntimeException('yfth_foundation_menu_required');
        }
        return (int)$rows[0]['id'];
    }

    private function menuRow(int $pid, string $name, string $url, string $auth): array
    {
        return ['pid'=>$pid,'icon'=>'md-contacts','menu_name'=>$name,'module'=>'admin','controller'=>'v1.yfth.PermanentMembership','action'=>'index','api_url'=>'yfth/permanent_membership/enrollment','methods'=>'GET','params'=>'','sort'=>11,'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'/yfth/permanent-membership','path'=>(string)$pid,'auth_type'=>1,'header'=>'yfth','is_header'=>0,'unique_auth'=>$auth,'is_del'=>0,'mark'=>'yfth'];
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return ['pid'=>$pid,'icon'=>'','menu_name'=>$name,'module'=>'admin','controller'=>'v1.yfth.PermanentMembership','action'=>'','api_url'=>$url,'methods'=>$method,'params'=>'','sort'=>0,'is_show'=>0,'is_show_path'=>0,'access'=>1,'menu_path'=>'','path'=>(string)$pid,'auth_type'=>2,'header'=>'yfth','is_header'=>0,'unique_auth'=>$auth,'is_del'=>0,'mark'=>'yfth'];
    }

    private function insertPermission(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function rowsByAuth(string $auth): array
    {
        return $this->getAdapter()->fetchAll(
            'SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` = '
            . $this->quote($auth) . ' ORDER BY `id` ASC'
        );
    }

    private function assertNotDuplicate(array $rows, string $auth): void
    {
        if (count($rows) > 1) {
            throw new RuntimeException('yfth_permanent_membership_permission_duplicate:' . $auth);
        }
    }

    private function assertPermissionSignature(array $actual, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual) || (string)$actual[$field] !== (string)$value) {
                throw new RuntimeException('yfth_permanent_membership_permission_signature_mismatch:' . $expected['unique_auth'] . ':' . $field);
            }
        }
    }

    private function ensureIndexes(string $table): void
    {
        foreach ($this->expectedIndexes()[$table] as $name => $definition) {
            if ($this->indexExists($table, $name)) {
                $this->assertIndexSignature($table, $name, $definition['columns'], $definition['unique']);
                continue;
            }
            if ($definition['unique']) {
                $this->assertNoDuplicates($table, $definition['columns']);
            }
            if ($name === 'PRIMARY') {
                $columns = implode(',', array_map(function ($column) {
                    return '`' . $column . '`';
                }, $definition['columns']));
                $this->execute('ALTER TABLE `' . $this->prefixed($table) . '` ADD PRIMARY KEY (' . $columns . ')');
            } else {
                $this->table($table)->addIndex($definition['columns'], [
                    'unique' => $definition['unique'],
                    'name' => $name,
                ])->update();
            }
            $this->assertIndexSignature($table, $name, $definition['columns'], $definition['unique']);
        }
    }

    private function expectedIndexes(): array
    {
        $definitions = [
            'yfth_permanent_membership_enrollment' => [
                ['PRIMARY', ['id'], true],
                ['uniq_yfth_pm_enrollment_no', ['enrollment_no'], true],
                ['uniq_yfth_pm_enrollment_target', ['active_target_key'], true],
                ['idx_yfth_pm_enrollment_store', ['store_id', 'status', 'add_time'], false],
                ['idx_yfth_pm_enrollment_uid', ['target_uid', 'status'], false],
            ],
            'yfth_permanent_membership' => [
                ['PRIMARY', ['id'], true],
                ['uniq_yfth_pm_no', ['membership_no'], true],
                ['uniq_yfth_pm_uid', ['uid'], true],
                ['uniq_yfth_pm_enrollment', ['enrollment_id'], true],
                ['idx_yfth_pm_store', ['store_id', 'status'], false],
            ],
            'yfth_permanent_membership_event' => [
                ['PRIMARY', ['id'], true],
                ['uniq_yfth_pm_event_no', ['event_no'], true],
                ['uniq_yfth_pm_event_version', ['membership_id', 'authority_version'], true],
                ['idx_yfth_pm_event_uid', ['uid', 'add_time'], false],
            ],
            'yfth_business_dynamic_code' => [
                ['PRIMARY', ['id'], true],
                ['uniq_yfth_business_code_no', ['code_no'], true],
                ['uniq_yfth_business_code_hash', ['token_hash'], true],
                ['uniq_yfth_business_code_active', ['active_key'], true],
                ['idx_yfth_business_code_uid', ['target_uid', 'scene', 'status'], false],
                ['idx_yfth_business_code_enrollment', ['enrollment_id', 'scene', 'status'], false],
            ],
            'yfth_membership_reward_candidate' => [
                ['PRIMARY', ['id'], true],
                ['uniq_yfth_pm_candidate_no', ['candidate_no'], true],
                ['uniq_yfth_pm_candidate_key', ['unique_key'], true],
                ['idx_yfth_pm_candidate_store', ['store_id', 'status'], false],
            ],
        ];
        $result = [];
        foreach ($definitions as $table => $indexes) {
            foreach ($indexes as [$name, $columns, $unique]) {
                $result[$table][$name] = ['columns' => $columns, 'unique' => $unique];
            }
        }
        return $result;
    }

    private function indexExists(string $table, string $name): bool
    {
        return (bool)$this->getAdapter()->fetchRow(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . $this->quote($this->prefixed($table)) . ' AND INDEX_NAME = ' . $this->quote($name) . ' LIMIT 1'
        );
    }

    private function assertIndexSignature(string $table, string $name, array $columns, bool $unique): void
    {
        $rows = $this->getAdapter()->fetchAll(
            'SELECT NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->quote($this->prefixed($table))
            . ' AND INDEX_NAME = ' . $this->quote($name) . ' ORDER BY SEQ_IN_INDEX ASC'
        );
        if (!$rows) {
            throw new RuntimeException('yfth_permanent_membership_missing_index:' . $table . ':' . $name);
        }
        $actualColumns = [];
        foreach ($rows as $position => $row) {
            if ((int)$row['NON_UNIQUE'] !== ($unique ? 0 : 1)
                || (int)$row['SEQ_IN_INDEX'] !== $position + 1
                || strtoupper((string)$row['INDEX_TYPE']) !== 'BTREE') {
                throw new RuntimeException('yfth_permanent_membership_index_signature_mismatch:' . $table . ':' . $name);
            }
            $actualColumns[] = (string)$row['COLUMN_NAME'];
        }
        if ($actualColumns !== array_values($columns)) {
            throw new RuntimeException('yfth_permanent_membership_index_signature_mismatch:' . $table . ':' . $name);
        }
    }

    private function assertNoDuplicates(string $table, array $columns): void
    {
        $where = implode(' AND ', array_map(function ($column) {
            return '`' . $column . '` IS NOT NULL';
        }, $columns));
        $group = implode(',', array_map(function ($column) {
            return '`' . $column . '`';
        }, $columns));
        if ($this->getAdapter()->fetchRow(
            'SELECT 1 FROM `' . $this->prefixed($table) . '` WHERE ' . $where
            . ' GROUP BY ' . $group . ' HAVING COUNT(*) > 1 LIMIT 1'
        )) {
            throw new RuntimeException('yfth_permanent_membership_unique_conflict:' . $table . ':' . implode(',', $columns));
        }
    }

    private function assertTableEngine(string $table): void
    {
        $row = $this->getAdapter()->fetchRow(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . $this->quote($this->prefixed($table)) . ' LIMIT 1'
        );
        if (!$row || strtoupper((string)$row['ENGINE']) !== 'INNODB') {
            throw new RuntimeException('yfth_permanent_membership_table_engine_mismatch:' . $table);
        }
    }

    private function assertTableColumns(string $table): void
    {
        $rows = $this->getAdapter()->fetchAll(
            'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_MAXIMUM_LENGTH,'
            . 'CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->quote($this->prefixed($table))
        );
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string)$row['COLUMN_NAME']] = $row;
        }
        foreach ($this->expectedColumns()[$table] as $name => $expected) {
            if (!isset($actual[$name])) {
                throw new RuntimeException('yfth_permanent_membership_missing_column:' . $table . ':' . $name);
            }
            $row = $actual[$name];
            if ((string)$row['DATA_TYPE'] !== $expected['type']) {
                throw new RuntimeException('yfth_permanent_membership_column_type_mismatch:' . $table . ':' . $name);
            }
            if ($expected['length'] !== null && (int)$row['CHARACTER_MAXIMUM_LENGTH'] !== $expected['length']) {
                throw new RuntimeException('yfth_permanent_membership_column_length_mismatch:' . $table . ':' . $name);
            }
            if (((string)$row['IS_NULLABLE'] === 'YES') !== $expected['nullable']) {
                throw new RuntimeException('yfth_permanent_membership_column_nullability_mismatch:' . $table . ':' . $name);
            }
            if ($expected['unsigned'] && stripos((string)$row['COLUMN_TYPE'], 'unsigned') === false) {
                throw new RuntimeException('yfth_permanent_membership_column_unsigned_mismatch:' . $table . ':' . $name);
            }
            if ($row['COLUMN_DEFAULT'] !== $expected['default']
                && (string)$row['COLUMN_DEFAULT'] !== (string)$expected['default']) {
                throw new RuntimeException('yfth_permanent_membership_column_default_mismatch:' . $table . ':' . $name);
            }
            if ((stripos((string)$row['EXTRA'], 'auto_increment') !== false) !== $expected['auto_increment']) {
                throw new RuntimeException('yfth_permanent_membership_column_extra_mismatch:' . $table . ':' . $name);
            }
            if ($expected['charset'] !== null
                && ((string)$row['CHARACTER_SET_NAME'] !== $expected['charset']
                    || (string)$row['COLLATION_NAME'] !== $expected['collation'])) {
                throw new RuntimeException('yfth_permanent_membership_column_collation_mismatch:' . $table . ':' . $name);
            }
        }
    }

    private function expectedColumns(): array
    {
        $id = $this->column('int', null, false, true, null, true);
        $i = $this->column('int', null, false, true, '0');
        $big9800 = $this->column('bigint', null, false, true, '980000');
        $v24 = function (string $default = '') { return $this->column('varchar', 24, false, false, $default); };
        $v40 = function (string $default = '') { return $this->column('varchar', 40, false, false, $default); };
        $v48 = function (string $default = '') { return $this->column('varchar', 48, false, false, $default); };
        $v64 = function (string $default = '') { return $this->column('varchar', 64, false, false, $default); };
        $v128 = function (string $default = '') { return $this->column('varchar', 128, false, false, $default); };
        return [
            'yfth_permanent_membership_enrollment' => [
                'id'=>$id,'enrollment_no'=>$v64(),'store_id'=>$i,'target_uid'=>$i,'status'=>$v40('draft'),
                'amount_cents'=>$big9800,'payment_status'=>$v24('pending'),'target_bound_at'=>$i,
                'payment_confirmed_at'=>$i,'activated_member_id'=>$i,'activated_at'=>$i,
                'created_by_type'=>$v24('store_user'),'created_by_id'=>$i,'created_by_role'=>$v40(),
                'active_target_key'=>$this->column('varchar',64,true,false,null),'request_id'=>$v64(),
                'add_time'=>$i,'update_time'=>$i,
            ],
            'yfth_permanent_membership' => [
                'id'=>$id,'membership_no'=>$v64(),'uid'=>$i,'store_id'=>$i,'enrollment_id'=>$i,
                'status'=>$v24('active'),'amount_cents'=>$big9800,'authority_version'=>$this->column('int',null,false,true,'1'),
                'source_type'=>$v64(),'source_id'=>$v64(),'activated_at'=>$i,'request_id'=>$v64(),
                'add_time'=>$i,'update_time'=>$i,
            ],
            'yfth_permanent_membership_event' => [
                'id'=>$id,'event_no'=>$v64(),'membership_id'=>$i,'membership_no'=>$v64(),'uid'=>$i,
                'store_id'=>$i,'authority_version'=>$this->column('int',null,false,true,'1'),
                'event_type'=>$v48('membership_activated'),'source_type'=>$v64(),'source_id'=>$v64(),
                'operator_uid'=>$i,'operator_role_code'=>$v64(),'request_id'=>$v64(),'add_time'=>$i,
            ],
            'yfth_business_dynamic_code' => [
                'id'=>$id,'code_no'=>$v64(),'scene'=>$v48(),'enrollment_id'=>$i,'target_uid'=>$i,
                'store_id'=>$i,'token_hash'=>$this->column('char',64,false,false,'',false,'ascii','ascii_bin'),
                'status'=>$v24('issued'),'issued_by_uid'=>$i,'issued_by_role'=>$v40(),'used_by_uid'=>$i,
                'used_by_role'=>$v40(),'issued_time'=>$i,'expire_time'=>$i,'used_time'=>$i,
                'invalidated_time'=>$i,'active_key'=>$this->column('varchar',128,true,false,null),
                'request_id'=>$v64(),'add_time'=>$i,'update_time'=>$i,
            ],
            'yfth_membership_reward_candidate' => [
                'id'=>$id,'candidate_no'=>$v64(),'business_type'=>$v48('permanent_membership_activated'),
                'membership_id'=>$i,'enrollment_id'=>$i,'store_id'=>$i,'target_uid'=>$i,
                'source_type'=>$v64(),'source_id'=>$v64(),'status'=>$v24('pending'),
                'unique_key'=>$v128(),'add_time'=>$i,'update_time'=>$i,
            ],
        ];
    }

    private function column(
        string $type,
        ?int $length,
        bool $nullable,
        bool $unsigned,
        $default,
        bool $autoIncrement = false,
        ?string $charset = null,
        ?string $collation = null
    ): array {
        return [
            'type' => $type,
            'length' => $length,
            'nullable' => $nullable,
            'unsigned' => $unsigned,
            'default' => $default,
            'auto_increment' => $autoIncrement,
            'charset' => $charset,
            'collation' => $collation,
        ];
    }

    private function assertSchemaComplete(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) {
                throw new RuntimeException('yfth_permanent_membership_table_missing:' . $table);
            }
            $this->assertTableEngine($table);
            $this->assertTableColumns($table);
            foreach ($this->expectedIndexes()[$table] as $name => $definition) {
                $this->assertIndexSignature($table, $name, $definition['columns'], $definition['unique']);
            }
        }
    }

    private function migrationRecordExists(): bool
    {
        $table = $this->prefixed('migrations');
        $exists = $this->getAdapter()->fetchRow(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . $this->quote($table) . ' LIMIT 1'
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
        if (is_int($value) || is_float($value)) return (string)$value;
        if ($value === null) return 'NULL';
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        return (method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '') . $table;
    }
}
