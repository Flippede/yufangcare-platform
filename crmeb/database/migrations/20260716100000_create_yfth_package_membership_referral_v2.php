<?php

use think\migration\Migrator;

class CreateYfthPackageMembershipReferralV2 extends Migrator
{
    private const TABLES = [
        'yfth_permanent_membership',
        'yfth_permanent_membership_event',
        'yfth_direct_referral_invite',
        'yfth_direct_referral_rule_version',
        'yfth_direct_referral_reward_candidate',
    ];

    private const AUTHS = [
        'yfth-package-membership-referral-index',
        'yfth-package-membership-referral-member-read',
        'yfth-package-membership-referral-candidate-read',
        'yfth-package-membership-referral-rule-read',
        'yfth-package-membership-referral-rule-save',
        'yfth-package-membership-referral-rule-publish',
        'yfth-package-membership-referral-legacy-backfill',
    ];

    public function up()
    {
        $recorded = $this->migrationRecordExists();
        if ($recorded) {
            try {
                $this->assertPackageColumns();
                $this->assertSchemaComplete();
                $this->assertPermissionsComplete();
            } catch (Throwable $e) {
                throw new RuntimeException('yfth_package_membership_referral_v2_forward_repair_required');
            }
            return;
        }

        $this->preflightPackageColumns();
        $this->preflightTables();
        $this->preflightPermissions();
        $this->addPackageColumns();
        $this->createMembership();
        $this->createMembershipEvent();
        $this->createInvite();
        $this->createRuleVersion();
        $this->createRewardCandidate();
        foreach (self::TABLES as $table) {
            $this->assertTableSignature($table);
            $this->ensureIndexes($table);
        }
        $this->seedPermissions();
        $this->assertPackageColumns();
        $this->assertSchemaComplete();
        $this->assertPermissionsComplete();
    }

    public function down()
    {
        try {
            $this->assertPackageColumns();
            $this->assertSchemaComplete();
            $permissionIds = $this->assertPermissionsComplete();
        } catch (Throwable $e) {
            throw new RuntimeException('yfth_package_membership_referral_v2_down_signature_ambiguous');
        }

        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `id` IN (' . implode(',', $permissionIds) . ')');
        foreach (array_reverse(self::TABLES) as $table) {
            $this->table($table)->drop();
        }
        $this->table('yfth_package_purchase_snapshot')->removeColumn('grants_permanent_membership')->update();
        $this->table('yfth_package_rule_version')->removeColumn('grants_permanent_membership')->update();
    }

    private function addPackageColumns(): void
    {
        if (!$this->tableHasColumn('yfth_package_rule_version', 'grants_permanent_membership')) {
            $this->table('yfth_package_rule_version')
                ->addColumn('grants_permanent_membership', 'boolean', ['signed' => false, 'default' => 0, 'after' => 'month_count', 'comment' => 'grant permanent membership after package activation'])
                ->update();
        }
        if (!$this->tableHasColumn('yfth_package_purchase_snapshot', 'grants_permanent_membership')) {
            $this->table('yfth_package_purchase_snapshot')
                ->addColumn('grants_permanent_membership', 'boolean', ['signed' => false, 'default' => 0, 'after' => 'month_count', 'comment' => 'immutable membership grant snapshot'])
                ->update();
        }
    }

