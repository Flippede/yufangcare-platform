<?php

use app\services\yfth\ReferralRewardServices;
use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$notes = [];

$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
        return;
    }
    $failures[] = $label;
};

$contains = function (string $text, string $needle): bool {
    return strpos($text, $needle) !== false;
};

try {
    $service = $read('app/services/yfth/ReferralRewardServices.php');
    $packageActivation = $read('app/services/yfth/PackageActivationServices.php');
    $packageLifecycle = $read('app/services/yfth/PackageLifecycleServices.php');
    $franchiseOpening = $read('app/services/yfth/FranchiseOpeningServices.php');
    $migration = $read('database/migrations/20260710100000_create_yfth_referral_reward_tables.php');

    $assert($contains($service, 'resolveTrustedBusinessEvent'), 'record_business_event_uses_trusted_resolver');
    $assert($contains($service, 'EVENT_SOURCE_MAP'), 'event_source_whitelist_exists');
    $assert($contains($service, 'resolveTrustedPackageEvent') && $contains($service, 'referral_package_not_activated'), 'package_event_revalidates_real_purchase_and_instance');
    $assert($contains($service, 'resolveTrustedFranchiseEvent') && $contains($service, 'franchiseBusinessIsOpened'), 'franchise_event_revalidates_opened_business');
    $assert($contains($service, 'referral_candidate_business_mismatch'), 'candidate_id_must_match_real_business_uid');
    $assert(!$contains($service, "(int)($data['referred_uid']"), 'client_referred_uid_is_not_trusted');
    $assert($contains($migration, 'ledger_unique_key') && $contains($migration, 'uniq_yfth_reward_ledger_unique_key'), 'immutable_ledger_unique_key_migration_exists');
    $assert($contains($service, "getOne(['ledger_unique_key'") && $contains($service, 'isUniqueConflict'), 'duplicate_ledger_guard_uses_immutable_key');
    $assert($contains($service, 'revalidateLedgerBusiness') && $contains($service, 'markLedgerInvalid'), 'observing_scan_revalidates_and_invalidates');
    $assert($contains($service, 'dedupe_key') && $contains($service, 'reverse:'), 'negative_reverse_adjustments_are_idempotent');
    $assert($contains($service, 'reward_rule_save_published_forbidden'), 'admin_save_cannot_publish_directly');
    $assert($contains($packageActivation, 'recordReferralPackageActivatedEventSafely'), 'package_activation_hook_exists');
    $assert($contains($packageLifecycle, 'recordReferralPackageNegativeEventSafely'), 'package_negative_hook_exists');
    $assert($contains($franchiseOpening, 'recordReferralFranchiseOpenedEventSafely'), 'franchise_opened_hook_exists');

    foreach ([
        'user_brokerage',
        'user_bill',
        'now_money',
        'user_spread',
        'StoreOrderCreateServices',
        'YfthInventoryBalanceDao',
        'YfthInventoryLedgerDao',
    ] as $forbidden) {
        $assert(!$contains($service, $forbidden), 'referral_service_does_not_touch_' . $forbidden);
    }
} catch (Throwable $e) {
    $failures[] = 'source_check_exception:' . $e->getMessage();
}

$executeFlow = (string)getenv('YFTH_REFERRAL_REWARD_REAL_FLOW_EXECUTE') === '1';
if (!$executeFlow) {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_REFERRAL_REWARD_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
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
            rrAssertIndexes($assert, $database, $prefix);
            rrRunTrustedEventFlow($assert);
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

echo $executeFlow
    ? "[OK] YFTH referral reward real-flow guards verified on isolated MySQL.\n"
    : "[OK] YFTH referral reward P1/P2 source guards passed; isolated MySQL flow skipped.\n";

function rrAssertIndexes(callable $assert, string $database, string $prefix): void
{
    foreach ([
        [$prefix . 'yfth_reward_ledger', 'uniq_yfth_reward_ledger_unique_key'],
        [$prefix . 'yfth_reward_ledger', 'uniq_yfth_reward_ledger_active_key'],
        [$prefix . 'yfth_reward_adjustment', 'uniq_yfth_reward_adjustment_dedupe'],
    ] as $index) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $index[0], $index[1]]
        );
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

