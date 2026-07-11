<?php

use think\migration\Migrator;

class CreateYfthHqAuthorityFoundationTables extends Migrator
{
    private const TABLES = [
        'yfth_hq_customer_attribution_current',
        'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current',
        'yfth_hq_active_referral_event',
    ];

    public function up()
    {
        if ($this->migrationRecordExists()) {
            if (!$this->schemaComplete()) {
                throw new RuntimeException('yfth_hq_authority_forward_repair_required');
            }
            return;
        }

        foreach (self::TABLES as $table) {
            if ($this->hasTable($table)) {
                $this->assertTableColumns($table);
            }
        }

        $this->createAttributionCurrent();
        $this->createAttributionEvent();
        $this->createReferralCurrent();
        $this->createReferralEvent();

        foreach (self::TABLES as $table) {
            $this->assertTableColumns($table);
            $this->ensureIndexes($table);
        }
        if (!$this->schemaComplete()) {
            throw new RuntimeException('yfth_hq_authority_schema_incomplete');
        }
    }

    public function down()
    {
        foreach ([
            'yfth_hq_active_referral_event',
            'yfth_hq_customer_attribution_event',
            'yfth_hq_active_referral_current',
            'yfth_hq_customer_attribution_current',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createAttributionCurrent(): void
    {
        if ($this->hasTable('yfth_hq_customer_attribution_current')) {
            return;
        }
        $this->table('yfth_hq_customer_attribution_current', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('YFTH headquarters permanent customer-store attribution authority')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'unassigned'])
            ->addColumn('status_reason_code', 'string', ['limit' => 64, 'default' => 'initial_placeholder'])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('bound_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('paused_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('closed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('close_reason', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
    }

    private function createAttributionEvent(): void
    {
        if ($this->hasTable('yfth_hq_customer_attribution_event')) {
            return;
        }
        $this->table('yfth_hq_customer_attribution_event', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('YFTH append-only customer attribution authority events')
            ->addColumn('event_no', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('attribution_current_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('authority_version', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('event_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('before_store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('after_store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('before_status', 'string', ['limit' => 24, 'default' => ''])
            ->addColumn('after_status', 'string', ['limit' => 24, 'default' => ''])
            ->addColumn('before_status_reason_code', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('after_status_reason_code', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('source_unique_key', 'char', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('operator_role_code', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
        $this->forceAsciiSourceKey('yfth_hq_customer_attribution_event');
    }

    private function createReferralCurrent(): void
    {
        if ($this->hasTable('yfth_hq_active_referral_current')) {
            return;
        }
        $this->table('yfth_hq_active_referral_current', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('YFTH active first-level referral relationship authority')
            ->addColumn('relation_no', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('referred_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('attribution_current_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
            ->addColumn('active_referred_uid', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('source_unique_key', 'char', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('started_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('paused_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('closed_at', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('close_reason', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('relation_version', 'integer', ['signed' => false, 'default' => 1])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
        $this->forceAsciiSourceKey('yfth_hq_active_referral_current');
    }

    private function createReferralEvent(): void
    {
        if ($this->hasTable('yfth_hq_active_referral_event')) {
            return;
        }
        $this->table('yfth_hq_active_referral_event', ['signed' => false])
            ->setEngine('InnoDB')
            ->setComment('YFTH append-only first-level referral authority events')
            ->addColumn('event_no', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('referral_current_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('relation_no', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('relation_version', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('referrer_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('referred_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('event_type', 'string', ['limit' => 48, 'default' => ''])
            ->addColumn('before_status', 'string', ['limit' => 24, 'default' => ''])
            ->addColumn('after_status', 'string', ['limit' => 24, 'default' => ''])
            ->addColumn('source_type', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_id', 'string', ['limit' => 128, 'default' => ''])
            ->addColumn('source_unique_key', 'char', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('operator_role_code', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('request_id', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0])
            ->create();
        $this->forceAsciiSourceKey('yfth_hq_active_referral_event');
    }

    private function ensureIndexes(string $table): void
    {
        $definitions = [
            'yfth_hq_customer_attribution_current' => [
                ['uniq_yfth_hq_attr_current_uid', ['uid'], true],
                ['idx_yfth_hq_attr_store_status_uid', ['store_id', 'status', 'uid'], false],
                ['idx_yfth_hq_attr_status_update', ['status', 'update_time'], false],
            ],
            'yfth_hq_customer_attribution_event' => [
                ['uniq_yfth_hq_attr_event_no', ['event_no'], true],
                ['uniq_yfth_hq_attr_event_version', ['attribution_current_id', 'authority_version'], true],
                ['uniq_yfth_hq_attr_event_source', ['source_unique_key'], true],
                ['idx_yfth_hq_attr_event_uid_time', ['uid', 'add_time'], false],
                ['idx_yfth_hq_attr_event_type_time', ['event_type', 'add_time'], false],
                ['idx_yfth_hq_attr_event_source', ['source_type', 'source_id'], false],
            ],
            'yfth_hq_active_referral_current' => [
                ['uniq_yfth_hq_ref_current_no', ['relation_no'], true],
                ['uniq_yfth_hq_ref_current_active_uid', ['active_referred_uid'], true],
                ['uniq_yfth_hq_ref_current_source', ['source_unique_key'], true],
                ['idx_yfth_hq_ref_current_referrer', ['referrer_uid', 'status'], false],
                ['idx_yfth_hq_ref_current_referred', ['referred_uid', 'status'], false],
                ['idx_yfth_hq_ref_current_store', ['store_id', 'status', 'referred_uid'], false],
                ['idx_yfth_hq_ref_current_status_time', ['status', 'update_time'], false],
            ],
            'yfth_hq_active_referral_event' => [
                ['uniq_yfth_hq_ref_event_no', ['event_no'], true],
                ['uniq_yfth_hq_ref_event_version', ['referral_current_id', 'relation_version'], true],
                ['uniq_yfth_hq_ref_event_source', ['source_unique_key'], true],
                ['idx_yfth_hq_ref_event_referrer_time', ['referrer_uid', 'add_time'], false],
                ['idx_yfth_hq_ref_event_referred_time', ['referred_uid', 'add_time'], false],
                ['idx_yfth_hq_ref_event_type_time', ['event_type', 'add_time'], false],
            ],
        ];
        foreach ($definitions[$table] as [$name, $columns, $unique]) {
            if ($this->getAdapter()->hasIndexByName($table, $name)) {
                continue;
            }
            if ($unique) {
                $this->assertNoDuplicates($table, $columns);
            }
            $this->table($table)->addIndex($columns, ['unique' => $unique, 'name' => $name])->update();
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
        $sql = 'SELECT 1 FROM `' . $this->prefixed($table) . '` WHERE ' . $where
            . ' GROUP BY ' . $group . ' HAVING COUNT(*) > 1 LIMIT 1';
        if ($this->getAdapter()->fetchRow($sql)) {
            throw new RuntimeException('yfth_hq_authority_unique_conflict:' . $table . ':' . implode(',', $columns));
        }
    }

    private function forceAsciiSourceKey(string $table): void
    {
        $this->execute('ALTER TABLE `' . $this->prefixed($table) . '` MODIFY `source_unique_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL');
    }

    private function assertTableColumns(string $table): void
    {
        $rows = $this->getAdapter()->fetchAll(
            'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->quote($this->prefixed($table))
        );
        $actual = [];
        foreach ($rows as $row) {
            $actual[$row['COLUMN_NAME']] = $row;
        }
        foreach ($this->expectedColumns()[$table] as $name => $expected) {
            if (!isset($actual[$name])) {
                throw new RuntimeException('yfth_hq_authority_missing_column:' . $table . ':' . $name);
            }
            $row = $actual[$name];
            if ((string)$row['DATA_TYPE'] !== $expected[0]) {
                throw new RuntimeException('yfth_hq_authority_column_type_mismatch:' . $table . ':' . $name);
            }
            if (isset($expected[1]) && (int)$row['CHARACTER_MAXIMUM_LENGTH'] !== $expected[1]) {
                throw new RuntimeException('yfth_hq_authority_column_length_mismatch:' . $table . ':' . $name);
            }
            $nullable = (string)$row['IS_NULLABLE'] === 'YES';
            if ($nullable !== ($expected[2] ?? false)) {
                throw new RuntimeException('yfth_hq_authority_column_nullability_mismatch:' . $table . ':' . $name);
            }
            if (($expected[3] ?? false) && stripos((string)$row['COLUMN_TYPE'], 'unsigned') === false) {
                throw new RuntimeException('yfth_hq_authority_column_unsigned_mismatch:' . $table . ':' . $name);
            }
            if ($name === 'source_unique_key'
                && ((string)$row['CHARACTER_SET_NAME'] !== 'ascii' || (string)$row['COLLATION_NAME'] !== 'ascii_bin')) {
                throw new RuntimeException('yfth_hq_authority_source_key_collation_mismatch:' . $table);
            }
        }
    }

    private function expectedColumns(): array
    {
        $i = ['int', null, false, true];
        $nullableInt = ['int', null, true, true];
        $v24 = ['varchar', 24, false, false];
        $v48 = ['varchar', 48, false, false];
        $v64 = ['varchar', 64, false, false];
        $v128 = ['varchar', 128, false, false];
        $v255 = ['varchar', 255, false, false];
        $key = ['char', 64, true, false];
        return [
            'yfth_hq_customer_attribution_current' => [
                'id' => $i, 'uid' => $i, 'store_id' => $i, 'status' => $v24,
                'status_reason_code' => $v64, 'authority_version' => $i, 'source_type' => $v64,
                'source_id' => $v128, 'bound_at' => $i, 'paused_at' => $i, 'closed_at' => $i,
                'close_reason' => $v64, 'add_time' => $i, 'update_time' => $i,
            ],
            'yfth_hq_customer_attribution_event' => [
                'id' => $i, 'event_no' => $v48, 'attribution_current_id' => $i, 'uid' => $i,
                'authority_version' => $i, 'event_type' => $v48, 'before_store_id' => $i,
                'after_store_id' => $i, 'before_status' => $v24, 'after_status' => $v24,
                'before_status_reason_code' => $v64, 'after_status_reason_code' => $v64,
                'source_type' => $v64, 'source_id' => $v128, 'source_unique_key' => $key,
                'operator_uid' => $i, 'operator_role_code' => $v64, 'reason' => $v255,
                'request_id' => $v64, 'add_time' => $i,
            ],
            'yfth_hq_active_referral_current' => [
                'id' => $i, 'relation_no' => $v48, 'referrer_uid' => $i, 'referred_uid' => $i,
                'store_id' => $i, 'attribution_current_id' => $i, 'status' => $v24,
                'active_referred_uid' => $nullableInt, 'source_type' => $v64, 'source_id' => $v128,
                'source_unique_key' => $key, 'started_at' => $i, 'paused_at' => $i,
                'closed_at' => $i, 'close_reason' => $v64, 'relation_version' => $i,
                'request_id' => $v64, 'add_time' => $i, 'update_time' => $i,
            ],
            'yfth_hq_active_referral_event' => [
                'id' => $i, 'event_no' => $v48, 'referral_current_id' => $i, 'relation_no' => $v48,
                'relation_version' => $i, 'referrer_uid' => $i, 'referred_uid' => $i,
                'store_id' => $i, 'event_type' => $v48, 'before_status' => $v24,
                'after_status' => $v24, 'source_type' => $v64, 'source_id' => $v128,
                'source_unique_key' => $key, 'operator_uid' => $i, 'operator_role_code' => $v64,
                'reason' => $v255, 'request_id' => $v64, 'add_time' => $i,
            ],
        ];
    }

    private function schemaComplete(): bool
    {
        try {
            foreach (self::TABLES as $table) {
                if (!$this->hasTable($table)) {
                    return false;
                }
                $this->assertTableColumns($table);
                foreach ($this->indexNames($table) as $indexName) {
                    if (!$this->getAdapter()->hasIndexByName($table, $indexName)) {
                        return false;
                    }
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function indexNames(string $table): array
    {
        $map = [
            'yfth_hq_customer_attribution_current' => ['uniq_yfth_hq_attr_current_uid', 'idx_yfth_hq_attr_store_status_uid', 'idx_yfth_hq_attr_status_update'],
            'yfth_hq_customer_attribution_event' => ['uniq_yfth_hq_attr_event_no', 'uniq_yfth_hq_attr_event_version', 'uniq_yfth_hq_attr_event_source', 'idx_yfth_hq_attr_event_uid_time', 'idx_yfth_hq_attr_event_type_time', 'idx_yfth_hq_attr_event_source'],
            'yfth_hq_active_referral_current' => ['uniq_yfth_hq_ref_current_no', 'uniq_yfth_hq_ref_current_active_uid', 'uniq_yfth_hq_ref_current_source', 'idx_yfth_hq_ref_current_referrer', 'idx_yfth_hq_ref_current_referred', 'idx_yfth_hq_ref_current_store', 'idx_yfth_hq_ref_current_status_time'],
            'yfth_hq_active_referral_event' => ['uniq_yfth_hq_ref_event_no', 'uniq_yfth_hq_ref_event_version', 'uniq_yfth_hq_ref_event_source', 'idx_yfth_hq_ref_event_referrer_time', 'idx_yfth_hq_ref_event_referred_time', 'idx_yfth_hq_ref_event_type_time'],
        ];
        return $map[$table];
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

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