    private function createMembership(): void
    {
        if ($this->hasTable('yfth_permanent_membership')) return;
        $this->table('yfth_permanent_membership', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH price-independent permanent membership authority')
            ->addColumn('membership_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_package_instance_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_purchase_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('actual_paid_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('currency', 'string', ['limit' => 8, 'default' => 'CNY'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => 'package_activation'])
            ->addColumn('activated_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
    }

    private function createMembershipEvent(): void
    {
        if ($this->hasTable('yfth_permanent_membership_event')) return;
        $this->table('yfth_permanent_membership_event', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH append-only permanent membership events')
            ->addColumn('event_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('membership_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('event_type', 'string', ['limit' => 48, 'default' => 'membership_activated'])
            ->addColumn('source_type', 'string', ['limit' => 48, 'default' => 'package_activation'])
            ->addColumn('source_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_unique_key', 'char', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('actual_paid_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('operator_role_code', 'string', ['limit' => 48, 'default' => 'system'])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
        $this->forceAscii('yfth_permanent_membership_event', 'source_unique_key');
    }

    private function createInvite(): void
    {
        if ($this->hasTable('yfth_direct_referral_invite')) return;
        $this->table('yfth_direct_referral_invite', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH one-level direct referral invitation')
            ->addColumn('invite_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('owner_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('accepted_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('relation_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('issued_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('expires_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('used_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('invalidated_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
        $this->forceAscii('yfth_direct_referral_invite', 'token_hash');
    }

    private function createRuleVersion(): void
    {
        if ($this->hasTable('yfth_direct_referral_rule_version')) return;
        $this->table('yfth_direct_referral_rule_version', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH versioned direct referral candidate rules')
            ->addColumn('rule_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('version_no', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'draft'])
            ->addColumn('package_ratio_first_bps', 'integer', ['signed' => false, 'default' => 1500])
            ->addColumn('package_ratio_second_bps', 'integer', ['signed' => false, 'default' => 2500])
            ->addColumn('package_ratio_third_bps', 'integer', ['signed' => false, 'default' => 6000])
            ->addColumn('mall_consumption_enabled', 'boolean', ['signed' => false, 'default' => 0])
            ->addColumn('mall_consumption_ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('effective_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('expires_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('active_key', 'string', ['limit' => 32, 'null' => true, 'default' => null])
            ->addColumn('created_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('published_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('published_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
    }

    private function createRewardCandidate(): void
    {
        if ($this->hasTable('yfth_direct_referral_reward_candidate')) return;
        $this->table('yfth_direct_referral_reward_candidate', ['signed' => false])
            ->setEngine('InnoDB')->setComment('YFTH direct referral reward candidates; no automatic payment')
            ->addColumn('candidate_no', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('candidate_type', 'string', ['limit' => 40, 'default' => 'package_activation'])
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('referred_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('relation_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_business_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('source_business_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_unique_key', 'char', ['limit' => 64, 'default' => ''])
            ->addColumn('reward_sequence_no', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('actual_paid_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('ratio_bps', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('reward_amount_cent', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'pending'])
            ->addColumn('responsibility_type', 'string', ['limit' => 48, 'default' => 'store_mall_revenue'])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
        $this->forceAscii('yfth_direct_referral_reward_candidate', 'source_unique_key');
    }

    private function expectedIndexes(): array
    {
        return [
            'yfth_permanent_membership' => [
                'uniq_yfth_pm_no' => [['membership_no'], true],
                'uniq_yfth_pm_uid' => [['uid'], true],
                'uniq_yfth_pm_source_instance' => [['source_package_instance_id'], true],
                'idx_yfth_pm_store_status' => [['store_id', 'status', 'uid'], false],
                'idx_yfth_pm_source_purchase' => [['source_purchase_id'], false],
            ],
            'yfth_permanent_membership_event' => [
                'uniq_yfth_pm_event_no' => [['event_no'], true],
                'uniq_yfth_pm_event_version' => [['membership_id', 'authority_version'], true],
                'uniq_yfth_pm_event_source' => [['source_unique_key'], true],
                'idx_yfth_pm_event_uid_time' => [['uid', 'add_time'], false],
            ],
            'yfth_direct_referral_invite' => [
                'uniq_yfth_direct_invite_no' => [['invite_no'], true],
                'uniq_yfth_direct_invite_hash' => [['token_hash'], true],
                'uniq_yfth_direct_invite_active' => [['active_key'], true],
                'idx_yfth_direct_invite_owner' => [['owner_uid', 'status'], false],
                'idx_yfth_direct_invite_expire' => [['status', 'expires_at'], false],
            ],
            'yfth_direct_referral_rule_version' => [
                'uniq_yfth_direct_rule_no' => [['rule_no'], true],
                'uniq_yfth_direct_rule_version' => [['version_no'], true],
                'uniq_yfth_direct_rule_active' => [['active_key'], true],
                'idx_yfth_direct_rule_status_time' => [['status', 'effective_at', 'expires_at'], false],
            ],
            'yfth_direct_referral_reward_candidate' => [
                'uniq_yfth_direct_candidate_no' => [['candidate_no'], true],
                'uniq_yfth_direct_candidate_source' => [['source_unique_key'], true],
                'uniq_yfth_direct_candidate_sequence' => [['referrer_uid', 'reward_sequence_no'], true],
                'idx_yfth_direct_candidate_store' => [['store_id', 'status', 'add_time'], false],
                'idx_yfth_direct_candidate_referrer' => [['referrer_uid', 'candidate_type', 'add_time'], false],
                'idx_yfth_direct_candidate_referred' => [['referred_uid', 'candidate_type', 'add_time'], false],
            ],
        ];
    }

    private function expectedColumns(): array
    {
        $i = ['int', null, false, true];
        $ni = ['int', null, true, true];
        $bi = ['bigint', null, false, true];
        $v8 = ['varchar', 8, false, false];
        $v24 = ['varchar', 24, false, false];
        $v32 = ['varchar', 32, true, false];
        $v40 = ['varchar', 40, false, false];
        $v48 = ['varchar', 48, false, false];
        $v64 = ['varchar', 64, false, false];
        $nv64 = ['varchar', 64, true, false];
        $c64 = ['char', 64, false, false];
        $nc64 = ['char', 64, true, false];
        return [
            'yfth_permanent_membership' => [
                'id'=>$i,'membership_no'=>$v64,'uid'=>$i,'store_id'=>$i,'source_package_instance_id'=>$i,
                'source_purchase_id'=>$i,'source_rule_version_id'=>$i,'actual_paid_amount_cent'=>$bi,'currency'=>$v8,
                'status'=>$v24,'authority_version'=>$i,'source_type'=>$v48,'activated_at'=>$i,'request_id'=>$v64,
                'add_time'=>$i,'update_time'=>$i,
            ],
            'yfth_permanent_membership_event' => [
                'id'=>$i,'event_no'=>$v64,'membership_id'=>$i,'uid'=>$i,'store_id'=>$i,'authority_version'=>$i,
                'event_type'=>$v48,'source_type'=>$v48,'source_id'=>$v64,'source_unique_key'=>$nc64,
                'actual_paid_amount_cent'=>$bi,'operator_uid'=>$i,'operator_role_code'=>$v48,'request_id'=>$v64,'add_time'=>$i,
            ],
            'yfth_direct_referral_invite' => [
                'id'=>$i,'invite_no'=>$v64,'owner_uid'=>$i,'store_id'=>$i,'token_hash'=>$c64,'status'=>$v24,
                'accepted_uid'=>$i,'relation_id'=>$i,'issued_at'=>$i,'expires_at'=>$i,'used_at'=>$i,
                'invalidated_at'=>$i,'active_key'=>$nv64,'request_id'=>$v64,'add_time'=>$i,'update_time'=>$i,
            ],
            'yfth_direct_referral_rule_version' => [
                'id'=>$i,'rule_no'=>$v64,'version_no'=>$i,'status'=>$v24,'package_ratio_first_bps'=>$i,
                'package_ratio_second_bps'=>$i,'package_ratio_third_bps'=>$i,'mall_consumption_enabled'=>['tinyint',null,false,true],
                'mall_consumption_ratio_bps'=>$i,'effective_at'=>$i,'expires_at'=>$i,'active_key'=>$v32,
                'created_uid'=>$i,'published_uid'=>$i,'published_at'=>$i,'add_time'=>$i,'update_time'=>$i,
            ],
            'yfth_direct_referral_reward_candidate' => [
                'id'=>$i,'candidate_no'=>$v64,'candidate_type'=>$v40,'referrer_uid'=>$i,'referred_uid'=>$i,
                'store_id'=>$i,'relation_id'=>$i,'source_business_type'=>$v48,'source_business_id'=>$v64,
                'source_unique_key'=>$c64,'reward_sequence_no'=>$ni,'actual_paid_amount_cent'=>$bi,
                'ratio_bps'=>$i,'reward_amount_cent'=>$bi,'rule_version_id'=>$i,'status'=>$v24,
                'responsibility_type'=>$v48,'add_time'=>$i,'update_time'=>$i,
            ],
        ];
    }

    private function preflightPackageColumns(): void
    {
        foreach (['yfth_package_rule_version', 'yfth_package_purchase_snapshot'] as $table) {
            if (!$this->hasTable($table)) {
                throw new RuntimeException('yfth_package_membership_referral_v2_package_table_missing:' . $table);
            }
            if ($this->tableHasColumn($table, 'grants_permanent_membership')) {
                $this->assertBooleanColumn($table, 'grants_permanent_membership');
            }
        }
    }

    private function assertPackageColumns(): void
    {
        foreach (['yfth_package_rule_version', 'yfth_package_purchase_snapshot'] as $table) {
            $this->assertBooleanColumn($table, 'grants_permanent_membership');
        }
    }

    private function assertBooleanColumn(string $table, string $column): void
    {
        $row = $this->getAdapter()->fetchRow(
            'SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()'
            . ' AND TABLE_NAME=' . $this->quote($this->prefixed($table)) . ' AND COLUMN_NAME=' . $this->quote($column) . ' LIMIT 1'
        );
        if (!$row || (string)$row['DATA_TYPE'] !== 'tinyint' || stripos((string)$row['COLUMN_TYPE'], 'unsigned') === false
            || (string)$row['IS_NULLABLE'] !== 'NO' || (string)$row['COLUMN_DEFAULT'] !== '0') {
            throw new RuntimeException('yfth_package_membership_referral_v2_package_column_mismatch:' . $table . ':' . $column);
        }
    }

    private function preflightTables(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) continue;
            $this->assertTableSignature($table);
            foreach ($this->expectedIndexes()[$table] as $name => $definition) {
                if ($this->indexExists($table, $name)) {
                    $this->assertIndexSignature($table, $name, $definition[0], $definition[1]);
                }
            }
        }
    }

    private function assertSchemaComplete(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->hasTable($table)) throw new RuntimeException('yfth_package_membership_referral_v2_table_missing:' . $table);
            $this->assertTableSignature($table);
            foreach ($this->expectedIndexes()[$table] as $name => $definition) {
                $this->assertIndexSignature($table, $name, $definition[0], $definition[1]);
            }
        }
    }

    private function assertTableSignature(string $table): void
    {
        $engine = $this->getAdapter()->fetchRow('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)));
        if (!$engine || strtoupper((string)$engine['ENGINE']) !== 'INNODB') {
            throw new RuntimeException('yfth_package_membership_referral_v2_engine_mismatch:' . $table);
        }
        $rows = $this->getAdapter()->fetchAll('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)));
        $actual = [];
        foreach ($rows as $row) $actual[(string)$row['COLUMN_NAME']] = $row;
        foreach ($this->expectedColumns()[$table] as $name => $expected) {
            if (!isset($actual[$name])) throw new RuntimeException('yfth_package_membership_referral_v2_column_missing:' . $table . ':' . $name);
            $row = $actual[$name];
            if ((string)$row['DATA_TYPE'] !== $expected[0]
                || ($expected[1] !== null && (int)$row['CHARACTER_MAXIMUM_LENGTH'] !== $expected[1])
                || ((string)$row['IS_NULLABLE'] === 'YES') !== $expected[2]
                || ($expected[3] && stripos((string)$row['COLUMN_TYPE'], 'unsigned') === false)) {
                throw new RuntimeException('yfth_package_membership_referral_v2_column_mismatch:' . $table . ':' . $name);
            }
            if (in_array($name, ['source_unique_key', 'token_hash'], true)
                && ((string)$row['CHARACTER_SET_NAME'] !== 'ascii' || (string)$row['COLLATION_NAME'] !== 'ascii_bin')) {
                throw new RuntimeException('yfth_package_membership_referral_v2_ascii_mismatch:' . $table . ':' . $name);
            }
        }
    }

    private function ensureIndexes(string $table): void
    {
        foreach ($this->expectedIndexes()[$table] as $name => $definition) {
            if ($this->indexExists($table, $name)) {
                $this->assertIndexSignature($table, $name, $definition[0], $definition[1]);
                continue;
            }
            if ($definition[1]) $this->assertNoDuplicates($table, $definition[0]);
            $this->table($table)->addIndex($definition[0], ['unique' => $definition[1], 'name' => $name])->update();
        }
    }

    private function assertIndexSignature(string $table, string $name, array $columns, bool $unique): void
    {
        $rows = $this->getAdapter()->fetchAll('SELECT NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)) . ' AND INDEX_NAME=' . $this->quote($name) . ' ORDER BY SEQ_IN_INDEX ASC');
        $actual = [];
        foreach ($rows as $position => $row) {
            if ((int)$row['NON_UNIQUE'] !== ($unique ? 0 : 1) || (int)$row['SEQ_IN_INDEX'] !== $position + 1 || strtoupper((string)$row['INDEX_TYPE']) !== 'BTREE') {
                throw new RuntimeException('yfth_package_membership_referral_v2_index_mismatch:' . $table . ':' . $name);
            }
            $actual[] = (string)$row['COLUMN_NAME'];
        }
        if ($actual !== array_values($columns)) throw new RuntimeException('yfth_package_membership_referral_v2_index_mismatch:' . $table . ':' . $name);
    }

    private function assertNoDuplicates(string $table, array $columns): void
    {
        $group = implode(',', array_map(function ($column) { return '`' . $column . '`'; }, $columns));
        $where = implode(' AND ', array_map(function ($column) { return '`' . $column . '` IS NOT NULL'; }, $columns));
        if ($this->getAdapter()->fetchRow('SELECT 1 FROM `' . $this->prefixed($table) . '` WHERE ' . $where . ' GROUP BY ' . $group . ' HAVING COUNT(*)>1 LIMIT 1')) {
            throw new RuntimeException('yfth_package_membership_referral_v2_unique_conflict:' . $table . ':' . implode(',', $columns));
        }
    }

    private function preflightPermissions(): void
    {
        $rootId = $this->rootId();
        $pageRows = $this->rowsByAuth(self::AUTHS[0]);
        $this->assertNotDuplicate($pageRows, self::AUTHS[0]);
        if (!$pageRows) {
            foreach (array_slice(self::AUTHS, 1) as $auth) {
                if ($this->rowsByAuth($auth)) throw new RuntimeException('yfth_package_membership_referral_v2_permission_partial_incompatible');
            }
            return;
        }
        foreach ($this->expectedPermissionRows($rootId, (int)$pageRows[0]['id']) as $expected) {
            $rows = $this->rowsByAuth($expected['unique_auth']);
            $this->assertNotDuplicate($rows, $expected['unique_auth']);
            if ($rows) $this->assertPermissionSignature($rows[0], $expected);
        }
    }

    private function seedPermissions(): void
    {
        $rootId = $this->rootId();
        $page = $this->menuRow($rootId);
        $rows = $this->rowsByAuth(self::AUTHS[0]);
        $pageId = $rows ? (int)$rows[0]['id'] : $this->insertPermission($page);
        foreach ($this->expectedPermissionRows($rootId, $pageId) as $expected) {
            $rows = $this->rowsByAuth($expected['unique_auth']);
            if (!$rows) $this->insertPermission($expected); else $this->assertPermissionSignature($rows[0], $expected);
        }
    }

    private function assertPermissionsComplete(): array
    {
        $rootId = $this->rootId();
        $pageRows = $this->rowsByAuth(self::AUTHS[0]);
        $this->assertNotDuplicate($pageRows, self::AUTHS[0]);
        if (!$pageRows) throw new RuntimeException('yfth_package_membership_referral_v2_permission_missing:' . self::AUTHS[0]);
        $ids = [];
        foreach ($this->expectedPermissionRows($rootId, (int)$pageRows[0]['id']) as $expected) {
            $rows = $this->rowsByAuth($expected['unique_auth']);
            $this->assertNotDuplicate($rows, $expected['unique_auth']);
            if (!$rows) throw new RuntimeException('yfth_package_membership_referral_v2_permission_missing:' . $expected['unique_auth']);
            $this->assertPermissionSignature($rows[0], $expected);
            $ids[] = (int)$rows[0]['id'];
        }
        return $ids;
    }

    private function expectedPermissionRows(int $rootId, int $pageId): array
    {
        return [
            $this->menuRow($rootId),
            $this->apiRow($pageId, 'View permanent memberships', 'yfth/package_membership/member', 'GET', self::AUTHS[1]),
            $this->apiRow($pageId, 'View reward candidates', 'yfth/package_membership/candidate', 'GET', self::AUTHS[2]),
            $this->apiRow($pageId, 'View direct referral rules', 'yfth/package_membership/rule', 'GET', self::AUTHS[3]),
            $this->apiRow($pageId, 'Save direct referral rules', 'yfth/package_membership/rule', 'POST', self::AUTHS[4]),
            $this->apiRow($pageId, 'Publish direct referral rules', 'yfth/package_membership/rule/<id>/publish', 'POST', self::AUTHS[5]),
            $this->apiRow($pageId, 'Backfill package memberships', 'yfth/package_membership/legacy_backfill', 'POST', self::AUTHS[6]),
        ];
    }

    private function rootId(): int
    {
        $rows = $this->getAdapter()->fetchAll('SELECT id FROM `' . $this->prefixed('system_menus') . '` WHERE unique_auth=' . $this->quote('yfth-foundation') . ' AND is_del=0 ORDER BY id ASC');
        if (count($rows) !== 1) throw new RuntimeException('yfth_foundation_menu_required');
        return (int)$rows[0]['id'];
    }

    private function menuRow(int $pid): array
    {
        return ['pid'=>$pid,'icon'=>'md-git-network','menu_name'=>'Package Membership Referral','module'=>'admin','controller'=>'v1.yfth.PackageMembershipReferral','action'=>'index','api_url'=>'yfth/package_membership/member','methods'=>'GET','params'=>'','sort'=>10,'is_show'=>1,'is_show_path'=>1,'access'=>1,'menu_path'=>'/yfth/package-membership-referral','path'=>(string)$pid,'auth_type'=>1,'header'=>'yfth','is_header'=>0,'unique_auth'=>self::AUTHS[0],'is_del'=>0,'mark'=>'yfth'];
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return ['pid'=>$pid,'icon'=>'','menu_name'=>$name,'module'=>'admin','controller'=>'v1.yfth.PackageMembershipReferral','action'=>'','api_url'=>$url,'methods'=>$method,'params'=>'','sort'=>0,'is_show'=>0,'is_show_path'=>0,'access'=>1,'menu_path'=>'','path'=>(string)$pid,'auth_type'=>2,'header'=>'yfth','is_header'=>0,'unique_auth'=>$auth,'is_del'=>0,'mark'=>'yfth'];
    }

    private function assertPermissionSignature(array $actual, array $expected): void
    {
        foreach (['pid','menu_name','module','controller','api_url','methods','auth_type','path','unique_auth','is_del'] as $field) {
            if ((string)($actual[$field] ?? '') !== (string)$expected[$field]) throw new RuntimeException('yfth_package_membership_referral_v2_permission_mismatch:' . $expected['unique_auth'] . ':' . $field);
        }
    }

    private function insertPermission(array $row): int
    {
        $fields = array_map(function ($field) { return '`' . $field . '`'; }, array_keys($row));
        $values = array_map([$this, 'quote'], array_values($row));
        $this->execute('INSERT INTO `' . $this->prefixed('system_menus') . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')');
        $created = $this->rowsByAuth($row['unique_auth']);
        return (int)$created[0]['id'];
    }

    private function rowsByAuth(string $auth): array
    {
        return $this->getAdapter()->fetchAll('SELECT * FROM `' . $this->prefixed('system_menus') . '` WHERE unique_auth=' . $this->quote($auth) . ' ORDER BY id ASC');
    }

    private function assertNotDuplicate(array $rows, string $auth): void
    {
        if (count($rows) > 1) throw new RuntimeException('yfth_package_membership_referral_v2_permission_duplicate:' . $auth);
    }

    private function preflightPackageTable(string $table): void
    {
        if (!$this->hasTable($table)) throw new RuntimeException('yfth_package_membership_referral_v2_package_table_missing:' . $table);
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return (bool)$this->getAdapter()->fetchRow('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)) . ' AND COLUMN_NAME=' . $this->quote($column) . ' LIMIT 1');
    }

    private function indexExists(string $table, string $name): bool
    {
        return (bool)$this->getAdapter()->fetchRow('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($this->prefixed($table)) . ' AND INDEX_NAME=' . $this->quote($name) . ' LIMIT 1');
    }

    private function forceAscii(string $table, string $column): void
    {
        $nullable = $column === 'source_unique_key' && $table === 'yfth_permanent_membership_event';
        $this->execute('ALTER TABLE `' . $this->prefixed($table) . '` MODIFY `' . $column . '` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin ' . ($nullable ? 'NULL DEFAULT NULL' : "NOT NULL DEFAULT ''"));
    }

    private function migrationRecordExists(): bool
    {
        $table = $this->prefixed('migrations');
        if (!$this->getAdapter()->fetchRow('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $this->quote($table) . ' LIMIT 1')) return false;
        return (bool)$this->getAdapter()->fetchRow('SELECT 1 FROM `' . $table . '` WHERE version=' . $this->quote((string)$this->getVersion()) . ' LIMIT 1');
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote($value): string
    {
        if ($value === null) return 'NULL';
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