function rrRunTrustedEventFlow(callable $assert): void
{
    $now = time();
    $uid = 810002;
    $referrerUid = 810001;

    Db::startTrans();
    try {
        foreach ([
            'yfth_reward_settlement_record',
            'yfth_reward_adjustment',
            'yfth_reward_ledger_snapshot',
            'yfth_reward_ledger',
            'yfth_reward_rule_item',
            'yfth_reward_rule_version',
            'yfth_referral_attribution',
            'yfth_referral_event',
            'yfth_referral_candidate',
            'yfth_package_instance',
            'yfth_package_purchase',
        ] as $table) {
            Db::name($table)->where('id', '>', 0)->delete();
        }

        $ruleId = Db::name('yfth_reward_rule_version')->insertGetId([
            'rule_no' => 'RRREAL' . $now,
            'scene' => 'package_5980',
            'name' => 'real flow package reward',
            'version_no' => 1,
            'status' => 'published',
            'effective_start' => 0,
            'effective_end' => 0,
            'published_time' => $now,
            'created_uid' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('yfth_reward_rule_item')->insert([
            'rule_version_id' => $ruleId,
            'reward_scene' => 'package_5980',
            'reward_type' => 'offline_reward',
            'title' => 'Package reward',
            'amount_cent' => 100,
            'observe_days' => 1,
            'condition_snapshot' => '{}',
            'status' => 'active',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('yfth_referral_candidate')->insert([
            'scene' => 'package_5980',
            'referrer_uid' => $referrerUid,
            'referrer_role_code' => 'customer',
            'referrer_store_id' => 0,
            'referred_uid' => $uid,
            'source' => 'code',
            'status' => 'bound',
            'active_key' => 'package_5980:uid:' . $uid,
            'bind_time' => $now,
            'expire_time' => $now + 86400,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $purchaseId = Db::name('yfth_package_purchase')->insertGetId([
            'purchase_no' => 'YPREAL' . $now,
            'uid' => $uid,
            'store_id' => 1,
            'order_id' => 900001,
            'order_sn' => 'ORDERREAL' . $now,
            'purchase_status' => 'activated',
            'activation_status' => 'succeeded',
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $instanceId = Db::name('yfth_package_instance')->insertGetId([
            'instance_no' => 'YIREAL' . $now,
            'purchase_id' => $purchaseId,
            'uid' => $uid,
            'store_id' => 1,
            'order_id' => 900001,
            'order_sn' => 'ORDERREAL' . $now,
            'status' => 'active',
            'refund_status' => 'none',
            'start_time' => $now,
            'end_time' => $now + 86400,
            'activated_time' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('yfth_package_purchase')->where('id', $purchaseId)->update(['instance_id' => $instanceId]);

        $service = app()->make(ReferralRewardServices::class);
        $service->recordPackageActivatedEvent($purchaseId, 'real_package_activated:' . $purchaseId);
        $service->recordPackageActivatedEvent($purchaseId, 'real_package_activated_repeat:' . $purchaseId);
        $assert((int)Db::name('yfth_reward_ledger')->count() === 1, 'duplicate_package_event_does_not_create_duplicate_ledger');

        Db::name('yfth_package_instance')->where('id', $instanceId)->update(['status' => 'refunded', 'refund_status' => 'succeeded']);
        Db::name('yfth_package_purchase')->where('id', $purchaseId)->update(['purchase_status' => 'refunded']);
        $service->recordPackageNegativeEvent($purchaseId, 'package_refunded', 'real_package_refunded:' . $purchaseId);
        $service->recordPackageNegativeEvent($purchaseId, 'package_refunded', 'real_package_refunded_repeat:' . $purchaseId);
        $assert((int)Db::name('yfth_reward_ledger')->where('status', 'reversed')->count() === 1, 'refund_reverses_existing_ledger_once');
        $assert((int)Db::name('yfth_reward_adjustment')->where('adjustment_type', 'reverse')->count() === 1, 'refund_reverse_adjustment_deduped');
        Db::commit();
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }
}
