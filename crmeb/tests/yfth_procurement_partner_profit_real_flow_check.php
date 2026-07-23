<?php

use app\services\yfth\ProcurementPartnerProfitServices;
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

$execute = (string)getenv('YFTH_PROCUREMENT_PARTNER_PROFIT_REAL_FLOW_EXECUTE') === '1';
if (!$execute) {
    $notes[] = 'real_flow_skipped_set_YFTH_PROCUREMENT_PARTNER_PROFIT_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
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
        $assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);
        $connection = Config::get('database.default');
        $database = (string)Config::get('database.connections.' . $connection . '.database');
        $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');
        $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
        $assert((bool)preg_match('/(validation|sandbox|test|local|dev)/i', $database), 'database_name_looks_isolated:' . $database);

        foreach ([
            'yfth_procurement_profit_snapshot',
            'yfth_procurement_profit_ledger',
            'yfth_partner_opening_reward_ledger',
            'yfth_platform_dividend_batch',
            'yfth_platform_dividend_item',
            'yfth_partner_service_area',
        ] as $table) {
            $rows = Db::query(
                'SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $prefix . $table]
            );
            $assert((int)($rows[0]['cnt'] ?? 0) === 1, 'real_table_exists:' . $table);
        }

        if (!$failures) {
            ppRunBusinessFlow($assert);
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
echo $execute
    ? "[OK] YFTH procurement partner profit real flow passed on isolated MySQL.\n"
    : "[OK] YFTH procurement partner profit real flow skipped.\n";

function ppRunBusinessFlow(callable $assert): void
{
    $run = 820000000 + random_int(1000, 9999);
    $storeId = 800000000 + random_int(1000, 9999);
    $orderId = 810000000 + random_int(1000, 9999);
    $uids = [
        'county_partner' => 820000001,
        'prefecture_partner' => 820000002,
        'province_partner' => 820000003,
        'regional_director' => 820000004,
        'platform_director' => 820000005,
    ];
    $rates = [
        'county_partner' => 2000,
        'prefecture_partner' => 1000,
        'province_partner' => 500,
        'regional_director' => 300,
        'platform_director' => 100,
    ];
    $now = time();

    Db::startTrans();
    try {
        Db::name('yfth_partner_rule_version')->where('active_key', 'published')->update([
            'active_key' => null,
            'status' => 'disabled',
            'update_time' => $now,
        ]);
        $versionNo = ((int)Db::name('yfth_partner_rule_version')->max('version_no')) + 1000;
        $ruleId = (int)Db::name('yfth_partner_rule_version')->insertGetId([
            'rule_no' => 'PPR-' . $run,
            'version_no' => $versionNo,
            'status' => 'published',
            'order_amount' => '98000.00',
            'bottle_count' => 440,
            'platform_dividend_bps' => 100,
            'effective_time' => $now,
            'active_key' => 'published',
            'operator_uid' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $level = 1;
        foreach ($rates as $rank => $rate) {
            Db::name('yfth_partner_rank_rule')->insert([
                'rule_version_id' => $ruleId,
                'rank_code' => $rank,
                'rank_name' => $rank,
                'rank_level' => $level++,
                'reward_per_bottle' => '0.00',
                'procurement_rate_bps' => $rate,
                'opening_reward_amount_cent' => $rank === 'county_partner' ? 1760000 : 0,
                'promotion_config' => '{}',
                'retention_config' => '{}',
                'warning_config' => '{}',
                'status' => 'active',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        foreach ($uids as $rank => $uid) {
            Db::name('yfth_partner_profile')->insert([
                'uid' => $uid,
                'rank_code' => $rank,
                'primary_store_id' => 0,
                'source_type' => 'real_flow',
                'source_id' => $run,
                'legacy_franchisee_role_id' => 0,
                'status' => 'active',
                'start_time' => $now,
                'end_time' => 0,
                'active_key' => 'real-flow-profile-' . $run . '-' . $uid,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        $orderedRanks = array_keys($uids);
        for ($index = 0; $index < count($orderedRanks) - 1; $index++) {
            $child = $uids[$orderedRanks[$index]];
            $parent = $uids[$orderedRanks[$index + 1]];
            Db::name('yfth_partner_relation')->insert([
                'partner_uid' => $child,
                'parent_uid' => $parent,
                'source_application_id' => 0,
                'status' => 'active',
                'start_time' => $now,
                'end_time' => 0,
                'reason' => 'real_flow',
                'operator_uid' => 1,
                'active_key' => 'real-flow-relation-' . $run . '-' . $child,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        Db::name('yfth_partner_store_binding')->insert([
            'partner_uid' => $uids['county_partner'],
            'store_id' => $storeId,
            'source_type' => 'real_flow',
            'source_id' => $run,
            'status' => 'active',
            'valid_from' => $now,
            'valid_to' => 0,
            'active_store_key' => 'real-flow-store-' . $run,
            'operator_uid' => 1,
            'reason' => 'real_flow',
            'create_time' => $now,
            'update_time' => $now,
        ]);

        $services = app()->make(ProcurementPartnerProfitServices::class);
        $snapshot = $services->freezeForPurchaseOrder([
            'id' => $orderId,
            'purchase_no' => 'PO-PROFIT-' . $run,
            'store_id' => $storeId,
        ], 100000);
        $assert((int)$snapshot['base_amount_cent'] === 100000, 'snapshot_freezes_purchase_base');
        $assert(count($snapshot['chain_snapshot']) === 5, 'snapshot_freezes_five_level_chain');

        $services->recognizeForReceipt($orderId);
        $services->recognizeForReceipt($orderId);
        $rows = Db::name('yfth_procurement_profit_ledger')
            ->where(['purchase_order_id' => $orderId, 'entry_type' => 'procurement_profit'])
            ->order('rank_code asc')->select()->toArray();
        $assert(count($rows) === 5, 'duplicate_receipt_does_not_duplicate_profit');
        $amounts = [];
        foreach ($rows as $row) {
            $amounts[(string)$row['rank_code']] = (int)$row['amount_cent'];
        }
        $assert($amounts === [
            'county_partner' => 20000,
            'platform_director' => 1000,
            'prefecture_partner' => 10000,
            'province_partner' => 5000,
            'regional_director' => 3000,
        ], 'five_level_procurement_profit_matches_frozen_rates');

        $opening = $services->recordOpeningReward($run, $storeId, $uids['county_partner']);
        $openingReplay = $services->recordOpeningReward($run, $storeId, $uids['county_partner']);
        $assert((int)($opening['reward']['amount_cent'] ?? 0) === 1760000, 'county_opening_reward_is_17600');
        $assert(($openingReplay['idempotent'] ?? false) === true, 'opening_reward_is_idempotent');
        $assert((int)Db::name('yfth_partner_opening_reward_ledger')->where('application_id', $run)->count() === 1, 'opening_reward_has_single_row');

        $reverse = $services->reverseForPurchaseOrder($orderId, 25000, 'refund-' . $run);
        $reverseReplay = $services->reverseForPurchaseOrder($orderId, 25000, 'refund-' . $run);
        $assert((int)$reverse['reversed_amount_cent'] === 25000, 'refund_reverses_snapshot_base');
        $assert(($reverseReplay['idempotent'] ?? false) === true, 'refund_reversal_is_idempotent');
        $assert((int)Db::name('yfth_procurement_profit_ledger')
            ->where(['purchase_order_id' => $orderId, 'entry_type' => 'procurement_reversal'])->count() === 5, 'refund_creates_one_reversal_per_partner');

        $period = date('Y-m');
        $dividend = $services->generateDividend($period);
        $dividendReplay = $services->generateDividend($period);
        $assert((int)($dividend['batch']['pool_cent'] ?? 0) === 1000, 'platform_dividend_pool_is_one_percent');
        $assert(($dividendReplay['idempotent'] ?? false) === true, 'platform_dividend_batch_is_idempotent');
        $assert((int)Db::name('yfth_platform_dividend_item')
            ->where('batch_id', (int)$dividend['batch']['id'])->sum('amount_cent') === 1000, 'weighted_dividend_items_equal_pool');
        $assert((int)Db::name('yfth_platform_dividend_item')
            ->where('batch_id', (int)$dividend['batch']['id'])
            ->where('beneficiary_uid', $uids['platform_director'])->value('amount_cent') > 0, 'platform_director_receives_weighted_share');

        Db::rollback();
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }
}
