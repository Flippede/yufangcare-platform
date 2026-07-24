<?php

use app\services\yfth\AutomaticCommissionServices;
use app\services\yfth\AutomaticCommissionMigrationHealthServices;
use app\services\yfth\CommissionFinanceServices;
use app\services\yfth\CommissionProfitSharingProviderFactory;
use app\services\yfth\FailClosedCommissionProfitSharingProvider;
use app\services\yfth\MockCommissionProfitSharingProvider;
use app\services\yfth\YfthOrderSourceServices;
use app\services\order\StoreOrderTakeServices;
use crmeb\services\CacheService;
use think\facade\Config;
use think\facade\Db;

// The provider factory admits this mock only inside an explicitly isolated
// validation database. Completed batches still require a signed callback.
putenv('YFTH_COMMISSION_PROFIT_SHARING_PROVIDER=mock');
putenv('YFTH_COMMISSION_TEST_MODE=1');
putenv('YFTH_COMMISSION_TEST_CALLBACK_SECRET=yfth-isolated-commission-test-secret');
putenv('APP_ENV=testing');

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_AUTOMATIC_COMMISSION_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_AUTOMATIC_COMMISSION_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

$passes = [];
$failures = [];
$assert = function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) $passes[] = $label; else $failures[] = $label;
};
$expectFailure = function (callable $callback, string $label) use ($assert): void {
    try {
        $callback();
        $assert(false, $label);
    } catch (Throwable $e) {
        $assert(true, $label . ':' . $e->getMessage());
    }
};

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test|local|dev|v1)/i', $database), 'database_name_is_isolated:' . $database);
    $assert(trim((string)getenv('YFTH_SETTLEMENT_KEY')) !== '', 'isolated_settlement_encryption_key_present');
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    $health = app()->make(AutomaticCommissionMigrationHealthServices::class)->report();
    $assert(!empty($health['healthy']), 'complete_automatic_commission_migration_is_healthy');
    if (empty($health['healthy'])) throw new RuntimeException('automatic_commission_migration_health_failed:' . implode(',', (array)($health['missing'] ?? [])));

    $providerFactory = app()->make(CommissionProfitSharingProviderFactory::class);
    putenv('APP_ENV=local');
    $assert($providerFactory->make() instanceof FailClosedCommissionProfitSharingProvider, 'mock_provider_is_not_enabled_in_local_environment');
    putenv('APP_ENV=production');
    $assert($providerFactory->make() instanceof FailClosedCommissionProfitSharingProvider, 'mock_provider_is_not_enabled_in_production_environment');
    putenv('APP_ENV=testing');
    putenv('YFTH_REAL_FLOW_ISOLATED_DB=0');
    $assert($providerFactory->make() instanceof FailClosedCommissionProfitSharingProvider, 'mock_provider_requires_isolated_database_marker');
    putenv('YFTH_REAL_FLOW_ISOLATED_DB=1');
    $assert($providerFactory->make() instanceof MockCommissionProfitSharingProvider, 'mock_provider_requires_explicit_isolated_test_environment');

    acCleanup();
    $fixture = acCreateFixtures();
    $automatic = app()->make(AutomaticCommissionServices::class);
    $finance = app()->make(CommissionFinanceServices::class);
    $c1 = $fixture['c1'];
    $buyer = $fixture['buyer'];
    $storeA = $fixture['store_a'];
    $storeB = $fixture['store_b'];

    $global = $automatic->saveRule([
        'scope_type' => 'all', 'c1_ratio_bps' => 1000, 'b1_ratio_bps' => 500,
        'observation_days' => 0, 'enabled' => 1, 'note' => 'isolated zero-day rule',
    ], 1);
    $automatic->publishRule((int)$global['id'], 1);

    $unmarkedOrder = acCreateOrder($buyer, '10.00', 'not-yfth-source', false);
    acCreateOrderItem($unmarkedOrder, $buyer, $fixture['product_a'], 1, '10.00');
    $assert((string)($automatic->snapshotMallOrderPaid($unmarkedOrder)['reason'] ?? '') === 'order_not_yfth_commission_source',
        'shared_crmeb_order_without_yfth_source_never_enters_automatic_commission');

    // With CRMEB brokerage explicitly enabled, unrelated orders continue to use
    // its own path, while every YFTH source (including package sources) is
    // excluded before legacy brokerage can create a second commission.
    $legacyConfig = acEnableLegacyBrokerageForMatrix();
    Db::name('user')->where('uid', $c1)->update(['is_promoter' => 1, 'spread_open' => 1]);
    Db::name('store_order')->where('id', $unmarkedOrder)->update([
        'spread_uid' => $c1, 'spread_two_uid' => 0, 'one_brokerage' => '2.00',
    ]);
    $legacyTake = app()->make(StoreOrderTakeServices::class);
    $legacyBefore = acLegacyBrokerageSnapshot($c1);
    $legacyTake->backOrderBrokerage(
        (array)Db::name('store_order')->where('id', $unmarkedOrder)->find(),
        (array)Db::name('user')->where('uid', $buyer)->find()
    );
    $legacyAfter = acLegacyBrokerageSnapshot($c1);
    $assert($legacyAfter['brokerage_cent'] === $legacyBefore['brokerage_cent'] + 200
        && $legacyAfter['brokerage_count'] === $legacyBefore['brokerage_count'] + 1,
        'non_yfth_order_retains_crmeb_legacy_brokerage_when_enabled');

    $order = acCreateOrder($buyer, '100.00', 'zero-day');
    acCreateOrderItem($order, $buyer, $fixture['product_a'], 2, '100.00');
    Db::name('store_order')->where('id', $order)->update([
        'spread_uid' => $c1, 'spread_two_uid' => 0, 'one_brokerage' => '2.00',
    ]);
    $yfthLegacyBefore = acLegacyBrokerageSnapshot($c1);
    $legacyTake->backOrderBrokerage(
        (array)Db::name('store_order')->where('id', $order)->find(),
        (array)Db::name('user')->where('uid', $buyer)->find()
    );
    $assert(acLegacyBrokerageSnapshot($c1) === $yfthLegacyBefore,
        'yfth_normal_order_never_executes_crmeb_legacy_brokerage');
    $paid = $automatic->snapshotMallOrderPaid($order);
    $completed = $automatic->completeMallOrder($order);
    $assert(!empty($paid['created']) && !empty($completed['accrual_ids']), 'zero_day_order_creates_accrual');
    $assert((string)Db::name('yfth_mall_commission_order_snapshot')->where('order_id', $order)->value('status') === 'credited', 'zero_day_order_credits_immediately');
    $assert((int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent') === 1000, 'mall_c1_10_percent_exact');
    $storeAccount = acStoreAccount($storeA);
    $assert((int)$storeAccount['unsettled_cent'] === 1500, 'mall_store_unsettled_includes_c1_responsibility_and_b1_commission');
    $assert((int)$storeAccount['settled_cent'] === 0, 'mall_store_initial_settled_is_zero');
    $assert((int)$storeAccount['c1_pending_cent'] === 1000, 'mall_store_c1_pending_exact');
    $assert((int)Db::name('yfth_direct_referral_reward_candidate')->count() === 0,
        'ordinary_mall_order_never_creates_legacy_pending_candidate');
    $automatic->snapshotMallOrderPaid($order);
    $automatic->completeMallOrder($order);
    $automatic->processDue(100);
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $order)->count() === 1, 'duplicate_order_events_do_not_duplicate_accrual');
    $assert((int)Db::name('yfth_commission_ledger')->where('source_order_id', $order)->count() === 3, 'duplicate_order_events_do_not_duplicate_ledgers');

    $categoryId = (int)(preg_split('/[,|]/', (string)Db::name('store_product')->where('id', $fixture['product_b'])->value('cate_id'))[0] ?? 0);
    if ($categoryId <= 0) throw new RuntimeException('isolated_product_category_fixture_required');
    $categoryRule = $automatic->saveRule([
        'scope_type' => 'category', 'scope_id' => $categoryId,
        'c1_ratio_bps' => 1200, 'b1_ratio_bps' => 300, 'observation_days' => 0,
        'enabled' => 1, 'note' => 'isolated category precedence rule',
    ], 1);
    $automatic->publishRule((int)$categoryRule['id'], 1);
    $categoryOrder = acCreateOrder($buyer, '100.00', 'category-precedence');
    acCreateOrderItem($categoryOrder, $buyer, $fixture['product_b'], 1, '100.00');
    $automatic->snapshotMallOrderPaid($categoryOrder);
    $automatic->completeMallOrder($categoryOrder);
    $categoryAccrual = acAccrual($categoryOrder);
    $assert((string)$categoryAccrual['rule_version_id'] === (string)$categoryRule['id']
        && (int)$categoryAccrual['c1_ratio_bps'] === 1200 && (int)$categoryAccrual['b1_ratio_bps'] === 300,
        'category_rule_overrides_global_rule');

    $frozenOrder = acCreateOrder($buyer, '40.00', 'category-snapshot');
    acCreateOrderItem($frozenOrder, $buyer, $fixture['product_b'], 1, '40.00');
    $automatic->snapshotMallOrderPaid($frozenOrder);
    $updatedCategoryRule = $automatic->saveRule([
        'scope_type' => 'category', 'scope_id' => $categoryId,
        'c1_ratio_bps' => 4000, 'b1_ratio_bps' => 4000, 'observation_days' => 0,
        'enabled' => 1, 'note' => 'isolated later category rule',
    ], 1);
    $automatic->publishRule((int)$updatedCategoryRule['id'], 1);
    $automatic->completeMallOrder($frozenOrder);
    $frozenAccrual = acAccrual($frozenOrder);
    $assert((int)$frozenAccrual['rule_version_id'] === (int)$categoryRule['id']
        && (int)$frozenAccrual['c1_ratio_bps'] === 1200 && (int)$frozenAccrual['b1_ratio_bps'] === 300,
        'paid_order_keeps_immutable_category_rule_snapshot');

    $refundProductRule = $automatic->saveRule([
        'scope_type' => 'product', 'scope_id' => $fixture['product_b'],
        'c1_ratio_bps' => 1000, 'b1_ratio_bps' => 500, 'observation_days' => 0,
        'enabled' => 1, 'note' => 'isolated refund fixture product rule',
    ], 1);
    $automatic->publishRule((int)$refundProductRule['id'], 1);

    $sevenDay = $automatic->saveRule([
        'scope_type' => 'product', 'scope_id' => $fixture['product_a'],
        'c1_ratio_bps' => 1000, 'b1_ratio_bps' => 500, 'observation_days' => 7,
        'enabled' => 1, 'note' => 'isolated seven-day rule',
    ], 1);
    $automatic->publishRule((int)$sevenDay['id'], 1);
    $observingOrder = acCreateOrder($buyer, '40.00', 'observing');
    acCreateOrderItem($observingOrder, $buyer, $fixture['product_a'], 1, '40.00');
    $automatic->snapshotMallOrderPaid($observingOrder);
    $automatic->completeMallOrder($observingOrder);
    $assert((string)Db::name('yfth_commission_accrual')->where('order_id', $observingOrder)->value('status') === 'observing', 'nonzero_observation_stays_observing');
    $beforeDue = (int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent');
    Db::name('yfth_commission_accrual')->where('order_id', $observingOrder)->update(['due_at' => time() - 1]);
    $due = $automatic->processDue(100);
    $assert((int)$due['credited'] >= 1, 'due_worker_credits_nonzero_observation');
    $assert((int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent') === $beforeDue + 400, 'nonzero_observation_c1_amount_exact');

    $packageLegacyOrder = acCreateOrder($buyer, '20.00', 'package-legacy-guard');
    app()->make(YfthOrderSourceServices::class)->mark($packageLegacyOrder, 'package');
    Db::name('store_order')->where('id', $packageLegacyOrder)->update([
        'spread_uid' => $c1, 'spread_two_uid' => 0, 'one_brokerage' => '2.00',
    ]);
    $packageLegacyBefore = acLegacyBrokerageSnapshot($c1);
    $legacyTake->backOrderBrokerage(
        (array)Db::name('store_order')->where('id', $packageLegacyOrder)->find(),
        (array)Db::name('user')->where('uid', $buyer)->find()
    );
    $assert(acLegacyBrokerageSnapshot($c1) === $packageLegacyBefore,
        'yfth_package_order_never_executes_crmeb_legacy_brokerage');
    acRestoreLegacyBrokerageConfig($legacyConfig);
    $crmebBefore = acCrmebMoneySnapshot([$c1, $buyer]);

    $refundOrder = acCreateOrder($buyer, '30.00', 'refund');
    acCreateOrderItem($refundOrder, $buyer, $fixture['product_b'], 1, '30.00');
    $automatic->snapshotMallOrderPaid($refundOrder);
    $automatic->completeMallOrder($refundOrder);
    $beforeRefund = acStoreAccount($storeA);
    Db::name('store_order')->where('id', $refundOrder)->update([
        'refund_status' => 2, 'refund_price' => '30.00', 'status' => -2,
    ]);
    acCreateRefund($refundOrder, $buyer, [[
        'product_id' => $fixture['product_b'], 'cart_num' => 1,
    ]], '30.00');
    $automatic->refundMallOrder($refundOrder);
    $automatic->refundMallOrder($refundOrder);
    $refundAccrual = acAccrual($refundOrder);
    $afterRefund = acStoreAccount($storeA);
    $assert((string)$refundAccrual['status'] === 'reversed', 'full_refund_reverses_credited_accrual');
    $assert((int)$afterRefund['unsettled_cent'] === (int)$beforeRefund['unsettled_cent'] - 450, 'full_refund_reverses_store_unsettled_once');
    $assert((int)$afterRefund['reversed_cent'] >= 450, 'refund_reversal_is_recorded');

    acCreateDirectRule();
    $unsettledBeforePackages = (int)acStoreAccount($storeA)['unsettled_cent'];
    $packageAmounts = [1500, 2500, 6000];
    foreach (['buyer', 'buyer_two', 'buyer_three'] as $index => $buyerKey) {
        $packageBuyer = (int)$fixture[$buyerKey];
        $relation = (array)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $packageBuyer)->find();
        $activation = [
            'instance_id' => 980001 + $index,
            'order_id' => 0,
            'purchase_id' => 0,
            'amount_cent' => 10000,
            'relation' => $relation,
        ];
        $automatic->consumePackageActivation($activation);
        $automatic->consumePackageActivation($activation);
    }
    $packageRows = Db::name('yfth_commission_accrual')->where('source_type', 'package_activation')->order('package_sequence_no asc')->select()->toArray();
    $assert(array_map('intval', array_column($packageRows, 'c1_amount_cent')) === $packageAmounts, 'package_rewards_are_15_25_60');
    $assert(array_map('intval', array_column($packageRows, 'package_sequence_no')) === [1, 2, 3], 'package_rewards_have_unique_frozen_sequence');
    $assert((int)acStoreAccount($storeA)['unsettled_cent'] === $unsettledBeforePackages + array_sum($packageAmounts), 'package_rewards_enter_store_unsettled_responsibility_once');

    $c1PendingBefore = (int)acStoreAccount($storeA)['c1_pending_cent'];
    $request = $finance->requestUserSettlement($c1, 500, 'ac-c1-settlement-manager');
    $replay = $finance->requestUserSettlement($c1, 500, 'ac-c1-settlement-manager');
    $assert((int)$request['id'] === (int)$replay['id'], 'c1_settlement_request_is_idempotent');
    $assert((int)acStoreAccount($storeA)['c1_pending_cent'] === $c1PendingBefore, 'c1_request_does_not_double_count_store_pending');
    $paidByManager = $finance->completeUserSettlement(acContext($fixture['manager'], 'store_manager', $storeA), (int)$request['id'], [
        'request_id' => 'ac-c1-complete-manager', 'offline_ref_no' => 'OFFLINE-MANAGER-1',
        'proof_ref' => 'proof://manager', 'remark' => 'isolated offline paid by manager',
    ]);
    $paidReplay = $finance->completeUserSettlement(acContext($fixture['manager'], 'store_manager', $storeA), (int)$request['id'], [
        'request_id' => 'ac-c1-complete-manager-replay', 'remark' => 'idempotent replay',
    ]);
    $assert((string)$paidByManager['status'] === 'paid' && (string)$paidReplay['status'] === 'paid', 'manager_completion_is_idempotent');

    $staffRequest = $finance->requestUserSettlement($c1, 300, 'ac-c1-settlement-staff');
    $expectFailure(function () use ($finance, $fixture, $storeB, $staffRequest) {
        $finance->completeUserSettlement(acContext($fixture['manager_b'], 'store_manager', $storeB), (int)$staffRequest['id'], [
            'request_id' => 'ac-cross-store', 'remark' => 'must reject',
        ]);
    }, 'cross_store_c1_settlement_rejected');
    $staffPaid = $finance->completeUserSettlement(acContext($fixture['staff'], 'store_staff', $storeA), (int)$staffRequest['id'], [
        'request_id' => 'ac-c1-complete-staff', 'offline_ref_no' => 'OFFLINE-STAFF-1',
        'remark' => 'isolated offline paid by staff',
    ]);
    $assert((string)$staffPaid['status'] === 'paid', 'store_staff_can_complete_same_store_c1_settlement');
    $c1SettlementSummary = $finance->storeSummary(acContext($fixture['manager'], 'store_manager', $storeA));
    $assert((int)$c1SettlementSummary['c1_account']['unsettled_cent'] === $c1PendingBefore - 800,
        'completed_c1_settlements_reduce_store_c1_unsettled_summary');
    $assert((int)$c1SettlementSummary['c1_account']['settled_cent'] === 800,
        'completed_c1_settlements_increase_store_c1_settled_summary');

    $negativeUid = $fixture['negative'];
    $finance->adjustUser($negativeUid, -1000, 1, 'isolated negative adjustment', 'ac-user-negative');
    $expectFailure(function () use ($finance, $negativeUid) {
        $finance->requestUserSettlement($negativeUid, 1, 'ac-negative-settlement');
    }, 'negative_user_balance_cannot_request_settlement');
    $finance->adjustUser($negativeUid, 500, 1, 'future credit offsets debt', 'ac-user-offset');
    $assert((int)Db::name('yfth_user_commission_account')->where('uid', $negativeUid)->value('available_cent') === -500, 'future_credit_offsets_negative_balance_first');

    $receiver = $finance->saveSettlementReceiver($storeA, [
        'receiver_type' => 'MERCHANT_ID', 'receiver_account' => '1900000109',
        'receiver_name' => 'Isolation B1 Merchant',
    ], 1);
    $assert((string)$receiver['receiver_account_masked'] !== '' && !isset($receiver['receiver_account_enc']), 'wechat_receiver_is_encrypted_and_masked');
    $unsettledBeforeBatch = (int)acStoreAccount($storeA)['unsettled_cent'];
    $generated = $finance->generateSettlementBatches(0, time() + 60, 1);
    $assert((int)$generated['count'] === 1, 'headquarters_generates_one_b1_settlement_batch');
    $batch = $generated['list'][0];
    $assert((string)$batch['status'] === 'pending' && (int)$batch['amount_cent'] === $unsettledBeforeBatch, 'batch_freezes_unsettled_cycle_amount');
    $batchReplay = $finance->generateSettlementBatches(0, time() + 60, 1);
    $assert((int)$batchReplay['count'] === 0, 'ledger_unique_allocation_prevents_duplicate_batch');
    $started = $finance->startSettlementBatch((int)$batch['id'], 1);
    $assert((string)$started['status'] === 'processing', 'batch_enters_processing_for_wechat_adapter');
    $beforeFailedCallback = acStoreAccount($storeA);
    $expectFailure(function () use ($finance, $batch) {
        $finance->recordSettlementCallback((int)$batch['id'], ['status' => 'success'], 1);
    }, 'ordinary_admin_cannot_forge_settlement_success');
    $expectFailure(function () use ($finance, $batch) {
        acSettlementCallback($finance, $batch, 'success', 'ac-callback-invalid-signature', true);
    }, 'invalid_signed_profit_sharing_callback_is_rejected');
    $failed = acSettlementCallback($finance, $batch, 'failed', 'ac-callback-failed');
    $assert((string)$failed['batch']['status'] === 'exception', 'signed_failed_callback_marks_batch_exception');
    $assert(acStoreAccount($storeA) === $beforeFailedCallback, 'failed_callback_does_not_move_store_balance');
    $finance->startSettlementBatch((int)$batch['id'], 1);
    $settled = acSettlementCallback($finance, $batch, 'success', 'ac-callback-success');
    $duplicateSettlement = acSettlementCallback($finance, $batch, 'success', 'ac-callback-success');
    $afterSettlement = acStoreAccount($storeA);
    $assert((string)$settled['batch']['status'] === 'settled' && !empty($duplicateSettlement['duplicate']), 'trusted_callback_marks_batch_settled_once');
    $assert((int)$afterSettlement['unsettled_cent'] === 0 && (int)$afterSettlement['settled_cent'] === $unsettledBeforeBatch, 'settlement_moves_unsettled_to_settled_once');
    $assert((int)Db::name('yfth_store_settlement_callback')->where('callback_event_id', 'ac-callback-success')->count() === 1, 'settlement_callback_is_idempotent');
    $return = $finance->requestSettlementReturn((int)$batch['id'], 100, 'isolated return boundary', 'ac-return-request-1', 1);
    $returned = acSettlementReturnCallback($finance, $return, 'success', 'ac-return-success');
    $returnReplay = acSettlementReturnCallback($finance, $return, 'success', 'ac-return-success');
    $assert((string)$returned['return']['status'] === 'returned' && !empty($returnReplay['duplicate']),
        'trusted_profit_sharing_return_and_replay_are_idempotent');

    acCreateRefund($order, $buyer, [[
        'product_id' => $fixture['product_a'], 'cart_num' => 1,
    ]], '50.00');
    Db::name('store_order')->where('id', $order)->update(['refund_status' => 1, 'refund_price' => '50.00']);
    $automatic->refundMallOrder($order);
    $afterSettledRefund = acStoreAccount($storeA);
    $assert((string)acAccrual($order)['status'] === 'partially_reversed', 'partial_refund_after_settlement_marks_accrual_partially_reversed');
    $assert((int)$afterSettledRefund['unsettled_cent'] === -750, 'post_settlement_refund_creates_next_cycle_negative_adjustment');
    $assert((int)$afterSettledRefund['settled_cent'] === (int)$afterSettlement['settled_cent'], 'post_settlement_refund_preserves_completed_settlement_history');
    $assert((int)Db::name('yfth_commission_ledger')->where(['account_type' => 'store', 'source_order_id' => $order, 'direction' => 'debit'])->sum('amount_cent') === 750, 'post_settlement_refund_has_traceable_store_debit');

    // A separate B1 verifies that old cross-cycle refunds are never forgotten:
    // a settled 100.00 order, later -30.00 item refund, and new 50.00 income
    // may only share 20.00 in the next batch.
    $storeBRule = $automatic->saveRule([
        'scope_type' => 'product', 'scope_id' => $fixture['product_c'],
        'c1_ratio_bps' => 0, 'b1_ratio_bps' => 10000, 'observation_days' => 0,
        'enabled' => 1, 'note' => 'isolated store B exact refund rule',
    ], 1);
    $automatic->publishRule((int)$storeBRule['id'], 1);
    $storeBOrder = acCreateOrder($fixture['buyer_b'], '100.00', 'store-b-settled-100');
    acCreateOrderItem($storeBOrder, $fixture['buyer_b'], $fixture['product_c'], 10, '100.00');
    $automatic->snapshotMallOrderPaid($storeBOrder);
    $automatic->completeMallOrder($storeBOrder);
    $withoutReceiver = $finance->generateSettlementBatches(0, time() + 60, 1);
    $waitingBatch = array_values(array_filter($withoutReceiver['list'], function (array $row) use ($storeB) {
        return (int)$row['store_id'] === $storeB;
    }))[0] ?? [];
    $assert((string)($waitingBatch['status'] ?? '') === 'waiting_receiver', 'b1_without_receiver_stays_waiting_not_settled');
    $expectFailure(function () use ($finance, $waitingBatch) {
        $finance->startSettlementBatch((int)$waitingBatch['id'], 1);
    }, 'b1_without_receiver_cannot_start_profit_sharing');
    $finance->saveSettlementReceiver($storeB, [
        'receiver_type' => 'MERCHANT_ID', 'receiver_account' => '1900000110',
        'receiver_name' => 'Isolation B1 Merchant B',
    ], 1);
    $settledStoreB = $finance->startSettlementBatch((int)$waitingBatch['id'], 1);
    $settledStoreB = acSettlementCallback($finance, $settledStoreB, 'success', 'ac-store-b-settled-100');
    $assert((string)$settledStoreB['batch']['status'] === 'settled', 'store_b_initial_100_is_trusted_settled');

    acCreateRefund($storeBOrder, $fixture['buyer_b'], [[
        'product_id' => $fixture['product_c'], 'cart_num' => 3,
    ]], '30.00');
    Db::name('store_order')->where('id', $storeBOrder)->update(['refund_status' => 1, 'refund_price' => '30.00']);
    $automatic->refundMallOrder($storeBOrder);
    $assert((int)acStoreAccount($storeB)['unsettled_cent'] === -3000, 'settled_order_partial_refund_becomes_negative_carry');
    $storeBNewOrder = acCreateOrder($fixture['buyer_b'], '50.00', 'store-b-new-50');
    acCreateOrderItem($storeBNewOrder, $fixture['buyer_b'], $fixture['product_c'], 1, '50.00');
    $automatic->snapshotMallOrderPaid($storeBNewOrder);
    $automatic->completeMallOrder($storeBNewOrder);
    $carryBatchResult = $finance->generateSettlementBatches(time() - 60, time() + 60, 1);
    $carryBatch = array_values(array_filter($carryBatchResult['list'], function (array $row) use ($storeB) {
        return (int)$row['store_id'] === $storeB;
    }))[0] ?? [];
    $assert((int)($carryBatch['amount_cent'] ?? 0) === 2000, 'cross_cycle_negative_30_offsets_future_positive_50_to_20');
    $carryStarted = $finance->startSettlementBatch((int)$carryBatch['id'], 1);
    $carrySettled = acSettlementCallback($finance, $carryStarted, 'success', 'ac-store-b-carry-20');
    $assert((string)$carrySettled['batch']['status'] === 'settled', 'cross_cycle_net_batch_is_settled_once');

    // Freight is outside the frozen commission base, while SKU/quantity refunds
    // reverse only the matched item amounts and are safe under repeated events.
    $freightOrder = acCreateOrder($fixture['buyer_b'], '110.00', 'freight-only-refund');
    Db::name('store_order')->where('id', $freightOrder)->update(['pay_postage' => '10.00']);
    acCreateOrderItem($freightOrder, $fixture['buyer_b'], $fixture['product_c'], 1, '100.00');
    $automatic->snapshotMallOrderPaid($freightOrder);
    $automatic->completeMallOrder($freightOrder);
    $freightBefore = acStoreAccount($storeB);
    acCreateRefund($freightOrder, $fixture['buyer_b'], [], '10.00');
    $automatic->refundMallOrder($freightOrder);
    $assert((int)acStoreAccount($storeB)['unsettled_cent'] === (int)$freightBefore['unsettled_cent'], 'freight_only_refund_does_not_reverse_commission');

    $storeBDRule = $automatic->saveRule([
        'scope_type' => 'product', 'scope_id' => $fixture['product_d'],
        'c1_ratio_bps' => 0, 'b1_ratio_bps' => 10000, 'observation_days' => 0,
        'enabled' => 1, 'note' => 'isolated multi sku exact refund rule',
    ], 1);
    $automatic->publishRule((int)$storeBDRule['id'], 1);
    $multiSkuOrder = acCreateOrder($fixture['buyer_b'], '150.00', 'multi-sku-refund');
    acCreateOrderItem($multiSkuOrder, $fixture['buyer_b'], $fixture['product_c'], 2, '100.00');
    acCreateOrderItem($multiSkuOrder, $fixture['buyer_b'], $fixture['product_d'], 1, '50.00');
    $automatic->snapshotMallOrderPaid($multiSkuOrder);
    $automatic->completeMallOrder($multiSkuOrder);
    $multiBefore = (int)acStoreAccount($storeB)['unsettled_cent'];
    acCreateRefund($multiSkuOrder, $fixture['buyer_b'], [[
        'product_id' => $fixture['product_c'], 'cart_num' => 1,
    ]], '50.00');
    acCreateRefund($multiSkuOrder, $fixture['buyer_b'], [[
        'product_id' => $fixture['product_d'], 'cart_num' => 1,
    ]], '50.00');
    acCreateRefund($multiSkuOrder, $fixture['buyer_b'], [[
        'product_id' => $fixture['product_c'], 'cart_num' => 1,
    ]], '50.00');
    $automatic->refundMallOrder($multiSkuOrder);
    $automatic->refundMallOrder($multiSkuOrder);
    $multiAccruals = Db::name('yfth_commission_accrual')->where('order_id', $multiSkuOrder)->order('product_id asc')->select()->toArray();
    $assert((int)acStoreAccount($storeB)['unsettled_cent'] === $multiBefore - 15000,
        'multi_sku_partial_refunds_reverse_exact_item_quantities_once');
    $assert(count($multiAccruals) === 2 && array_sum(array_column($multiAccruals, 'reversed_b1_cent')) === 15000,
        'multiple_partial_refunds_never_exceed_original_commission');

    $storeSummary = $finance->storeSummary(acContext($fixture['manager'], 'store_manager', $storeA));
    $assert(isset($storeSummary['account']['unsettled']) && isset($storeSummary['account']['settled']), 'b1_surface_exposes_only_settlement_balances');
    $assert(isset($storeSummary['c1_account']['unsettled']) && isset($storeSummary['c1_account']['settled']),
        'store_surface_exposes_c1_settlement_balances_separately');
    foreach (['own_available_cent', 'proxy_available_cent', 'hq_frozen_cent', 'hq_withdrawn_cent'] as $forbidden) {
        $assert(!array_key_exists($forbidden, $storeSummary['account']), 'b1_surface_hides_withdrawal_bucket:' . $forbidden);
    }
    $assert(acCrmebMoneySnapshot([$c1, $buyer]) === $crmebBefore, 'commission_never_writes_crmeb_balance_brokerage_or_user_bill');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'automatic_commission')->count() >= 8, 'settlement_actions_write_audit_events');
} catch (Throwable $e) {
    $failures[] = 'exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH automatic commission and B1 settlement real flow verified.\n";

function acCleanup(): void
{
    foreach (['yfth_store_settlement_callback', 'yfth_store_settlement_return', 'yfth_store_settlement_batch_item',
        'yfth_store_settlement_batch', 'yfth_store_settlement_receiver', 'yfth_c1_settlement_request',
        'yfth_commission_ledger', 'yfth_store_commission_account', 'yfth_user_commission_account',
        'yfth_commission_accrual', 'yfth_mall_commission_order_snapshot', 'yfth_commission_rule_version',
        'yfth_commission_sequence_counter', 'yfth_commission_refund_reversal', 'yfth_commission_order_source'] as $table) {
        Db::name($table)->delete(true);
    }
    Db::name('yfth_direct_referral_reward_candidate')->whereBetween('referrer_uid', [970001, 970020])->delete();
    Db::name('yfth_direct_referral_rule_version')->delete(true);
    Db::name('yfth_hq_active_referral_current')->whereBetween('referrer_uid', [970001, 970020])->delete();
    Db::name('yfth_hq_customer_attribution_current')->whereBetween('uid', [970001, 970020])->delete();
    $orderIds = Db::name('store_order')->whereBetween('uid', [970001, 970020])->column('id');
    if ($orderIds) {
        Db::name('store_order_refund')->whereIn('store_order_id', $orderIds)->delete();
        Db::name('store_order_cart_info')->whereIn('oid', $orderIds)->delete();
    }
    Db::name('store_order')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('user_bill')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('user_brokerage')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('user_brokerage_frozen')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('user')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('system_store')->whereIn('id', [9701, 9702])->delete();
}

function acCreateFixtures(): array
{
    $now = time();
    $users = [
        'c1' => 970001, 'buyer' => 970002, 'buyer_two' => 970003, 'buyer_three' => 970004, 'manager' => 970006, 'staff' => 970007,
        'manager_b' => 970008, 'negative' => 970009, 'buyer_b' => 970010,
    ];
    foreach ($users as $name => $uid) {
        Db::name('user')->insert([
            'uid' => $uid, 'account' => 'ac_' . $name, 'nickname' => 'AC ' . $name,
            'phone' => '19' . substr((string)$uid . '000000000', 0, 9), 'status' => 1,
            'user_type' => 'wechat', 'uniqid' => 'ac' . $uid, 'now_money' => '12.34',
            'brokerage_price' => '56.78', 'add_time' => $now,
        ]);
    }
    foreach ([9701 => 'Automatic Commission Store A', 9702 => 'Automatic Commission Store B'] as $id => $name) {
        Db::name('system_store')->insert([
            'id' => $id, 'name' => $name, 'phone' => '19900000000', 'address' => 'isolated validation',
            'detailed_address' => 'isolated validation only', 'valid_time' => '00:00-23:59',
            'day_time' => '1,2,3,4,5,6,7', 'is_show' => 1, 'is_del' => 0, 'add_time' => $now,
        ]);
    }
    foreach ([$users['c1'], $users['buyer'], $users['buyer_two'], $users['buyer_three'], $users['manager'], $users['staff'], $users['negative']] as $uid) {
        acInsertAttribution($uid, 9701);
    }
    foreach ([$users['manager_b'], $users['buyer_b']] as $uid) {
        acInsertAttribution($uid, 9702);
    }
    acInsertReferral($users['c1'], $users['buyer'], 9701, 'primary');
    acInsertReferral($users['c1'], $users['buyer_two'], 9701, 'second');
    acInsertReferral($users['c1'], $users['buyer_three'], 9701, 'third');
    $products = acCloneProducts();
    return $users + [
        'store_a' => 9701, 'store_b' => 9702,
        'product_a' => $products[0], 'product_b' => $products[1],
        'product_c' => $products[2], 'product_d' => $products[3],
    ];
}

function acCloneProducts(): array
{
    $source = (array)Db::name('store_product')->order('id asc')->find();
    if (!$source) throw new RuntimeException('isolated_product_fixture_required');
    unset($source['id']);
    $ids = [];
    foreach (['A', 'B', 'C', 'D'] as $suffix) {
        $row = $source;
        $row['store_name'] = 'Automatic Commission Product ' . $suffix;
        $row['spu'] = substr('AC' . date('His') . $suffix . random_int(100, 999), 0, 13);
        $row['add_time'] = time(); $row['is_show'] = 1; $row['is_del'] = 0;
        $ids[] = (int)Db::name('store_product')->insertGetId($row);
    }
    return $ids;
}

function acInsertAttribution(int $uid, int $storeId): void
{
    Db::name('yfth_hq_customer_attribution_current')->insert([
        'uid' => $uid, 'store_id' => $storeId, 'status' => 'active', 'status_reason_code' => 'isolated_test',
        'authority_version' => 1, 'source_type' => 'isolated_test', 'source_id' => 'ac-' . $uid,
        'bound_at' => time(), 'paused_at' => 0, 'closed_at' => 0, 'close_reason' => '',
        'add_time' => time(), 'update_time' => time(),
    ]);
}

function acInsertReferral(int $referrerUid, int $referredUid, int $storeId, string $key): void
{
    Db::name('yfth_hq_active_referral_current')->where('referred_uid', $referredUid)->delete();
    $attributionId = (int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $referredUid)->value('id');
    Db::name('yfth_hq_active_referral_current')->insert([
        'relation_no' => 'ACR' . strtoupper(substr(hash('sha256', $key . microtime(true)), 0, 32)),
        'referrer_uid' => $referrerUid, 'referred_uid' => $referredUid, 'store_id' => $storeId,
        'attribution_current_id' => $attributionId, 'status' => 'active', 'active_referred_uid' => $referredUid,
        'source_type' => 'isolated_test', 'source_id' => $key,
        'source_unique_key' => hash('sha256', 'ac-referral-' . $key . microtime(true)),
        'started_at' => time(), 'paused_at' => 0, 'closed_at' => 0, 'close_reason' => '',
        'relation_version' => 1, 'request_id' => 'ac-' . $key, 'add_time' => time(), 'update_time' => time(),
    ]);
}

function acCreateOrder(int $uid, string $amount, string $suffix, bool $markYfthSource = true): int
{
    $now = time();
    $orderId = (int)Db::name('store_order')->insertGetId([
        'order_id' => 'AC' . strtoupper(substr(hash('sha256', $suffix . microtime(true)), 0, 22)),
        'uid' => $uid, 'pay_price' => $amount, 'total_price' => $amount, 'pay_postage' => '0.00',
        'paid' => 1, 'pay_time' => $now, 'pay_type' => 'test', 'add_time' => $now,
        'unique' => substr(hash('md5', $suffix . microtime(true)), 0, 32),
        'store_id' => 0, 'pid' => 0, 'status' => 2, 'refund_status' => 0,
        'refund_price' => '0.00', 'is_del' => 0, 'is_system_del' => 0, 'is_cancel' => 0,
    ]);
    if ($markYfthSource) {
        app()->make(YfthOrderSourceServices::class)->mark($orderId, 'normal_mall');
    }
    return $orderId;
}

function acCreateOrderItem(int $orderId, int $uid, int $productId, int $cartNum, string $sumTruePrice): int
{
    $cartId = 'ac-' . $orderId . '-' . $productId;
    $unitPrice = bcdiv($sumTruePrice, (string)max(1, $cartNum), 2);
    return (int)Db::name('store_order_cart_info')->insertGetId([
        'oid' => $orderId, 'uid' => $uid, 'cart_id' => $cartId,
        'product_id' => $productId, 'old_cart_id' => '', 'cart_num' => $cartNum,
        'refund_num' => 0, 'surplus_num' => $cartNum, 'split_status' => 0,
        'cart_info' => json_encode([
            'id' => $cartId, 'product_id' => $productId, 'cart_num' => $cartNum, 'sum_true_price' => $sumTruePrice,
            'truePrice' => $unitPrice,
            'productInfo' => ['id' => $productId, 'store_name' => 'Automatic Commission Product', 'price' => $unitPrice],
        ], JSON_UNESCAPED_UNICODE),
        'unique' => substr(hash('md5', $orderId . ':' . $productId . ':' . microtime(true)), 0, 32),
    ]);
}

function acCreateDirectRule(): int
{
    $now = time();
    return (int)Db::name('yfth_direct_referral_rule_version')->insertGetId([
        'rule_no' => 'ACDR' . date('YmdHis'), 'version_no' => 1, 'status' => 'published',
        'package_ratio_first_bps' => 1500, 'package_ratio_second_bps' => 2500,
        'package_ratio_third_bps' => 6000, 'package_observation_days' => 0,
        'mall_consumption_enabled' => 0, 'mall_consumption_ratio_bps' => 0,
        'effective_at' => $now, 'expires_at' => 0, 'active_key' => 'published',
        'created_uid' => 1, 'published_uid' => 1, 'published_at' => $now,
        'add_time' => $now, 'update_time' => $now,
    ]);
}

function acAccrual(int $orderId): array
{
    return (array)Db::name('yfth_commission_accrual')->where('order_id', $orderId)->order('id asc')->find();
}

function acStoreAccount(int $storeId): array
{
    return (array)Db::name('yfth_store_commission_account')->where('store_id', $storeId)->find();
}

function acContext(int $uid, string $role, int $storeId): array
{
    return ['uid' => $uid, 'role_code' => $role, 'store_id' => $storeId];
}

function acCrmebMoneySnapshot(array $uids): array
{
    return [
        'users' => Db::name('user')->whereIn('uid', $uids)->order('uid asc')->column('uid,now_money,brokerage_price', 'uid'),
        'bills' => (int)Db::name('user_bill')->whereIn('uid', $uids)->count(),
    ];
}

function acEnableLegacyBrokerageForMatrix(): string
{
    $original = Db::name('system_config')->where('menu_name', 'brokerage_func_status')->value('value');
    if ($original === null) throw new RuntimeException('brokerage_func_status_configuration_required');
    Db::name('system_config')->where('menu_name', 'brokerage_func_status')->update(['value' => json_encode(1)]);
    CacheService::clear();
    return (string)$original;
}

function acRestoreLegacyBrokerageConfig(string $original): void
{
    Db::name('system_config')->where('menu_name', 'brokerage_func_status')->update(['value' => $original]);
    CacheService::clear();
}

function acLegacyBrokerageSnapshot(int $uid): array
{
    return [
        'brokerage_cent' => (int)round((float)Db::name('user')->where('uid', $uid)->value('brokerage_price') * 100),
        'brokerage_count' => (int)Db::name('user_brokerage')->where('uid', $uid)->count(),
    ];
}

function acCreateRefund(int $orderId, int $uid, array $items, string $amount): int
{
    $now = time();
    return (int)Db::name('store_order_refund')->insertGetId([
        'order_id' => (string)Db::name('store_order')->where('id', $orderId)->value('order_id'),
        'store_order_id' => $orderId,
        'uid' => $uid,
        'refund_num' => array_sum(array_map(function (array $item) { return (int)$item['cart_num']; }, $items)),
        'refund_price' => $amount,
        'cart_info' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'is_cancel' => 0,
        'is_del' => 0,
        'is_pink_cancel' => 0,
        'refunded_time' => $now,
        'add_time' => $now,
    ]);
}

function acSettlementCallback(CommissionFinanceServices $finance, array $batch, string $status, string $eventId, bool $invalidSignature = false): array
{
    $payload = [
        'type' => 'settlement',
        'status' => $status,
        'event_id' => $eventId,
        'batch_no' => (string)$batch['batch_no'],
        'amount_cent' => (int)$batch['amount_cent'],
        'receiver_account_masked' => (string)$batch['receiver_account_masked'],
        'message' => 'isolated mock callback',
    ];
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string)time();
    $nonce = 'ac-' . substr(hash('sha256', $eventId), 0, 20);
    $secret = (string)getenv('YFTH_COMMISSION_TEST_CALLBACK_SECRET');
    $signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $raw, $invalidSignature ? $secret . '-invalid' : $secret);
    return $finance->handleTrustedSettlementCallback([
        'X-Yfth-Mock-Signature' => $signature,
        'X-Yfth-Mock-Timestamp' => $timestamp,
        'X-Yfth-Mock-Nonce' => $nonce,
        'X-Yfth-Mock-Certificate' => 'YFTH-ISOLATED-TEST',
    ], $raw);
}

function acSettlementReturnCallback(CommissionFinanceServices $finance, array $return, string $status, string $eventId): array
{
    $payload = [
        'type' => 'return',
        'status' => $status,
        'event_id' => $eventId,
        'return_no' => (string)$return['return_no'],
        'amount_cent' => (int)$return['amount_cent'],
        'message' => 'isolated mock return callback',
    ];
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string)time();
    $nonce = 'ac-return-' . substr(hash('sha256', $eventId), 0, 20);
    $signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $raw, (string)getenv('YFTH_COMMISSION_TEST_CALLBACK_SECRET'));
    return $finance->handleTrustedSettlementCallback([
        'X-Yfth-Mock-Signature' => $signature,
        'X-Yfth-Mock-Timestamp' => $timestamp,
        'X-Yfth-Mock-Nonce' => $nonce,
        'X-Yfth-Mock-Certificate' => 'YFTH-ISOLATED-TEST',
    ], $raw);
}
