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
    $assert(!$contains($service, '(int)($data[\'referred_uid\']'), 'client_referred_uid_is_not_trusted');
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
                    ['YFTH_REAL_FLOW_DB_HOSTNAME', 'YFTH_REAL_FLOW_DB_HOST', 'database.hostname'],
                    ['YFTH_REAL_FLOW_DB_HOSTPORT', 'YFTH_REAL_FLOW_DB_PORT', 'database.hostport'],
                    ['YFTH_REAL_FLOW_DB_USERNAME', 'YFTH_REAL_FLOW_DB_USER', 'database.username'],
                    ['YFTH_REAL_FLOW_DB_PASSWORD', '', 'database.password'],
                    ['YFTH_REAL_FLOW_DB_DATABASE', 'YFTH_REAL_FLOW_DB_NAME', 'database.database'],
                    ['YFTH_REAL_FLOW_DB_PREFIX', '', 'database.prefix'],
                    ['YFTH_REAL_FLOW_DB_CHARSET', '', 'database.charset'],
                ] as $env) {
                    $primary = getenv($env[0]);
                    $alias = $env[1] !== '' ? getenv($env[1]) : false;
                    $value = $primary !== false ? $primary : $alias;
                    if ($value !== false) {
                        $this->env->set($env[2], $value);
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
        $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine() . ':' . $e->getTraceAsString();
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
    echo "[OK] YFTH referral reward real package flow verified.\n";
    echo "[OK] YFTH referral reward real franchise flow verified.\n";
    echo "[OK] YFTH referral reward CRMEB funding boundary verified.\n";
} else {
    echo "[OK] YFTH referral reward P1/P2 source guards passed; isolated MySQL flow skipped.\n";
}

function rrAssertIndexes(callable $assert, string $database, string $prefix): void
{
    foreach ([
        [$prefix . 'yfth_reward_ledger', 'uniq_yfth_reward_ledger_unique_key'],
        [$prefix . 'yfth_reward_ledger', 'uniq_yfth_reward_ledger_active_key'],
        [$prefix . 'yfth_referral_event', 'uniq_yfth_referral_event_idempotency'],
        [$prefix . 'yfth_referral_candidate', 'uniq_yfth_referral_candidate_active_key'],
        [$prefix . 'yfth_reward_adjustment', 'uniq_yfth_reward_adjustment_dedupe'],
        [$prefix . 'yfth_reward_settlement_record', 'uniq_yfth_reward_settlement_active'],
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
    rrCleanup();
    $service = app()->make(ReferralRewardServices::class);
    $admin = ['id' => 1, 'level' => 0];
    $fundingBefore = rrFundingSnapshot();

    rrCreateRewardRule($service, 'package_5980', 100, 1);
    $package = rrCreatePackageBusiness(810002, 1);
    rrCreateCandidate('package_5980', 810001, 810002, 'customer', 0);
    $service->recordPackageActivatedEvent($package['purchase_id'], 'real_package_activated:' . $package['purchase_id']);
    $service->recordPackageActivatedEvent($package['purchase_id'], 'real_package_activated_repeat:' . $package['purchase_id']);
    $assert((int)Db::name('yfth_reward_ledger')->where('scene', 'package_5980')->where('business_id', $package['purchase_id'])->count() === 1, 'duplicate_package_event_does_not_create_duplicate_ledger');

    $earlyScan = $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $assert((int)$earlyScan['matched'] === 0 && (int)Db::name('yfth_reward_ledger')->where('business_id', $package['purchase_id'])->where('status', 'observing')->count() === 1, 'package_scan_before_observe_end_keeps_observing');

    Db::name('yfth_reward_ledger')->where('business_id', $package['purchase_id'])->update(['observe_end_time' => time() - 1]);
    $scan = $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $assert((int)$scan['valid'] >= 1 && (int)Db::name('yfth_reward_ledger')->where('business_id', $package['purchase_id'])->where('status', 'valid')->count() === 1, 'package_scan_after_observe_end_promotes_valid');

    $ledgerId = (int)Db::name('yfth_reward_ledger')->where('business_id', $package['purchase_id'])->value('id');
    $service->adminSettleLedger($ledgerId, ['offline_ref_no' => 'OFFREAL' . time(), 'remark' => 'real flow settle'], 1, $admin);
    $assert((int)Db::name('yfth_reward_settlement_record')->where('ledger_id', $ledgerId)->count() === 1, 'settle_writes_only_yfth_settlement_record');

    Db::name('yfth_package_instance')->where('id', $package['instance_id'])->update(['status' => 'refunded', 'refund_status' => 'succeeded']);
    Db::name('yfth_package_purchase')->where('id', $package['purchase_id'])->update(['purchase_status' => 'refunded']);
    $service->recordPackageNegativeEvent($package['purchase_id'], 'package_refunded', 'real_package_refunded:' . $package['purchase_id']);
    $service->recordPackageNegativeEvent($package['purchase_id'], 'package_refunded', 'real_package_refunded_repeat:' . $package['purchase_id']);
    try {
        $service->recordPackageActivatedEvent($package['purchase_id'], 'real_package_activated_after_reverse:' . $package['purchase_id']);
    } catch (Throwable $e) {
    }
    $assert((int)Db::name('yfth_reward_ledger')->where('business_id', $package['purchase_id'])->count() === 1, 'package_reactivation_after_reverse_does_not_create_second_ledger');
    $assert((int)Db::name('yfth_reward_adjustment')->where('ledger_id', $ledgerId)->where('adjustment_type', 'reverse')->count() === 1, 'package_reverse_adjustment_deduped');

    $invalidPackage = rrCreatePackageBusiness(810004, 1);
    rrCreateCandidate('package_5980', 810003, 810004, 'customer', 0);
    $service->recordPackageActivatedEvent($invalidPackage['purchase_id'], 'real_package_invalid_scan:' . $invalidPackage['purchase_id']);
    Db::name('yfth_reward_ledger')->where('business_id', $invalidPackage['purchase_id'])->update(['observe_end_time' => time() - 1]);
    Db::name('yfth_package_instance')->where('id', $invalidPackage['instance_id'])->update(['status' => 'refunded', 'refund_status' => 'succeeded']);
    Db::name('yfth_package_purchase')->where('id', $invalidPackage['purchase_id'])->update(['purchase_status' => 'refunded']);
    $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $invalidLedgerId = (int)Db::name('yfth_reward_ledger')->where('business_id', $invalidPackage['purchase_id'])->value('id');
    $assert((int)Db::name('yfth_reward_ledger')->where('id', $invalidLedgerId)->where('status', 'invalid')->count() === 1, 'package_scan_invalidates_failed_business');
    $assert((int)Db::name('yfth_reward_adjustment')->where('ledger_id', $invalidLedgerId)->where('adjustment_type', 'void')->count() === 1, 'package_invalid_scan_adjustment_deduped');

    rrCreateRewardRule($service, 'franchise_opening', 200, 1);
    rrCreateCandidate('franchise_opening', 820001, 820002, 'franchisee', 880001);
    $applicationId = rrCreateFranchiseApplication(820002, 'submitted', 880001, true, true);
    try {
        $service->recordFranchiseOpenedEvent($applicationId, 'real_franchise_before_opened:' . $applicationId);
        $assert(false, 'franchise_before_opened_rejected');
    } catch (Throwable $e) {
        $assert((int)Db::name('yfth_reward_ledger')->where('scene', 'franchise_opening')->count() === 0, 'franchise_before_opened_rejected');
    }

    Db::name('yfth_franchise_application')->where('id', $applicationId)->update(['status' => 'opened', 'update_time' => time()]);
    $service->recordFranchiseOpenedEvent($applicationId, 'real_franchise_opened:' . $applicationId);
    $service->recordFranchiseOpenedEvent($applicationId, 'real_franchise_opened_repeat:' . $applicationId);
    $assert((int)Db::name('yfth_reward_ledger')->where('scene', 'franchise_opening')->where('business_id', $applicationId)->count() === 1, 'duplicate_franchise_opened_does_not_create_duplicate_ledger');
    $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $assert((int)Db::name('yfth_reward_ledger')->where('business_id', $applicationId)->where('status', 'observing')->count() === 1, 'franchise_scan_before_observe_end_keeps_observing');
    Db::name('yfth_reward_ledger')->where('business_id', $applicationId)->update(['observe_end_time' => time() - 1]);
    $scan = $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $assert((int)$scan['valid'] >= 1 && (int)Db::name('yfth_reward_ledger')->where('business_id', $applicationId)->where('status', 'valid')->count() === 1, 'franchise_scan_after_observe_end_promotes_valid');

    Db::name('yfth_franchise_application')->where('id', $applicationId)->update(['status' => 'revoked', 'update_time' => time()]);
    Db::name('yfth_franchise_identity_grant')->where('application_id', $applicationId)->update(['status' => 'revoked', 'active_key' => null, 'update_time' => time()]);
    $service->recordFranchiseNegativeEvent($applicationId, 'franchise_revoked', 'real_franchise_revoked:' . $applicationId);
    try {
        $service->recordFranchiseOpenedEvent($applicationId, 'real_franchise_reopened_after_reverse:' . $applicationId);
    } catch (Throwable $e) {
    }
    $assert((int)Db::name('yfth_reward_ledger')->where('business_id', $applicationId)->count() === 1, 'franchise_reopened_after_reverse_does_not_create_second_ledger');

    rrCreateCandidate('franchise_opening', 820003, 820004, 'franchisee', 880002);
    $invalidApplicationId = rrCreateFranchiseApplication(820004, 'opened', 880002, true, true);
    $service->recordFranchiseOpenedEvent($invalidApplicationId, 'real_franchise_invalid_scan:' . $invalidApplicationId);
    Db::name('yfth_reward_ledger')->where('business_id', $invalidApplicationId)->update(['observe_end_time' => time() - 1]);
    Db::name('yfth_franchise_identity_grant')->where('application_id', $invalidApplicationId)->update(['status' => 'revoked', 'active_key' => null, 'update_time' => time()]);
    $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $service->adminScan(['dry_run' => 0, 'limit' => 50], 1, $admin);
    $invalidFranchiseLedgerId = (int)Db::name('yfth_reward_ledger')->where('business_id', $invalidApplicationId)->value('id');
    $assert((int)Db::name('yfth_reward_ledger')->where('id', $invalidFranchiseLedgerId)->where('status', 'invalid')->count() === 1, 'franchise_scan_invalidates_inactive_grant');
    $assert((int)Db::name('yfth_reward_adjustment')->where('ledger_id', $invalidFranchiseLedgerId)->where('adjustment_type', 'void')->count() === 1, 'franchise_invalid_scan_adjustment_deduped');

    $fundingAfter = rrFundingSnapshot();
    $assert($fundingBefore === $fundingAfter, 'crmeb_funding_tables_unchanged');
}

function rrCreateRewardRule(ReferralRewardServices $service, string $scene, int $amountCent, int $observeDays): void
{
    $rule = $service->adminRuleSave([
        'scene' => $scene,
        'name' => 'Real flow ' . $scene . ' reward',
        'version_no' => 1,
        'status' => 'draft',
        'items' => [[
            'reward_scene' => $scene,
            'reward_type' => 'offline_reward',
            'title' => 'Real flow reward',
            'amount_cent' => $amountCent,
            'observe_days' => $observeDays,
            'condition_snapshot' => [],
            'status' => 'active',
        ]],
    ], 1, ['id' => 1, 'level' => 0]);
    $service->adminRulePublish((int)$rule['rule']['id'], 1, ['id' => 1, 'level' => 0]);
}

function rrCreateCandidate(string $scene, int $referrerUid, int $referredUid, string $roleCode, int $storeId): void
{
    Db::name('yfth_referral_candidate')->insert([
        'scene' => $scene,
        'referrer_uid' => $referrerUid,
        'referrer_role_code' => $roleCode,
        'referrer_store_id' => $storeId,
        'referred_uid' => $referredUid,
        'source' => 'code',
        'status' => 'bound',
        'active_key' => $scene . ':uid:' . $referredUid,
        'bind_time' => time(),
        'expire_time' => time() + 86400,
        'create_time' => time(),
        'update_time' => time(),
    ]);
}

function rrCreatePackageBusiness(int $uid, int $storeId): array
{
    $now = time();
    $suffix = $uid . $now . random_int(1000, 9999);
    $purchaseId = Db::name('yfth_package_purchase')->insertGetId([
        'purchase_no' => 'YPREAL' . $suffix,
        'uid' => $uid,
        'store_id' => $storeId,
        'order_id' => (int)substr($suffix, -8),
        'order_sn' => 'ORDERREAL' . $suffix,
        'purchase_status' => 'activated',
        'activation_status' => 'succeeded',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $instanceId = Db::name('yfth_package_instance')->insertGetId([
        'instance_no' => 'YIREAL' . $suffix,
        'purchase_id' => $purchaseId,
        'uid' => $uid,
        'store_id' => $storeId,
        'order_id' => (int)substr($suffix, -8),
        'order_sn' => 'ORDERREAL' . $suffix,
        'status' => 'active',
        'refund_status' => 'none',
        'start_time' => $now,
        'end_time' => $now + 86400,
        'activated_time' => $now,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_package_purchase')->where('id', $purchaseId)->update(['instance_id' => $instanceId]);
    return ['purchase_id' => $purchaseId, 'instance_id' => $instanceId];
}

function rrCreateFranchiseApplication(int $uid, string $status, int $storeId, bool $profileBound, bool $grantActive): int
{
    $now = time();
    $suffix = $uid . $now . random_int(1000, 9999);
    rrEnsureSystemStore($storeId);
    $applicationId = Db::name('yfth_franchise_application')->insertGetId([
        'application_no' => 'FAREAL' . $suffix,
        'applicant_uid' => $uid,
        'name' => 'Real Flow Applicant',
        'phone' => '13800000000',
        'city' => 'Shanghai',
        'region' => 'Pudong',
        'intention_area' => 'Real Flow Area',
        'budget' => '0.00',
        'source' => 'real_flow',
        'status' => $status,
        'assigned_uid' => 1,
        'remark' => '',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_franchise_store_profile')->insert([
        'application_id' => $applicationId,
        'contract_id' => 0,
        'intended_store_type' => 'standard',
        'store_name' => 'Real Flow Store',
        'province' => 'Shanghai',
        'city' => 'Shanghai',
        'district' => 'Pudong',
        'address' => 'Real Flow Road',
        'business_subject_id' => 0,
        'system_store_id' => $storeId,
        'status' => $profileBound ? 'bound' : 'verified',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_franchise_identity_grant')->insert([
        'application_id' => $applicationId,
        'acceptance_id' => 0,
        'target_uid' => $uid,
        'store_id' => $storeId,
        'role_code' => 'franchisee',
        'store_role_id' => 0,
        'status' => $grantActive ? 'active' : 'pending',
        'grant_uid' => 1,
        'grant_time' => $now,
        'revoke_uid' => 0,
        'revoke_time' => 0,
        'reason' => 'real_flow',
        'active_key' => $grantActive ? $uid . ':' . $storeId . ':franchisee' : null,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    return $applicationId;
}

function rrEnsureSystemStore(int $storeId): void
{
    $existing = Db::name('system_store')->where('id', $storeId)->find();
    if ($existing) {
        Db::name('system_store')->where('id', $storeId)->update(['is_show' => 1, 'is_del' => 0]);
        return;
    }
    Db::name('system_store')->insert([
        'id' => $storeId,
        'name' => 'Real Flow Store ' . $storeId,
        'introduction' => '',
        'phone' => '13800000000',
        'address' => 'Shanghai',
        'detailed_address' => 'Real Flow Road',
        'image' => '',
        'oblong_image' => '',
        'latitude' => '',
        'longitude' => '',
        'valid_time' => '',
        'day_time' => '',
        'add_time' => time(),
        'is_show' => 1,
        'is_del' => 0,
    ]);
}

function rrFundingSnapshot(): array
{
    $snapshot = [];
    foreach (['user_spread', 'user_brokerage', 'user_bill', 'store_order'] as $table) {
        $snapshot[$table] = rrTableExists($table) ? (int)Db::name($table)->count() : 'missing';
    }
    if (rrTableExists('user')) {
        $rows = Db::name('user')->field('uid,now_money,integral')->whereIn('uid', [810001, 810002, 820001, 820002])->select()->toArray();
        $snapshot['user_money_rows'] = $rows;
    } else {
        $snapshot['user_money_rows'] = 'missing';
    }
    return $snapshot;
}

function rrCleanup(): void
{
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
        'yfth_franchise_identity_grant',
        'yfth_franchise_store_profile',
        'yfth_franchise_application',
        'yfth_package_instance',
        'yfth_package_purchase',
    ] as $table) {
        if (rrTableExists($table)) {
            Db::name($table)->where('id', '>', 0)->delete();
        }
    }
    if (rrTableExists('system_store')) {
        Db::name('system_store')->whereIn('id', [880001, 880002])->delete();
    }
}

function rrTableExists(string $table): bool
{
    try {
        Db::name($table)->limit(1)->find();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
