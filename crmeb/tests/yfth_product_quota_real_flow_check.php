<?php

use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$notes = [];

$assert = function ($condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $message;
        return;
    }
    $failures[] = $message;
};

$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

try {
    $service = $read('app/services/yfth/ProductQuotaServices.php');
    $migration = $read('database/migrations/20260711100000_create_yfth_product_quota_tables.php');

    foreach ([
        'grant_requires_headquarter' => 'assertHeadquarterAdmin($adminInfo)',
        'user_read_resolves_context' => 'CurrentBusinessContextServices::class',
        'user_read_rejects_forbidden_fields' => 'assertUserReadonlyPayload',
        'account_row_lock' => 'function lockAccount(int $id)',
        'grant_row_lock' => 'function lockGrant(int $id)',
        'negative_available_guard' => "available_cent'] <",
        'frozen_closed_write_guard' => 'assertAccountAmountWritable',
        'manual_grant_source' => 'headquarters_manual_grant',
        'opening_source_revalidated' => 'franchise_opening_initial_quota',
        'reserved_sources_rejected' => 'product_quota_source_reserved_not_open',
        'audit_written' => 'AuditEventServices::class',
        'snapshot_written' => 'writeSnapshot',
        'no_float_amount' => '(float)',
        'no_crmeb_store_order_write' => "Db::name('store_order')",
        'no_crmeb_stock_write' => 'decStockIncSales',
        'no_user_balance_write' => 'now_money',
    ] as $label => $needle) {
        if (strpos($label, 'no_') === 0) {
            $assert(strpos($service, $needle) === false, $label);
        } else {
            $assert(strpos($service, $needle) !== false, $label);
        }
    }

    foreach ([
        'uniq_yfth_product_quota_account_active',
        'uniq_yfth_product_quota_ledger_idempotency',
        'uniq_yfth_product_quota_grant_idempotency',
        'uniq_yfth_product_quota_adjustment_dedupe',
    ] as $index) {
        $assert(strpos($migration, $index) !== false, 'migration_has_' . $index);
    }
} catch (Throwable $e) {
    $failures[] = 'source_check_exception:' . $e->getMessage();
}

$executeFlow = (string)getenv('YFTH_PRODUCT_QUOTA_REAL_FLOW_EXECUTE') === '1';
if (!$executeFlow) {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_PRODUCT_QUOTA_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
} else {
    require $root . '/vendor/autoload.php';
    try {
        $app = new class() extends App {
            public function loadEnv(string $envName = ''): void
            {
                parent::loadEnv($envName);
                foreach ([
                    'YFTH_REAL_FLOW_DB_HOSTNAME' => 'database.hostname',
                    'YFTH_REAL_FLOW_DB_HOSTPORT' => 'database.hostport',
                    'YFTH_REAL_FLOW_DB_USERNAME' => 'database.username',
                    'YFTH_REAL_FLOW_DB_PASSWORD' => 'database.password',
                    'YFTH_REAL_FLOW_DB_DATABASE' => 'database.database',
                    'YFTH_REAL_FLOW_DB_PREFIX' => 'database.prefix',
                    'YFTH_REAL_FLOW_DB_CHARSET' => 'database.charset',
                ] as $envKey => $configKey) {
                    $value = getenv($envKey);
                    if ($value !== false) {
                        $this->env->set($configKey, $value);
                    }
                }
                if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') {
                    $this->env->set('database.password', '');
                }
                $this->env->set('cache.driver', 'file');
            }
        };
        $app->initialize();

        $versionRow = Db::query('SELECT VERSION() AS version');
        $mysqlVersion = (string)($versionRow[0]['version'] ?? '');
        $assert($mysqlVersion !== '', 'mysql_version_available');
        $assert(stripos($mysqlVersion, 'mariadb') === false, 'mysql_vendor_is_not_mariadb');
        $assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);

        $connection = Config::get('database.default');
        $database = (string)Config::get('database.connections.' . $connection . '.database');
        $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');
        $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
        $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);

        if (!$failures) {
            pqAssertRealIndexes($assert, $database, $prefix);
            pqAssertMandatoryKeyColumns($assert, $database, $prefix);
            pqAssertUniquenessGuards($assert);
            pqAssertServiceIdempotency($assert);
        }
    } catch (Throwable $e) {
        $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
    }
}

