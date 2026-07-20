<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Config;
use think\facade\Db;

/** Small forward-repair gate for the automatic commission release. */
class AutomaticCommissionMigrationHealthServices
{
    private const MIGRATIONS = ['20260720200000', '20260720210000'];

    private const TABLES = [
        'yfth_commission_rule_version', 'yfth_mall_commission_order_snapshot', 'yfth_commission_accrual',
        'yfth_commission_ledger', 'yfth_store_settlement_batch', 'yfth_store_settlement_batch_item',
        'yfth_store_settlement_receiver', 'yfth_store_settlement_callback', 'yfth_store_settlement_return',
        'yfth_commission_sequence_counter', 'yfth_commission_refund_reversal', 'yfth_commission_order_source',
    ];

    private const COLUMNS = [
        'yfth_commission_accrual' => ['package_sequence_no', 'package_sequence_key'],
        'yfth_commission_refund_reversal' => ['refund_id', 'order_item_id', 'accrual_id'],
        'yfth_commission_order_source' => ['order_id', 'source_type', 'legacy_brokerage_excluded'],
    ];

    private const INDEXES = [
        'yfth_commission_rule_version' => ['uniq_yfth_commission_rule_version'],
        'yfth_commission_accrual' => ['uniq_yfth_commission_accrual_source', 'uniq_yfth_commission_package_sequence'],
        'yfth_commission_ledger' => ['uniq_yfth_commission_ledger_source'],
        'yfth_store_settlement_batch_item' => ['uniq_yfth_store_settlement_ledger'],
        'yfth_commission_sequence_counter' => ['uniq_yfth_commission_sequence_referrer'],
        'yfth_commission_refund_reversal' => ['uniq_yfth_commission_refund_item_accrual'],
        'yfth_commission_order_source' => ['uniq_yfth_commission_order_source'],
    ];

    private const AUTHS = [
        'yfth-auto-commission-index', 'yfth-auto-commission-rule-read', 'yfth-auto-commission-rule-write',
        'yfth-auto-commission-account-read', 'yfth-auto-commission-accrual-read', 'yfth-auto-commission-ledger-read',
        'yfth-auto-commission-adjust', 'yfth-auto-commission-settlement-read', 'yfth-auto-commission-settlement-write',
        'yfth-auto-commission-retry',
    ];

    public function report(): array
    {
        $missing = [];
        foreach (self::MIGRATIONS as $version) {
            if (!$this->migrationRecorded($version)) $missing[] = 'migration:' . $version;
        }
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) $missing[] = 'table:' . $table;
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) if (!$this->columnExists($table, $column)) $missing[] = 'column:' . $table . '.' . $column;
        }
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $index) if (!$this->indexExists($table, $index)) $missing[] = 'index:' . $table . '.' . $index;
        }
        if ($this->tableExists('system_menus')) {
            $existing = Db::name('system_menus')->whereIn('unique_auth', self::AUTHS)->column('unique_auth');
            foreach (array_diff(self::AUTHS, $existing) as $auth) $missing[] = 'permission:' . $auth;
        } else {
            $missing[] = 'table:system_menus';
        }
        return ['healthy' => !$missing, 'missing' => $missing, 'forward_repair_required' => (bool)$missing];
    }

    public function assertHealthy(): void
    {
        $report = $this->report();
        if (!$report['healthy']) throw new ApiException('automatic_commission_forward_repair_required');
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->physicalTableExists($this->prefixed($table));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function migrationRecorded(string $version): bool
    {
        try {
            $table = $this->migrationTable();
            if (!$this->physicalTableExists($table)) return false;
            $rows = Db::query('SELECT COUNT(*) AS count FROM `' . $this->identifier($table) . '` WHERE `version` = ?', [$version]);
            return (int)($rows[0]['count'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $physicalTable = $this->prefixed($table);
            if (!$this->physicalTableExists($physicalTable)) return false;
            return Db::query(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$physicalTable, $column]
            ) !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $physicalTable = $this->prefixed($table);
            if (!$this->physicalTableExists($physicalTable)) return false;
            return Db::query(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$physicalTable, $index]
            ) !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function physicalTableExists(string $table): bool
    {
        return Db::query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function migrationTable(): string
    {
        return $this->prefixed((string)Config::get('database.migration_table', 'migrations'));
    }

    private function identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \RuntimeException('invalid_automatic_commission_health_identifier');
        }
        return $identifier;
    }

    private function prefixed(string $table): string
    {
        $default = (string)Config::get('database.default');
        return (string)Config::get('database.connections.' . $default . '.prefix') . $table;
    }
}
