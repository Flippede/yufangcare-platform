<?php

use app\Request;
use app\services\yfth\MonthlyBenefitFulfillmentServices;
use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$notes = [];

if ((string)getenv('YFTH_MONTHLY_BENEFIT_WORKER') === 'pickup_confirm') {
    require $root . '/vendor/autoload.php';
    try {
        mbfBootstrapApplication();
        mbfInstallRequestMacro();
        mbfRunPickupConfirmWorker();
    } catch (Throwable $e) {
        fwrite(STDERR, '[WORKER_FAIL] ' . $e->getMessage() . "\n");
        exit(1);
    }
}

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
    $service = $read('app/services/yfth/MonthlyBenefitFulfillmentServices.php');
    $migration = $read('database/migrations/20260712100000_create_yfth_monthly_benefit_fulfillment_tables.php');

    foreach ([
        'claim_requires_idempotency' => 'monthly_benefit_idempotency_key_required',
        'claim_rejects_client_uid' => 'monthly_benefit_claim_field_forbidden',
        'active_key_guard' => 'uniq_yfth_benefit_fulfillment_active',
        'final_consumption' => "fulfillment_status' => 'product_fulfilled'",
        'event_written' => 'appendEvent',
        'audit_written' => 'AuditEventServices::class',
        'store_context_resolved' => 'CurrentBusinessContextServices::class',
        'admin_headquarter_required' => 'assertHeadquarterScope',
        'complete_rejects_unshipped_express' => 'monthly_benefit_complete_requires_shipped',
        'pickup_confirm_event' => "'event_type' => 'pickup_confirm'",
        'pickup_confirm_requires_preparing_source' => '$this->transition($id, [self::STATUS_PREPARING], self::STATUS_COMPLETED',
        'pickup_direct_complete_requires_preparing_source' => "!empty(\$operator['allow_pickup_direct_complete']) && \$status === self::STATUS_PREPARING",
        'delivery_company_required' => 'monthly_benefit_delivery_company_required',
        'delivery_no_required' => 'monthly_benefit_delivery_no_required',
        'no_crmeb_order_write' => "Db::name('store_order')",
        'no_crmeb_stock_write' => 'decStockIncSales',
        'no_product_quota_write' => 'yfth_product_quota_account',
    ] as $label => $needle) {
        if (strpos($label, 'no_') === 0) {
            $assert(strpos($service, $needle) === false, $label);
        } else {
            $assert(strpos($service . $migration, $needle) !== false, $label);
        }
    }
    $assert(strpos($service, '[self::STATUS_CONFIRMED, self::STATUS_PREPARING], self::STATUS_COMPLETED') === false, 'pickup_confirm_does_not_allow_confirmed_source');
    preg_match_all("/'menu_name'\\s*=>\\s*'([^']*)'/u", $migration, $literalMenuMatches);
    preg_match_all("/apiRow\\([^,]+,\\s*'([^']*)'/u", $migration, $apiMenuMatches);
    $menuNames = array_merge($literalMenuMatches[1] ?? [], $apiMenuMatches[1] ?? []);
    $assert(count($menuNames) === 10, 'migration_source_guard_covers_all_menu_names');
    foreach ($menuNames as $index => $menuName) {
        $length = function_exists('mb_strlen') ? mb_strlen($menuName, 'UTF-8') : count(preg_split('//u', $menuName, -1, PREG_SPLIT_NO_EMPTY));
        $assert($length <= 32, 'migration_menu_name_within_32_chars_' . $index);
    }
    $assert(strpos($migration, 'Monthly Benefit Fulfillment') === false && strpos($migration, 'Monthly benefit fulfillment') === false, 'migration_overlength_english_menu_names_removed');
} catch (Throwable $e) {
    $failures[] = 'source_check_exception:' . $e->getMessage();
}