foreach ($notes as $note) {
    echo "[NOTE] {$note}\n";
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

foreach ($passes as $pass) {
    echo "[PASS] {$pass}\n";
}

if ($executeFlow) {
    echo "[OK] YFTH product quota real-flow guards verified on isolated MySQL.\n";
} else {
    echo "[OK] YFTH product quota source guards passed; isolated MySQL flow skipped.\n";
}

function pqAssertRealIndexes(callable $assert, string $database, string $prefix): void
{
    foreach ([
        [$prefix . 'yfth_product_quota_account', 'uniq_yfth_product_quota_account_active'],
        [$prefix . 'yfth_product_quota_ledger', 'uniq_yfth_product_quota_ledger_idempotency'],
        [$prefix . 'yfth_product_quota_grant_order', 'uniq_yfth_product_quota_grant_idempotency'],
        [$prefix . 'yfth_product_quota_adjustment', 'uniq_yfth_product_quota_adjustment_dedupe'],
    ] as $index) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $index[0], $index[1]]
        );
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

function pqAssertMandatoryKeyColumns(callable $assert, string $database, string $prefix): void
{
    foreach ([
        [$prefix . 'yfth_product_quota_grant_order', 'idempotency_key'],
        [$prefix . 'yfth_product_quota_adjustment', 'dedupe_key'],
    ] as $column) {
        $rows = Db::query(
            'SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $column[0], $column[1]]
        );
        $row = $rows[0] ?? [];
        $assert((string)($row['IS_NULLABLE'] ?? '') === 'NO', 'real_column_not_nullable:' . $column[0] . '.' . $column[1]);
        $assert(array_key_exists('COLUMN_DEFAULT', $row) && (string)$row['COLUMN_DEFAULT'] === '', 'real_column_default_empty_string:' . $column[0] . '.' . $column[1]);
    }
}

function pqAssertUniquenessGuards(callable $assert): void
{
    $runId = time() . random_int(1000, 9999);
    $now = time();

    Db::startTrans();
    try {
        Db::name('yfth_product_quota_account')->insert([
            'account_no' => 'PQREAL' . $runId,
            'store_id' => 990001,
            'quota_type' => 'return_goods',
            'status' => 'active',
            'total_granted_cent' => 0,
            'total_adjusted_cent' => 0,
            'total_reversed_cent' => 0,
            'reserved_cent' => 0,
            'consumed_cent' => 0,
            'available_cent' => 0,
            'frozen_cent' => 0,
            'version' => 1,
            'active_key' => '990001:return_goods',
            'remark' => 'real flow guard',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $accountId = (int)Db::name('yfth_product_quota_account')->where('active_key', '990001:return_goods')->value('id');

        pqExpectDuplicate(function () use ($runId, $now) {
            Db::name('yfth_product_quota_account')->insert([
                'account_no' => 'PQDUP' . $runId,
                'store_id' => 990001,
                'quota_type' => 'return_goods',
                'status' => 'active',
                'total_granted_cent' => 0,
                'total_adjusted_cent' => 0,
                'total_reversed_cent' => 0,
                'reserved_cent' => 0,
                'consumed_cent' => 0,
                'available_cent' => 0,
                'frozen_cent' => 0,
                'version' => 1,
                'active_key' => '990001:return_goods',
                'remark' => 'duplicate active key',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_active_account_blocked');

        Db::name('yfth_product_quota_ledger')->insert([
            'ledger_no' => 'PQLREAL' . $runId,
            'account_id' => $accountId,
            'store_id' => 990001,
            'quota_type' => 'return_goods',
            'direction' => 'in',
            'action_type' => 'headquarters_manual_grant',
            'amount_cent' => 100,
            'balance_before_cent' => 0,
            'balance_after_cent' => 100,
            'source_type' => 'headquarters_manual_grant',
            'source_id' => 0,
            'idempotency_key' => 'pq-real-flow-' . $runId,
            'status' => 'valid',
            'operator_type' => 'admin',
            'operator_uid' => 1,
            'reason' => 'real flow guard',
            'create_time' => $now,
        ]);
        pqExpectDuplicate(function () use ($accountId, $runId, $now) {
            Db::name('yfth_product_quota_ledger')->insert([
                'ledger_no' => 'PQLDUP' . $runId,
                'account_id' => $accountId,
                'store_id' => 990001,
                'quota_type' => 'return_goods',
                'direction' => 'in',
                'action_type' => 'headquarters_manual_grant',
                'amount_cent' => 100,
                'balance_before_cent' => 0,
                'balance_after_cent' => 100,
                'source_type' => 'headquarters_manual_grant',
                'source_id' => 0,
                'idempotency_key' => 'pq-real-flow-' . $runId,
                'status' => 'valid',
                'operator_type' => 'admin',
                'operator_uid' => 1,
                'reason' => 'duplicate idempotency',
                'create_time' => $now,
            ]);
        }, $assert, 'duplicate_ledger_idempotency_blocked');

        Db::rollback();
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }
}

function pqAssertServiceIdempotency(callable $assert): void
{
    $runId = time() . random_int(1000, 9999);
    $storeId = pqEnsureActiveStore($runId);
    $adminInfo = ['id' => 1, 'level' => 0];
    $adminId = 1;
    $service = app()->make(\app\services\yfth\ProductQuotaServices::class);
    $boundaryBefore = pqCrmebBoundarySnapshot();

    pqExpectException(function () use ($service, $storeId, $adminId, $adminInfo) {
        $service->adminCreateGrant([
            'store_id' => $storeId,
            'quota_type' => 'return_goods',
            'amount_cent' => 10000,
            'source_type' => 'headquarters_manual_grant',
            'source_id' => 0,
            'reason' => 'missing idempotency key check',
        ], $adminId, $adminInfo);
    }, $assert, 'grant_create_missing_idempotency_key_rejected');

    $grantPayload = [
        'store_id' => $storeId,
        'quota_type' => 'return_goods',
        'amount_cent' => 10000,
        'source_type' => 'headquarters_manual_grant',
        'source_id' => 0,
        'reason' => 'real service idempotency grant',
        'idempotency_key' => 'grant-' . $runId,
    ];
    $grantFirst = $service->adminCreateGrant($grantPayload, $adminId, $adminInfo);
    $grantSecond = $service->adminCreateGrant($grantPayload, $adminId, $adminInfo);
    $grantId = (int)($grantFirst['grant']['id'] ?? 0);
    $assert($grantId > 0 && $grantId === (int)($grantSecond['grant']['id'] ?? 0), 'duplicate_grant_create_returns_existing_grant');

    pqExpectException(function () use ($service, $grantPayload, $adminId, $adminInfo) {
        $payload = $grantPayload;
        $payload['amount_cent'] = 12000;
        $service->adminCreateGrant($payload, $adminId, $adminInfo);
    }, $assert, 'grant_create_same_key_different_payload_rejected');

    $confirmFirst = $service->adminConfirmGrant($grantId, $adminId, $adminInfo);
    $confirmSecond = $service->adminConfirmGrant($grantId, $adminId, $adminInfo);
    $accountId = (int)($confirmFirst['account']['id'] ?? 0);
    $assert($accountId > 0, 'grant_confirm_returns_account');
    $assert((int)($confirmSecond['account']['available_cent'] ?? 0) === 10000, 'duplicate_grant_confirm_does_not_increase_balance_twice');
    $ledgerCount = (int)Db::name('yfth_product_quota_ledger')->where('idempotency_key', 'product_quota_grant_confirm:' . $grantId)->count();
    $assert($ledgerCount === 1, 'duplicate_grant_confirm_keeps_single_ledger');

    pqExpectException(function () use ($service, $accountId, $adminId, $adminInfo) {
        $service->adminCreateAdjustment([
            'account_id' => $accountId,
            'action_type' => 'manual_increase',
            'amount_cent' => 500,
            'reason' => 'missing dedupe key check',
        ], $adminId, $adminInfo);
    }, $assert, 'adjustment_missing_dedupe_key_rejected');

    $adjustPayload = [
        'account_id' => $accountId,
        'action_type' => 'manual_increase',
        'amount_cent' => 500,
        'reason' => 'real service idempotency adjustment',
        'dedupe_key' => 'adjust-' . $runId,
    ];
    $adjustFirst = $service->adminCreateAdjustment($adjustPayload, $adminId, $adminInfo);
    $adjustSecond = $service->adminCreateAdjustment($adjustPayload, $adminId, $adminInfo);
    $adjustId = (int)($adjustFirst['adjustment']['id'] ?? 0);
    $assert($adjustId > 0 && $adjustId === (int)($adjustSecond['adjustment']['id'] ?? 0), 'duplicate_adjustment_returns_existing_adjustment');
    $assert((int)($adjustSecond['account']['available_cent'] ?? 0) === 10500, 'duplicate_adjustment_does_not_increase_balance_twice');
    $adjustLedgerCount = (int)Db::name('yfth_product_quota_ledger')->where('source_type', 'correction_adjustment')->where('source_id', $adjustId)->count();
    $assert($adjustLedgerCount === 1, 'duplicate_adjustment_keeps_single_ledger');

    pqExpectException(function () use ($service, $adjustPayload, $adminId, $adminInfo) {
        $payload = $adjustPayload;
        $payload['amount_cent'] = 600;
        $service->adminCreateAdjustment($payload, $adminId, $adminInfo);
    }, $assert, 'adjustment_same_key_different_payload_rejected');

    $decreasePayload = [
        'account_id' => $accountId,
        'action_type' => 'manual_decrease',
        'amount_cent' => 300,
        'reason' => 'real service idempotency decrease',
        'dedupe_key' => 'decrease-' . $runId,
    ];
    $decreaseFirst = $service->adminCreateAdjustment($decreasePayload, $adminId, $adminInfo);
    $decreaseSecond = $service->adminCreateAdjustment($decreasePayload, $adminId, $adminInfo);
    $assert((int)($decreaseFirst['adjustment']['id'] ?? 0) === (int)($decreaseSecond['adjustment']['id'] ?? -1), 'duplicate_decrease_returns_existing_adjustment');
    $assert((int)($decreaseSecond['account']['available_cent'] ?? 0) === 10200, 'duplicate_decrease_does_not_decrease_balance_twice');

    $service->adminFreezeAccount($accountId, ['reason' => 'freeze write guard'], $adminId, $adminInfo);
    pqExpectException(function () use ($service, $accountId, $adminId, $adminInfo, $runId) {
        $service->adminCreateAdjustment([
            'account_id' => $accountId,
            'action_type' => 'manual_increase',
            'amount_cent' => 100,
            'reason' => 'blocked while frozen',
            'dedupe_key' => 'frozen-adjust-' . $runId,
        ], $adminId, $adminInfo);
    }, $assert, 'frozen_account_blocks_amount_adjustment');
    $service->adminUnfreezeAccount($accountId, ['reason' => 'unfreeze after guard'], $adminId, $adminInfo);

    $auditCount = (int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_product_quota')->count();
    $assert($auditCount > 0, 'product_quota_audit_events_written');
    $assert(pqCrmebBoundarySnapshot() === $boundaryBefore, 'crmeb_order_product_stock_boundary_unchanged');
}

function pqEnsureActiveStore(string $runId): int
{
    $now = time();
    Db::name('system_store')->insert([
        'name' => 'YFTH Product Quota Test Store ' . $runId,
        'introduction' => 'isolated product quota idempotency test',
        'phone' => '1380000' . substr($runId, -4),
        'address' => 'isolated test city',
        'detailed_address' => 'isolated test address',
        'image' => '',
        'oblong_image' => '',
        'latitude' => '30.000000',
        'longitude' => '120.000000',
        'valid_time' => '',
        'day_time' => '08:00-20:00',
        'add_time' => $now,
        'is_show' => 1,
        'is_del' => 0,
    ]);
    return (int)Db::name('system_store')->getLastInsID();
}

function pqCrmebBoundarySnapshot(): array
{
    $snapshot = [];
    foreach (['store_order', 'store_product', 'store_product_attr_value', 'user'] as $table) {
        try {
            $snapshot[$table . '_count'] = (int)Db::name($table)->count();
        } catch (Throwable $e) {
            $snapshot[$table . '_count'] = 'table_missing';
        }
    }
    foreach ([
        'store_product_stock' => ['store_product', 'stock'],
        'store_product_sales' => ['store_product', 'sales'],
        'store_product_attr_stock' => ['store_product_attr_value', 'stock'],
        'store_product_attr_sales' => ['store_product_attr_value', 'sales'],
    ] as $key => $target) {
        try {
            $snapshot[$key] = (string)Db::name($target[0])->sum($target[1]);
        } catch (Throwable $e) {
            $snapshot[$key] = 'table_missing';
        }
    }
    return $snapshot;
}

function pqExpectDuplicate(callable $callback, callable $assert, string $label): void
{
    try {
        $callback();
        $assert(false, $label);
    } catch (Throwable $e) {
        $assert(true, $label);
    }
}

function pqExpectException(callable $callback, callable $assert, string $label): void
{
    try {
        $callback();
        $assert(false, $label);
    } catch (Throwable $e) {
        $assert(true, $label);
    }
}