$executeFlow = (string)getenv('YFTH_MONTHLY_BENEFIT_REAL_FLOW_EXECUTE') === '1';
if (!$executeFlow) {
    $notes[] = 'real_flow_execute_skipped_set_YFTH_MONTHLY_BENEFIT_REAL_FLOW_EXECUTE=1_and_YFTH_REAL_FLOW_ISOLATED_DB=1';
} else {
    require $root . '/vendor/autoload.php';
    try {
        mbfBootstrapApplication();

        $versionRow = Db::query('SELECT VERSION() AS version');
        $mysqlVersion = (string)($versionRow[0]['version'] ?? '');
        $assert($mysqlVersion !== '', 'mysql_version_available');
        $assert(stripos($mysqlVersion, 'mariadb') === false, 'mysql_vendor_is_not_mariadb');
        $assert((bool)preg_match('/^8\.0\./', $mysqlVersion), 'mysql_version_is_8_0:' . $mysqlVersion);

        $connection = Config::get('database.default');
        $database = (string)Config::get('database.connections.' . $connection . '.database');
        $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');
        $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_db_guard_confirmed');
        $assert((bool)preg_match('/(validation|sandbox|test|local|dev|temp|tmp)/i', $database), 'database_name_looks_isolated:' . $database);

        if (!$failures) {
            mbfInstallRequestMacro();
            mbfAssertIndexes($assert, $database, $prefix);
            mbfAssertUniqueness($assert);
            mbfAssertServiceLevelFlow($assert);
            mbfAssertConcurrentPickup($assert, $notes);
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
    echo "[OK] YFTH monthly benefit fulfillment service-level real-flow verified on isolated MySQL.\n";
} else {
    echo "[OK] YFTH monthly benefit fulfillment source guards passed; isolated MySQL flow skipped.\n";
}

function mbfAssertIndexes(callable $assert, string $database, string $prefix): void
{
    foreach ([
        [$prefix . 'yfth_benefit_fulfillment', 'uniq_yfth_benefit_fulfillment_idem'],
        [$prefix . 'yfth_benefit_fulfillment', 'uniq_yfth_benefit_fulfillment_active'],
        [$prefix . 'yfth_benefit_fulfillment', 'idx_yfth_benefit_fulfillment_pickup'],
        [$prefix . 'yfth_benefit_fulfillment_event', 'idx_yfth_benefit_fulfillment_event_order'],
    ] as $index) {
        $rows = Db::query(
            'SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$database, $index[0], $index[1]]
        );
        $assert((int)($rows[0]['cnt'] ?? 0) > 0, 'real_index_exists:' . $index[0] . '.' . $index[1]);
    }
}

function mbfAssertUniqueness(callable $assert): void
{
    $now = time();
    $runId = (string)$now . random_int(1000, 9999);
    Db::startTrans();
    try {
        Db::name('yfth_benefit_fulfillment')->insert([
            'fulfillment_no' => 'MBF' . $runId,
            'uid' => 990001,
            'store_id' => 880001,
            'package_instance_id' => 1,
            'benefit_plan_id' => 1,
            'benefit_period_id' => 1,
            'benefit_item_id' => 990001,
            'benefit_template_id' => 1,
            'month_no' => 1,
            'period_code' => '202607',
            'benefit_code' => 'product_monthly',
            'benefit_name' => 'monthly product',
            'fulfillment_type' => 'product',
            'fulfillment_method' => 'self_pickup',
            'status' => 'pending_confirm',
            'quantity_total' => '1.00',
            'product_id' => 0,
            'sku_unique' => '',
            'pickup_store_id' => 880001,
            'idempotency_key' => 'mbf_idem_' . $runId,
            'active_key' => 'benefit_item:990001',
            'claim_time' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        mbfExpectDuplicate(function () use ($runId, $now) {
            Db::name('yfth_benefit_fulfillment')->insert([
                'fulfillment_no' => 'MBF_DUP_' . $runId,
                'uid' => 990001,
                'store_id' => 880001,
                'package_instance_id' => 1,
                'benefit_plan_id' => 1,
                'benefit_period_id' => 1,
                'benefit_item_id' => 990001,
                'benefit_template_id' => 1,
                'month_no' => 1,
                'period_code' => '202607',
                'benefit_code' => 'product_monthly',
                'benefit_name' => 'monthly product',
                'fulfillment_type' => 'product',
                'fulfillment_method' => 'self_pickup',
                'status' => 'pending_confirm',
                'quantity_total' => '1.00',
                'product_id' => 0,
                'sku_unique' => '',
                'pickup_store_id' => 880001,
                'idempotency_key' => 'mbf_idem_dup_' . $runId,
                'active_key' => 'benefit_item:990001',
                'claim_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_active_fulfillment_rejected');

        mbfExpectDuplicate(function () use ($runId, $now) {
            Db::name('yfth_benefit_fulfillment')->insert([
                'fulfillment_no' => 'MBF_IDEM_DUP_' . $runId,
                'uid' => 990002,
                'store_id' => 880001,
                'package_instance_id' => 2,
                'benefit_plan_id' => 2,
                'benefit_period_id' => 2,
                'benefit_item_id' => 990002,
                'benefit_template_id' => 1,
                'month_no' => 1,
                'period_code' => '202607',
                'benefit_code' => 'product_monthly',
                'benefit_name' => 'monthly product',
                'fulfillment_type' => 'product',
                'fulfillment_method' => 'self_pickup',
                'status' => 'pending_confirm',
                'quantity_total' => '1.00',
                'product_id' => 0,
                'sku_unique' => '',
                'pickup_store_id' => 880001,
                'idempotency_key' => 'mbf_idem_' . $runId,
                'active_key' => 'benefit_item:990002',
                'claim_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }, $assert, 'duplicate_claim_idempotency_rejected');
    } finally {
        Db::rollback();
    }
}

function mbfAssertServiceLevelFlow(callable $assert): void
{
    $run = time() . random_int(1000, 9999);
    $uid = 710000 + random_int(1, 999);
    $otherUid = $uid + 1;
    $adminInfo = ['id' => 1, 'level' => 0];
    /** @var MonthlyBenefitFulfillmentServices $service */
    $service = app()->make(MonthlyBenefitFulfillmentServices::class);

    Db::startTrans();
    try {
        $storeA = mbfSeedStore('Monthly Store A ' . $run);
        $storeB = mbfSeedStore('Monthly Store B ' . $run);
        $addressId = mbfSeedAddress($uid);
        mbfSeedStoreRoles($storeA, [
            $uid + 10 => 'store_staff',
            $uid + 11 => 'store_manager',
            $uid + 12 => 'franchisee',
        ]);
        mbfSeedStoreRoles($storeB, [
            $uid + 20 => 'store_staff',
        ]);
        mbfSeedServiceMentorIdentity($uid + 30);

        $adjacentBefore = mbfSnapshotAdjacentTables();

        $express = mbfSeedBenefitRows($uid, $storeA, ['case' => 'express_main']);
        $claim = $service->claim(mbfRequest($uid), [
            'benefit_item_id' => $express['item_id'],
            'fulfillment_method' => 'express_delivery',
            'address_id' => $addressId,
            'idempotency_key' => 'claim_express_' . $run,
        ]);
        $fulfillmentId = (int)$claim['fulfillment']['id'];
        $assert((string)$claim['fulfillment']['status'] === 'pending_confirm', 'real_claim_express_success_pending');

        $replay = $service->claim(mbfRequest($uid), [
            'benefit_item_id' => $express['item_id'],
            'fulfillment_method' => 'express_delivery',
            'address_id' => $addressId,
            'idempotency_key' => 'claim_express_' . $run,
        ]);
        $activeCount = Db::name('yfth_benefit_fulfillment')->where('active_key', 'benefit_item:' . $express['item_id'])->count();
        $assert((int)$replay['fulfillment']['id'] === $fulfillmentId && (int)$activeCount === 1, 'real_duplicate_claim_is_idempotent_without_second_active_fulfillment');

        mbfExpectException(function () use ($service, $uid, $express, $storeA, $run) {
            $service->claim(mbfRequest($uid), [
                'benefit_item_id' => $express['item_id'],
                'fulfillment_method' => 'self_pickup',
                'pickup_store_id' => $storeA,
                'idempotency_key' => 'claim_express_' . $run,
            ]);
        }, 'idempotency_key_payload_mismatch', $assert, 'real_claim_payload_mismatch_rejected');
        mbfExpectException(function () use ($service, $otherUid, $express, $addressId, $run) {
            $service->claim(mbfRequest($otherUid), [
                'benefit_item_id' => $express['item_id'],
                'fulfillment_method' => 'express_delivery',
                'address_id' => $addressId,
                'idempotency_key' => 'claim_other_uid_' . $run,
            ]);
        }, 'benefit_item_not_found', $assert, 'real_non_owner_claim_rejected');
        mbfExpectException(function () use ($service, $uid, $addressId, $storeA, $run) {
            $unopened = mbfSeedBenefitRows($uid, $storeA, ['case' => 'unopened_period', 'period_status' => 'unopened']);
            $service->claim(mbfRequest($uid), [
                'benefit_item_id' => $unopened['item_id'],
                'fulfillment_method' => 'express_delivery',
                'address_id' => $addressId,
                'idempotency_key' => 'claim_unopened_' . $run,
            ]);
        }, 'benefit_period_not_available', $assert, 'real_unopened_period_claim_rejected');
        mbfExpectException(function () use ($service, $uid, $addressId, $storeA, $run) {
            $expired = mbfSeedBenefitRows($uid, $storeA, ['case' => 'expired_item', 'item_expire_time' => time() - 60]);
            $service->claim(mbfRequest($uid), [
                'benefit_item_id' => $expired['item_id'],
                'fulfillment_method' => 'express_delivery',
                'address_id' => $addressId,
                'idempotency_key' => 'claim_expired_' . $run,
            ]);
        }, 'benefit_item_expired', $assert, 'real_expired_item_claim_rejected');
        foreach ([
            ['frozen', ['instance_status' => 'frozen']],
            ['closed', ['instance_status' => 'closed']],
            ['refunded', ['instance_refund_status' => 'refunded']],
        ] as $variant) {
            mbfExpectException(function () use ($service, $uid, $addressId, $storeA, $run, $variant) {
                $rows = mbfSeedBenefitRows($uid, $storeA, array_merge(['case' => $variant[0]], $variant[1]));
                $service->claim(mbfRequest($uid), [
                    'benefit_item_id' => $rows['item_id'],
                    'fulfillment_method' => 'express_delivery',
                    'address_id' => $addressId,
                    'idempotency_key' => 'claim_' . $variant[0] . '_' . $run,
                ]);
            }, 'package_instance_not_active', $assert, 'real_' . $variant[0] . '_package_claim_rejected');
        }
        mbfExpectException(function () use ($service, $uid, $addressId, $storeA, $run) {
            $rows = mbfSeedBenefitRows($uid, $storeA, ['case' => 'client_active_key']);
            $service->claim(mbfRequest($uid), [
                'benefit_item_id' => $rows['item_id'],
                'fulfillment_method' => 'express_delivery',
                'address_id' => $addressId,
                'active_key' => 'benefit_item:' . $rows['item_id'],
                'idempotency_key' => 'claim_active_key_' . $run,
            ]);
        }, 'monthly_benefit_claim_field_forbidden:active_key', $assert, 'real_claim_payload_active_key_rejected');

        $pendingCancel = mbfClaimExpress($service, $uid, $storeA, $addressId, 'pending_cancel_' . $run);
        $service->cancelByUser(mbfRequest($uid), $pendingCancel['fulfillment_id'], ['idempotency_key' => 'cancel_pending_' . $run]);
        $cancelled = mbfFulfillment($pendingCancel['fulfillment_id']);
        $cancelledItem = mbfItem($pendingCancel['item_id']);
        $assert((string)$cancelled['status'] === 'cancelled' && $cancelled['active_key'] === null, 'real_pending_cancel_releases_active_key');
        $assert((string)$cancelledItem['status'] === 'available' && (string)$cancelledItem['fulfillment_status'] === 'none', 'real_pending_cancel_does_not_consume_item');
        $reclaim = $service->claim(mbfRequest($uid), [
            'benefit_item_id' => $pendingCancel['item_id'],
            'fulfillment_method' => 'express_delivery',
            'address_id' => $addressId,
            'idempotency_key' => 'reclaim_after_cancel_' . $run,
        ]);
        $assert((int)$reclaim['fulfillment']['id'] !== $pendingCancel['fulfillment_id'], 'real_reclaim_after_cancel_creates_new_active_fulfillment');

        $confirmedCancel = mbfClaimExpress($service, $uid, $storeA, $addressId, 'confirmed_cancel_' . $run);
        $service->adminConfirm($confirmedCancel['fulfillment_id'], ['idempotency_key' => 'confirm_cancel_' . $run], 1, $adminInfo);
        $service->cancelByUser(mbfRequest($uid), $confirmedCancel['fulfillment_id'], ['idempotency_key' => 'cancel_confirmed_' . $run]);
        $assert((string)mbfFulfillment($confirmedCancel['fulfillment_id'])['status'] === 'cancelled', 'real_confirmed_user_cancel_allowed_by_state_machine');

        foreach (['preparing', 'shipped', 'completed'] as $statusCase) {
            $flow = mbfClaimExpress($service, $uid, $storeA, $addressId, 'cancel_forbidden_' . $statusCase . '_' . $run);
            mbfMoveExpressTo($service, $flow['fulfillment_id'], $statusCase, $adminInfo, $run . '_' . $statusCase);
            mbfExpectException(function () use ($service, $uid, $flow, $run, $statusCase) {
                $service->cancelByUser(mbfRequest($uid), $flow['fulfillment_id'], ['idempotency_key' => 'cancel_forbidden_' . $statusCase . '_' . $run]);
            }, 'invalid_fulfillment_status_transition', $assert, 'real_' . $statusCase . '_user_cancel_rejected');
        }

        $legalExpress = mbfClaimExpress($service, $uid, $storeA, $addressId, 'legal_express_' . $run);
        $service->adminConfirm($legalExpress['fulfillment_id'], ['idempotency_key' => 'legal_confirm_' . $run], 1, $adminInfo);
        mbfExpectException(function () use ($service, $legalExpress, $adminInfo, $run) {
            $service->adminComplete($legalExpress['fulfillment_id'], ['idempotency_key' => 'illegal_confirmed_complete_' . $run], 1, $adminInfo);
        }, 'invalid_fulfillment_status_transition', $assert, 'real_confirmed_to_completed_rejected');
        $service->adminPrepare($legalExpress['fulfillment_id'], ['idempotency_key' => 'legal_prepare_' . $run], 1, $adminInfo);
        mbfExpectException(function () use ($service, $legalExpress, $adminInfo, $run) {
            $service->adminComplete($legalExpress['fulfillment_id'], ['idempotency_key' => 'illegal_preparing_complete_' . $run], 1, $adminInfo);
        }, 'invalid_fulfillment_status_transition', $assert, 'real_preparing_to_completed_rejected');
        mbfExpectException(function () use ($service, $legalExpress, $adminInfo, $run) {
            $service->adminShip($legalExpress['fulfillment_id'], ['delivery_no' => 'SF123', 'idempotency_key' => 'ship_missing_company_' . $run], 1, $adminInfo);
        }, 'monthly_benefit_delivery_company_required', $assert, 'real_ship_missing_delivery_company_rejected');
        mbfExpectException(function () use ($service, $legalExpress, $adminInfo, $run) {
            $service->adminShip($legalExpress['fulfillment_id'], ['delivery_company' => 'SF Express', 'idempotency_key' => 'ship_missing_no_' . $run], 1, $adminInfo);
        }, 'monthly_benefit_delivery_no_required', $assert, 'real_ship_missing_delivery_no_rejected');
        $service->adminShip($legalExpress['fulfillment_id'], ['delivery_company' => 'SF Express', 'delivery_no' => 'SF' . $run, 'idempotency_key' => 'legal_ship_' . $run], 1, $adminInfo);
        $beforeCounts = mbfBenefitCounters($legalExpress);
        $service->adminComplete($legalExpress['fulfillment_id'], ['idempotency_key' => 'legal_complete_' . $run], 1, $adminInfo);
        $service->adminComplete($legalExpress['fulfillment_id'], ['idempotency_key' => 'legal_complete_' . $run], 1, $adminInfo);
        $service->adminComplete($legalExpress['fulfillment_id'], ['idempotency_key' => 'legal_complete_second_key_' . $run], 1, $adminInfo);
        $afterCounts = mbfBenefitCounters($legalExpress);
        $assert((string)mbfFulfillment($legalExpress['fulfillment_id'])['status'] === 'completed', 'real_express_legal_path_completed');
        $assert($afterCounts['item_status'] === 'used' && $afterCounts['fulfillment_status'] === 'product_fulfilled' && $afterCounts['quantity_available'] === '0.00', 'real_final_consumes_benefit_item_once');
        $assert($afterCounts['item_used'] === $afterCounts['item_total'], 'real_final_quantity_used_equals_total');
        $assert($afterCounts['period_fulfilled'] === $beforeCounts['period_fulfilled'] + 1 && $afterCounts['instance_fulfilled'] === $beforeCounts['instance_fulfilled'] + 1, 'real_final_period_and_instance_increment_once');

        foreach (['rejected', 'cancelled', 'exception'] as $terminal) {
            $flow = mbfClaimExpress($service, $uid, $storeA, $addressId, 'terminal_' . $terminal . '_' . $run);
            if ($terminal === 'rejected') {
                $service->adminReject($flow['fulfillment_id'], ['idempotency_key' => 'reject_' . $run], 1, $adminInfo);
            } elseif ($terminal === 'cancelled') {
                $service->adminCancel($flow['fulfillment_id'], ['idempotency_key' => 'admin_cancel_' . $run], 1, $adminInfo);
            } else {
                $service->adminConfirm($flow['fulfillment_id'], ['idempotency_key' => 'exception_confirm_' . $run], 1, $adminInfo);
                $service->adminException($flow['fulfillment_id'], ['idempotency_key' => 'exception_' . $run], 1, $adminInfo);
            }
            mbfExpectException(function () use ($service, $flow, $adminInfo, $run, $terminal) {
                $service->adminComplete($flow['fulfillment_id'], ['idempotency_key' => 'complete_terminal_' . $terminal . '_' . $run], 1, $adminInfo);
            }, 'invalid_fulfillment_status_transition', $assert, 'real_' . $terminal . '_cannot_complete');
        }

        $pickup = mbfClaimPickup($service, $uid, $storeA, 'pickup_staff_' . $run);
        $service->adminConfirm($pickup['fulfillment_id'], ['idempotency_key' => 'pickup_confirm_admin_' . $run], 1, $adminInfo);
        $confirmedPickupCounters = mbfBenefitCounters($pickup);
        $confirmedPickupEventCount = (int)Db::name('yfth_benefit_fulfillment_event')->where('fulfillment_id', $pickup['fulfillment_id'])->count();
        $confirmedPickupAuditCount = (int)Db::name('yfth_audit_event')
            ->where('business_domain', 'yfth_monthly_benefit_fulfillment')
            ->where('object_type', 'benefit_fulfillment')
            ->where('object_id', (string)$pickup['fulfillment_id'])
            ->count();
        $confirmedPickupConsumptionAuditCount = (int)Db::name('yfth_audit_event')
            ->where('object_type', 'benefit_item')
            ->where('object_id', (string)$pickup['item_id'])
            ->where('action', 'product_fulfillment_complete')
            ->count();
        mbfExpectException(function () use ($service, $uid, $pickup, $storeA, $run) {
            $service->storePickupConfirm(mbfRequest($uid + 10, 'store_staff', $storeA), $pickup['fulfillment_id'], ['idempotency_key' => 'pickup_before_prepare_' . $run]);
        }, 'invalid_fulfillment_status_transition', $assert, 'real_confirmed_pickup_confirm_rejected_before_prepare');
        $assert((string)mbfFulfillment($pickup['fulfillment_id'])['status'] === 'confirmed', 'real_rejected_confirmed_pickup_keeps_fulfillment_confirmed');
        $assert(mbfBenefitCounters($pickup) === $confirmedPickupCounters, 'real_rejected_confirmed_pickup_keeps_benefit_and_package_counters');
        $assert((int)Db::name('yfth_benefit_fulfillment_event')->where('fulfillment_id', $pickup['fulfillment_id'])->count() === $confirmedPickupEventCount, 'real_rejected_confirmed_pickup_writes_no_event');
        $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_monthly_benefit_fulfillment')->where('object_type', 'benefit_fulfillment')->where('object_id', (string)$pickup['fulfillment_id'])->count() === $confirmedPickupAuditCount, 'real_rejected_confirmed_pickup_writes_no_fulfillment_audit');
        $assert((int)Db::name('yfth_audit_event')->where('object_type', 'benefit_item')->where('object_id', (string)$pickup['item_id'])->where('action', 'product_fulfillment_complete')->count() === $confirmedPickupConsumptionAuditCount, 'real_rejected_confirmed_pickup_writes_no_consumption_audit');
        mbfExpectException(function () use ($service, $pickup, $adminInfo, $run) {
            $service->adminComplete($pickup['fulfillment_id'], ['idempotency_key' => 'hq_pickup_direct_complete_' . $run], 1, $adminInfo);
        }, 'invalid_fulfillment_status_transition', $assert, 'real_hq_self_pickup_confirmed_complete_rejected');
        $service->adminPrepare($pickup['fulfillment_id'], ['idempotency_key' => 'pickup_prepare_admin_' . $run], 1, $adminInfo);
        mbfExpectException(function () use ($service, $uid, $pickup, $storeB, $run) {
            $service->storePickupConfirm(mbfRequest($uid + 20, 'store_staff', $storeB), $pickup['fulfillment_id'], ['idempotency_key' => 'cross_store_pickup_' . $run]);
        }, 'fulfillment_store_scope_forbidden', $assert, 'real_cross_store_pickup_confirm_rejected');
        mbfExpectException(function () use ($service, $uid, $pickup, $storeA, $run) {
            $service->storePickupConfirm(mbfRequest($uid, 'customer', $storeA), $pickup['fulfillment_id'], ['idempotency_key' => 'customer_pickup_' . $run]);
        }, 'store_workbench_role_forbidden', $assert, 'real_customer_pickup_confirm_rejected');
        mbfExpectException(function () use ($service, $uid, $pickup, $storeA, $run) {
            $service->storePickupConfirm(mbfRequest($uid + 30, 'service_mentor', $storeA), $pickup['fulfillment_id'], ['idempotency_key' => 'mentor_pickup_' . $run]);
        }, 'store_workbench_role_forbidden', $assert, 'real_service_mentor_pickup_confirm_rejected');
        $pickupBefore = mbfBenefitCounters($pickup);
        $service->storePickupConfirm(mbfRequest($uid + 10, 'store_staff', $storeA), $pickup['fulfillment_id'], ['idempotency_key' => 'staff_pickup_' . $run]);
        $service->storePickupConfirm(mbfRequest($uid + 10, 'store_staff', $storeA), $pickup['fulfillment_id'], ['idempotency_key' => 'staff_pickup_' . $run]);
        $service->storePickupConfirm(mbfRequest($uid + 10, 'store_staff', $storeA), $pickup['fulfillment_id'], ['idempotency_key' => 'staff_pickup_second_' . $run]);
        $pickupAfter = mbfBenefitCounters($pickup);
        $assert((string)mbfFulfillment($pickup['fulfillment_id'])['status'] === 'completed', 'real_same_store_staff_pickup_confirm_completed');
        $assert($pickupAfter['period_fulfilled'] === $pickupBefore['period_fulfilled'] + 1 && $pickupAfter['instance_fulfilled'] === $pickupBefore['instance_fulfilled'] + 1, 'real_pickup_confirm_idempotent_consumes_once');
        $pickupEvent = Db::name('yfth_benefit_fulfillment_event')
            ->where('fulfillment_id', $pickup['fulfillment_id'])
            ->where('event_type', 'pickup_confirm')
            ->find();
        $assert($pickupEvent && (string)$pickupEvent['from_status'] === 'preparing' && (string)$pickupEvent['to_status'] === 'completed', 'real_pickup_event_timeline_preparing_to_completed');
        $assert((int)Db::name('yfth_benefit_fulfillment_event')->where('fulfillment_id', $pickup['fulfillment_id'])->where('event_type', 'pickup_confirm')->count() === 1, 'real_repeated_pickup_writes_one_pickup_event');
        $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_monthly_benefit_fulfillment')->where('object_type', 'benefit_fulfillment')->where('object_id', (string)$pickup['fulfillment_id'])->where('action', 'pickup_confirm')->count() === 1, 'real_pickup_confirm_writes_one_fulfillment_audit');
        $assert((int)Db::name('yfth_audit_event')->where('object_type', 'benefit_item')->where('object_id', (string)$pickup['item_id'])->where('action', 'product_fulfillment_complete')->count() === 1, 'real_pickup_confirm_writes_one_consumption_audit');

        foreach ([
            [$uid + 11, 'store_manager'],
            [$uid + 12, 'franchisee'],
        ] as $roleCase) {
            [$roleUid, $roleCode] = $roleCase;
            $rolePickup = mbfClaimPickup($service, $uid, $storeA, 'pickup_' . $roleCode . '_' . $run);
            $service->adminConfirm($rolePickup['fulfillment_id'], ['idempotency_key' => 'pickup_' . $roleCode . '_confirm_' . $run], 1, $adminInfo);
            $service->adminPrepare($rolePickup['fulfillment_id'], ['idempotency_key' => 'pickup_' . $roleCode . '_prepare_' . $run], 1, $adminInfo);
            $service->storePickupConfirm(mbfRequest($roleUid, $roleCode, $storeA), $rolePickup['fulfillment_id'], ['idempotency_key' => 'pickup_' . $roleCode . '_done_' . $run]);
            $assert((string)mbfFulfillment($rolePickup['fulfillment_id'])['status'] === 'completed', 'real_' . $roleCode . '_pickup_confirm_allowed');
        }

        $adjacentAfter = mbfSnapshotAdjacentTables();
        $assert($adjacentBefore === $adjacentAfter, 'real_adjacent_crmeb_quota_supply_reward_tables_unchanged');

        $eventCount = Db::name('yfth_benefit_fulfillment_event')->where('fulfillment_id', $legalExpress['fulfillment_id'])->count();
        $auditCount = Db::name('yfth_audit_event')->where('business_domain', 'yfth_monthly_benefit_fulfillment')->count();
        $assert((int)$eventCount >= 5, 'real_express_event_timeline_written');
        $assert((int)$auditCount > 0, 'real_monthly_benefit_audit_written');
    } finally {
        Db::rollback();
    }
}

function mbfAssertConcurrentPickup(callable $assert, array &$notes): void
{
    $run = time() . random_int(1000, 9999);
    $customerUid = 760000 + random_int(1, 999);
    $operatorUid = $customerUid + 10;
    $adminInfo = ['id' => 1, 'level' => 0];
    /** @var MonthlyBenefitFulfillmentServices $service */
    $service = app()->make(MonthlyBenefitFulfillmentServices::class);

    $storeId = mbfSeedStore('Concurrent Pickup Store ' . $run);
    mbfSeedStoreRoles($storeId, [$operatorUid => 'store_staff']);
    $pickup = mbfClaimPickup($service, $customerUid, $storeId, 'concurrent_pickup_' . $run);
    $service->adminConfirm($pickup['fulfillment_id'], ['idempotency_key' => 'concurrent_pickup_confirm_' . $run], 1, $adminInfo);
    $service->adminPrepare($pickup['fulfillment_id'], ['idempotency_key' => 'concurrent_pickup_prepare_' . $run], 1, $adminInfo);

    $before = mbfBenefitCounters($pickup);
    $adjacentBefore = mbfSnapshotAdjacentTables();
    $keys = [];
    $workers = [];
    $command = mbfBuildPhpCommand();
    for ($i = 0; $i < 2; $i++) {
        $key = 'concurrent_pickup_worker_' . $i . '_' . $run;
        $keys[] = 'monthly_benefit:' . hash('sha256', $key);
        $environment = mbfWorkerEnvironment([
            'YFTH_MONTHLY_BENEFIT_WORKER' => 'pickup_confirm',
            'YFTH_WORKER_UID' => (string)$operatorUid,
            'YFTH_WORKER_STORE_ID' => (string)$storeId,
            'YFTH_WORKER_FULFILLMENT_ID' => (string)$pickup['fulfillment_id'],
            'YFTH_WORKER_IDEMPOTENCY_KEY' => $key,
        ]);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
        if (!is_resource($process)) {
            $assert(false, 'real_concurrent_pickup_worker_started_' . $i);
            return;
        }
        $workers[] = [$process, $pipes];
    }

    $workerResults = [];
    foreach ($workers as $index => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $assert($exitCode === 0, 'real_concurrent_pickup_worker_exits_safely_' . $index);
        if ($exitCode !== 0) {
            $notes[] = 'concurrent_pickup_worker_failed_' . $index . ':' . substr(trim($stderr ?: $stdout), 0, 200);
            continue;
        }
        $decoded = json_decode(trim($stdout), true);
        $assert(is_array($decoded) && ($decoded['status'] ?? '') === 'completed', 'real_concurrent_pickup_worker_returns_completed_' . $index);
        $workerResults[] = $decoded;
    }

    $after = mbfBenefitCounters($pickup);
    $assert(count($workerResults) === 2, 'real_concurrent_pickup_both_workers_return_safe_result');
    $assert((string)mbfFulfillment($pickup['fulfillment_id'])['status'] === 'completed', 'real_concurrent_pickup_final_status_completed');
    $assert($after['item_status'] === 'used' && $after['fulfillment_status'] === 'product_fulfilled', 'real_concurrent_pickup_benefit_item_used_once');
    $assert($after['quantity_available'] === '0.00' && $after['item_used'] === $after['item_total'], 'real_concurrent_pickup_quantity_consumed_once');
    $assert($after['period_fulfilled'] === $before['period_fulfilled'] + 1, 'real_concurrent_pickup_period_counter_increments_once');
    $assert($after['instance_fulfilled'] === $before['instance_fulfilled'] + 1, 'real_concurrent_pickup_instance_counter_increments_once');
    $assert((int)Db::name('yfth_benefit_fulfillment_event')->where('fulfillment_id', $pickup['fulfillment_id'])->where('event_type', 'pickup_confirm')->count() === 1, 'real_concurrent_pickup_writes_one_final_event');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_monthly_benefit_fulfillment')->where('object_type', 'benefit_fulfillment')->where('object_id', (string)$pickup['fulfillment_id'])->where('action', 'pickup_confirm')->count() === 1, 'real_concurrent_pickup_writes_one_fulfillment_audit');
    $assert((int)Db::name('yfth_audit_event')->where('object_type', 'benefit_item')->where('object_id', (string)$pickup['item_id'])->where('action', 'product_fulfillment_complete')->count() === 1, 'real_concurrent_pickup_writes_one_consumption_audit');
    $assert((int)Db::name('yfth_idempotency_record')->where('business_domain', 'yfth_monthly_benefit_fulfillment')->where('action_type', 'pickup_confirm')->whereIn('idempotency_key', $keys)->count() === 2, 'real_concurrent_pickup_records_both_distinct_request_keys');
    $assert(mbfSnapshotAdjacentTables() === $adjacentBefore, 'real_concurrent_pickup_keeps_adjacent_module_snapshots');
}

function mbfRunPickupConfirmWorker(): void
{
    /** @var MonthlyBenefitFulfillmentServices $service */
    $service = app()->make(MonthlyBenefitFulfillmentServices::class);
    $result = $service->storePickupConfirm(
        mbfRequest((int)getenv('YFTH_WORKER_UID'), 'store_staff', (int)getenv('YFTH_WORKER_STORE_ID')),
        (int)getenv('YFTH_WORKER_FULFILLMENT_ID'),
        ['idempotency_key' => (string)getenv('YFTH_WORKER_IDEMPOTENCY_KEY')]
    );
    echo json_encode([
        'fulfillment_id' => (int)($result['fulfillment']['id'] ?? 0),
        'status' => (string)($result['fulfillment']['status'] ?? ''),
    ], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

function mbfBootstrapApplication(): void
{
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
            ] as $mapping) {
                [$primary, $alias, $configKey] = $mapping;
                $value = getenv($primary);
                if ($value === false && $alias !== '') {
                    $value = getenv($alias);
                }
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
}

function mbfBuildPhpCommand(): array
{
    $command = [PHP_BINARY];
    $iniFile = php_ini_loaded_file();
    if (is_string($iniFile) && $iniFile !== '') {
        $command[] = '-c';
        $command[] = $iniFile;
    }
    $command[] = __FILE__;
    return $command;
}

function mbfWorkerEnvironment(array $extra): array
{
    $environment = [];
    foreach (['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $environment[$key] = $value;
        }
    }
    $connection = Config::get('database.default');
    $database = (array)Config::get('database.connections.' . $connection);
    $environment = array_merge($environment, [
        'YFTH_REAL_FLOW_ISOLATED_DB' => '1',
        'YFTH_REAL_FLOW_DB_HOSTNAME' => (string)($database['hostname'] ?? '127.0.0.1'),
        'YFTH_REAL_FLOW_DB_HOSTPORT' => (string)($database['hostport'] ?? '3306'),
        'YFTH_REAL_FLOW_DB_USERNAME' => (string)($database['username'] ?? 'root'),
        'YFTH_REAL_FLOW_DB_PASSWORD' => (string)($database['password'] ?? ''),
        'YFTH_REAL_FLOW_DB_PASSWORD_EMPTY' => (string)($database['password'] ?? '') === '' ? '1' : '0',
        'YFTH_REAL_FLOW_DB_DATABASE' => (string)($database['database'] ?? ''),
        'YFTH_REAL_FLOW_DB_PREFIX' => (string)($database['prefix'] ?? 'eb_'),
        'YFTH_REAL_FLOW_DB_CHARSET' => (string)($database['charset'] ?? 'utf8mb4'),
    ]);
    return array_merge($environment, $extra);
}

function mbfInstallRequestMacro(): void
{
    if (!Request::hasMacro('uid')) {
        Request::macro('uid', function () {
            return (int)($this->yfthTestUid ?? 0);
        });
    }
}

function mbfRequest(int $uid, string $roleCode = 'customer', int $storeId = 0): Request
{
    $request = new Request();
    $request->yfthTestUid = $uid;
    $request->withGet([
        'role_code' => $roleCode,
        'store_id' => $storeId,
    ]);
    $request->withHeader([
        'X-YFTH-Role' => $roleCode,
        'X-YFTH-Store-Id' => (string)$storeId,
    ]);
    return $request;
}

function mbfClaimExpress(MonthlyBenefitFulfillmentServices $service, int $uid, int $storeId, int $addressId, string $key): array
{
    $rows = mbfSeedBenefitRows($uid, $storeId, ['case' => $key]);
    $result = $service->claim(mbfRequest($uid), [
        'benefit_item_id' => $rows['item_id'],
        'fulfillment_method' => 'express_delivery',
        'address_id' => $addressId,
        'idempotency_key' => 'claim_' . $key,
    ]);
    $rows['fulfillment_id'] = (int)$result['fulfillment']['id'];
    return $rows;
}

function mbfClaimPickup(MonthlyBenefitFulfillmentServices $service, int $uid, int $storeId, string $key): array
{
    $rows = mbfSeedBenefitRows($uid, $storeId, ['case' => $key]);
    $result = $service->claim(mbfRequest($uid), [
        'benefit_item_id' => $rows['item_id'],
        'fulfillment_method' => 'self_pickup',
        'pickup_store_id' => $storeId,
        'idempotency_key' => 'claim_' . $key,
    ]);
    $rows['fulfillment_id'] = (int)$result['fulfillment']['id'];
    return $rows;
}

function mbfMoveExpressTo(MonthlyBenefitFulfillmentServices $service, int $fulfillmentId, string $status, array $adminInfo, string $suffix): void
{
    $service->adminConfirm($fulfillmentId, ['idempotency_key' => 'move_confirm_' . $suffix], 1, $adminInfo);
    if ($status === 'confirmed') {
        return;
    }
    $service->adminPrepare($fulfillmentId, ['idempotency_key' => 'move_prepare_' . $suffix], 1, $adminInfo);
    if ($status === 'preparing') {
        return;
    }
    $service->adminShip($fulfillmentId, ['delivery_company' => 'SF Express', 'delivery_no' => 'SF' . $suffix, 'idempotency_key' => 'move_ship_' . $suffix], 1, $adminInfo);
    if ($status === 'shipped') {
        return;
    }
    $service->adminComplete($fulfillmentId, ['idempotency_key' => 'move_complete_' . $suffix], 1, $adminInfo);
}

function mbfSeedBenefitRows(int $uid, int $storeId, array $options = []): array
{
    $now = time();
    $case = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)($options['case'] ?? random_int(1000, 9999)));
    $caseKey = substr(hash('sha256', $case), 0, 20);
    $instanceId = (int)Db::name('yfth_package_instance')->insertGetId([
        'instance_no' => 'MBFI' . $uid . '_' . $caseKey,
        'purchase_id' => random_int(100000, 999999),
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 1,
        'rule_version_id' => 1,
        'order_id' => random_int(1000000, 9999999),
        'order_sn' => 'MBFO' . substr(hash('sha256', $uid . ':' . $case . ':' . random_int(1, 999999)), 0, 24),
        'plan_id' => 0,
        'status' => (string)($options['instance_status'] ?? 'active'),
        'refund_status' => (string)($options['instance_refund_status'] ?? 'none'),
        'fulfilled_count' => 0,
        'start_time' => $now - 3600,
        'end_time' => $now + 86400,
        'activated_time' => $now - 3600,
        'close_reason' => '',
        'rule_snapshot' => '{}',
        'store_snapshot' => '{}',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $planId = (int)Db::name('yfth_benefit_plan')->insertGetId([
        'plan_no' => 'MBFP' . $uid . '_' . $caseKey,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'template_id' => 1,
        'rule_version_id' => 1,
        'month_count' => 10,
        'status' => 'active',
        'start_time' => $now - 3600,
        'end_time' => $now + 86400,
        'opened_month_no' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_package_instance')->where('id', $instanceId)->update(['plan_id' => $planId, 'update_time' => $now]);
    $periodId = (int)Db::name('yfth_benefit_period')->insertGetId([
        'plan_id' => $planId,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'month_no' => 1,
        'period_code' => '202607',
        'period_start_time' => $now - 3600,
        'period_end_time' => $now + 86400,
        'open_at' => $now - 3600,
        'expire_at' => $now + 86400,
        'status' => (string)($options['period_status'] ?? 'available'),
        'total_item_count' => 1,
        'fulfilled_item_count' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $itemId = (int)Db::name('yfth_benefit_item')->insertGetId([
        'plan_id' => $planId,
        'period_id' => $periodId,
        'package_instance_id' => $instanceId,
        'uid' => $uid,
        'store_id' => $storeId,
        'month_no' => 1,
        'benefit_template_id' => 1,
        'benefit_code' => 'product_monthly',
        'benefit_name' => 'Monthly product benefit',
        'benefit_type' => 'product',
        'quantity_total' => '1.00',
        'quantity_available' => '1.00',
        'quantity_used' => '0.00',
        'available_time' => $now - 3600,
        'expire_time' => (int)($options['item_expire_time'] ?? ($now + 86400)),
        'status' => (string)($options['item_status'] ?? 'available'),
        'fulfillment_status' => 'none',
        'source_rule_id' => random_int(1000000, 9999999),
        'add_time' => $now,
        'update_time' => $now,
    ]);
    return [
        'instance_id' => $instanceId,
        'plan_id' => $planId,
        'period_id' => $periodId,
        'item_id' => $itemId,
    ];
}

function mbfSeedStore(string $name): int
{
    $now = time();
    return mbfInsertFlexible('system_store', [
        'name' => $name,
        'introduction' => 'monthly benefit test store',
        'phone' => '13800000000',
        'address' => 'Test City',
        'detailed_address' => 'Test Street',
        'image' => '',
        'lat' => '0.000000',
        'lng' => '0.000000',
        'valid_time' => '',
        'day_time' => '',
        'is_show' => 1,
        'is_del' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function mbfSeedAddress(int $uid): int
{
    $now = time();
    return mbfInsertFlexible('user_address', [
        'uid' => $uid,
        'real_name' => 'Monthly Tester',
        'phone' => '13800000000',
        'province' => 'Test Province',
        'city' => 'Test City',
        'district' => 'Test District',
        'detail' => 'Test detail address',
        'is_del' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function mbfSeedStoreRoles(int $storeId, array $roles): void
{
    $now = time();
    foreach ($roles as $uid => $roleCode) {
        Db::name('yfth_user_store_role')->insert([
            'uid' => (int)$uid,
            'store_id' => $storeId,
            'role_code' => (string)$roleCode,
            'permission_scope' => '{}',
            'status' => 'active',
            'start_time' => $now - 3600,
            'end_time' => 0,
            'inviter_uid' => 0,
            'creator_uid' => 1,
            'active_key' => implode(':', [(int)$uid, $storeId, (string)$roleCode]),
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }
}

function mbfSeedServiceMentorIdentity(int $uid): void
{
    $now = time();
    Db::name('yfth_user_identity')->insert([
        'uid' => $uid,
        'role_code' => 'service_mentor',
        'status' => 'active',
        'source_type' => 'test',
        'source_id' => 0,
        'effective_time' => $now - 3600,
        'expire_time' => 0,
        'active_key' => $uid . ':service_mentor:test:0',
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function mbfInsertFlexible(string $table, array $data): int
{
    $columns = Db::query('SHOW COLUMNS FROM `' . mbfPrefixed($table) . '`');
    $allowed = [];
    foreach ($columns as $column) {
        $allowed[(string)$column['Field']] = true;
    }
    foreach (array_keys($data) as $field) {
        if (!isset($allowed[$field])) {
            unset($data[$field]);
        }
    }
    foreach ($columns as $column) {
        $field = (string)$column['Field'];
        if ($field === 'id' || array_key_exists($field, $data)) {
            continue;
        }
        if ((string)$column['Null'] === 'NO' && $column['Default'] === null) {
            $type = strtolower((string)$column['Type']);
            if (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'float') !== false || strpos($type, 'double') !== false) {
                $data[$field] = 0;
            } else {
                $data[$field] = '';
            }
        }
    }
    return (int)Db::name($table)->insertGetId($data);
}

function mbfPrefixed(string $table): string
{
    $connection = Config::get('database.default');
    $prefix = (string)Config::get('database.connections.' . $connection . '.prefix');
    return str_replace('`', '``', $prefix . $table);
}

function mbfBenefitCounters(array $rows): array
{
    $item = mbfItem((int)$rows['item_id']);
    $period = Db::name('yfth_benefit_period')->where('id', (int)$rows['period_id'])->find();
    $instance = Db::name('yfth_package_instance')->where('id', (int)$rows['instance_id'])->find();
    return [
        'item_status' => (string)$item['status'],
        'fulfillment_status' => (string)$item['fulfillment_status'],
        'quantity_available' => number_format((float)$item['quantity_available'], 2, '.', ''),
        'item_used' => number_format((float)$item['quantity_used'], 2, '.', ''),
        'item_total' => number_format((float)$item['quantity_total'], 2, '.', ''),
        'period_fulfilled' => (int)$period['fulfilled_item_count'],
        'instance_fulfilled' => (int)$instance['fulfilled_count'],
    ];
}

function mbfItem(int $itemId): array
{
    return Db::name('yfth_benefit_item')->where('id', $itemId)->find() ?: [];
}

function mbfFulfillment(int $id): array
{
    return Db::name('yfth_benefit_fulfillment')->where('id', $id)->find() ?: [];
}

function mbfSnapshotAdjacentTables(): array
{
    $snapshot = [];
    foreach ([
        'store_order',
        'store_order_refund',
        'store_product',
        'store_product_attr_value',
        'yfth_inventory_balance',
        'yfth_inventory_ledger',
        'yfth_product_quota_account',
        'yfth_product_quota_ledger',
        'yfth_referral_reward_ledger',
        'yfth_referral_reward_settlement',
    ] as $table) {
        if (!mbfTableExists($table)) {
            $snapshot[$table] = ['exists' => false];
            continue;
        }
        $snapshot[$table] = [
            'exists' => true,
            'count' => (int)Db::name($table)->count(),
        ];
        if ($table === 'store_product' && mbfColumnExists($table, 'stock')) {
            $snapshot[$table]['stock_sum'] = (string)Db::name($table)->sum('stock');
        }
        if ($table === 'store_product_attr_value' && mbfColumnExists($table, 'stock')) {
            $snapshot[$table]['sku_stock_sum'] = (string)Db::name($table)->sum('stock');
        }
    }
    return $snapshot;
}

function mbfTableExists(string $table): bool
{
    $connection = Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $connection . '.database');
    $rows = Db::query(
        'SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [$database, mbfPrefixed($table)]
    );
    return (int)($rows[0]['cnt'] ?? 0) > 0;
}

function mbfColumnExists(string $table, string $column): bool
{
    $connection = Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $connection . '.database');
    $rows = Db::query(
        'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$database, mbfPrefixed($table), $column]
    );
    return (int)($rows[0]['cnt'] ?? 0) > 0;
}

function mbfExpectException(callable $fn, string $expected, callable $assert, string $label): void
{
    try {
        $fn();
        $assert(false, $label);
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $expected) !== false, $label);
    }
}

function mbfExpectDuplicate(callable $fn, callable $assert, string $label): void
{
    try {
        $fn();
        $assert(false, $label);
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        $assert(strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000', $label);
    }
}
