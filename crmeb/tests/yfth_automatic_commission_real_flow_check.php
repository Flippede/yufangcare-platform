<?php

use app\services\yfth\AutomaticCommissionServices;
use app\services\yfth\CommissionFinanceServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_AUTOMATIC_COMMISSION_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_AUTOMATIC_COMMISSION_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

$passes = [];
$failures = [];
$assert = function (bool $condition, string $label) use (&$passes, &$failures): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
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

    acCleanup();
    $fixture = acCreateFixtures();
    $automatic = app()->make(AutomaticCommissionServices::class);
    $finance = app()->make(CommissionFinanceServices::class);
    $c1 = $fixture['c1'];
    $buyer = $fixture['buyer'];
    $storeA = $fixture['store_a'];
    $storeB = $fixture['store_b'];

    $crmebBefore = acCrmebMoneySnapshot([$c1, $buyer]);
    $global = $automatic->saveRule([
        'scope_type' => 'all', 'c1_ratio_bps' => 1000, 'b1_ratio_bps' => 500,
        'observation_days' => 0, 'enabled' => 1, 'note' => 'isolated zero-day rule',
    ], 1);
    $automatic->publishRule((int)$global['id'], 1);

    $order = acCreateOrder($buyer, '100.00', 'zero-day');
    acCreateOrderItem($order, $buyer, $fixture['product_a'], 1, '100.00');
    $paid = $automatic->snapshotMallOrderPaid($order);
    $completed = $automatic->completeMallOrder($order);
    $assert(!empty($paid['created']) && !empty($completed['accrual_ids']), 'zero_day_paid_and_completed_events_create_accrual');
    $assert((string)Db::name('yfth_mall_commission_order_snapshot')->where('order_id', $order)->value('status') === 'credited', 'zero_day_completion_credits_immediately');
    $assert((int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent') === 1000, 'mall_c1_10_percent_exact');
    $storeAccount = acStoreAccount($storeA);
    $assert((int)$storeAccount['own_available_cent'] === 500 && (int)$storeAccount['proxy_available_cent'] === 1000, 'mall_b1_own_and_proxy_exact');
    $assert((int)$storeAccount['c1_pending_cent'] === 1000, 'mall_b1_c1_pending_exact');
    $automatic->snapshotMallOrderPaid($order);
    $automatic->completeMallOrder($order);
    $automatic->processDue(100);
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $order)->count() === 1, 'duplicate_order_events_do_not_duplicate_accrual');
    $assert((int)Db::name('yfth_commission_ledger')->where('source_order_id', $order)->count() === 3, 'duplicate_events_do_not_duplicate_ledger');

    $selfUid = $fixture['self'];
    acInsertReferral($selfUid, $selfUid, $storeA, 'self');
    $selfOrder = acCreateOrder($selfUid, '100.00', 'self-order');
    acCreateOrderItem($selfOrder, $selfUid, $fixture['product_a'], 1, '100.00');
    $automatic->snapshotMallOrderPaid($selfOrder);
    $automatic->completeMallOrder($selfOrder);
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $selfOrder)->sum('c1_amount_cent') === 0, 'self_purchase_has_no_c1_commission');
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $selfOrder)->sum('b1_amount_cent') === 500, 'self_purchase_keeps_b1_commission');

    $noC1Uid = $fixture['no_c1'];
    $noC1Order = acCreateOrder($noC1Uid, '100.00', 'no-c1');
    acCreateOrderItem($noC1Order, $noC1Uid, $fixture['product_a'], 1, '100.00');
    $automatic->snapshotMallOrderPaid($noC1Order);
    $automatic->completeMallOrder($noC1Order);
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $noC1Order)->sum('c1_amount_cent') === 0, 'attributed_user_without_c1_has_no_c1_commission');
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $noC1Order)->sum('b1_amount_cent') === 500, 'attributed_user_without_c1_keeps_b1_commission');

    $unboundOrder = acCreateOrder($fixture['unbound'], '100.00', 'unbound');
    acCreateOrderItem($unboundOrder, $fixture['unbound'], $fixture['product_a'], 1, '100.00');
    $unbound = $automatic->snapshotMallOrderPaid($unboundOrder);
    $assert(($unbound['reason'] ?? '') === 'buyer_store_attribution_missing', 'unbound_order_safely_skips_all_commission');

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
    $assert((string)Db::name('yfth_commission_accrual')->where('order_id', $observingOrder)->value('status') === 'observing', 'seven_day_order_stays_observing');
    $balanceBeforeDue = (int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent');
    Db::name('yfth_hq_active_referral_current')->where('referred_uid', $buyer)->update([
        'status' => 'closed', 'active_referred_uid' => null, 'close_reason' => 'membership_activated', 'closed_at' => time(), 'update_time' => time(),
    ]);
    Db::name('yfth_commission_accrual')->where('order_id', $observingOrder)->update(['due_at' => time() - 1]);
    $due = $automatic->processDue(100);
    $assert((int)$due['credited'] >= 1 && (int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent') === $balanceBeforeDue + 400, 'due_task_uses_frozen_relation_snapshot_after_referral_close');
    $assert((string)Db::name('yfth_mall_commission_order_snapshot')->where('order_id', $observingOrder)->value('status') === 'credited', 'due_task_syncs_snapshot_status');

    $postCloseOrder = acCreateOrder($buyer, '20.00', 'after-referral-close');
    acCreateOrderItem($postCloseOrder, $buyer, $fixture['product_a'], 1, '20.00');
    $automatic->snapshotMallOrderPaid($postCloseOrder);
    $automatic->completeMallOrder($postCloseOrder);
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $postCloseOrder)->sum('c1_amount_cent') === 0, 'new_order_after_referral_close_has_no_c1_commission');
    $assert((int)Db::name('yfth_commission_accrual')->where('order_id', $postCloseOrder)->sum('b1_amount_cent') === 100, 'new_order_after_referral_close_keeps_b1_commission');

    $disabledGlobal = $automatic->saveRule([
        'scope_type' => 'all', 'c1_ratio_bps' => 1000, 'b1_ratio_bps' => 500,
        'observation_days' => 0, 'enabled' => 0, 'note' => 'disable global for mixed allocation',
    ], 1);
    $automatic->publishRule((int)$disabledGlobal['id'], 1);
    $productOnly = $automatic->saveRule([
        'scope_type' => 'product', 'scope_id' => $fixture['product_a'],
        'c1_ratio_bps' => 1000, 'b1_ratio_bps' => 500, 'observation_days' => 0,
        'enabled' => 1, 'note' => 'product-only mixed allocation',
    ], 1);
    $automatic->publishRule((int)$productOnly['id'], 1);
    acInsertReferral($c1, $buyer, $storeA, 'reopened');
    $mixedOrder = acCreateOrder($buyer, '100.00', 'mixed');
    $mixedItem = acCreateOrderItem($mixedOrder, $buyer, $fixture['product_a'], 2, '50.00');
    acCreateOrderItem($mixedOrder, $buyer, $fixture['product_b'], 1, '50.00');
    $automatic->snapshotMallOrderPaid($mixedOrder);
    $automatic->completeMallOrder($mixedOrder);
    $mixedAccrual = acAccrual($mixedOrder);
    $assert((int)$mixedAccrual['base_amount_cent'] === 5000, 'mixed_order_allocates_discount_across_all_items_before_rule_filter');
    $assert((int)$mixedAccrual['c1_amount_cent'] === 500 && (int)$mixedAccrual['b1_amount_cent'] === 250, 'mixed_order_commission_uses_item_base');

    Db::name('store_order_cart_info')->where('id', $mixedItem)->update(['refund_num' => 1]);
    Db::name('store_order')->where('id', $mixedOrder)->update(['refund_status' => 1, 'refund_price' => '25.00']);
    $automatic->refundMallOrder($mixedOrder, ['refunded_amount_cent' => 2500]);
    $partial = acAccrual($mixedOrder);
    $assert((int)$partial['reversed_c1_cent'] === 250 && (int)$partial['reversed_b1_cent'] === 125, 'partial_item_refund_reverses_exact_item_commission');
    $automatic->refundMallOrder($mixedOrder, ['refunded_amount_cent' => 2500]);
    $partialReplay = acAccrual($mixedOrder);
    $assert((int)$partialReplay['reversed_c1_cent'] === 250 && (int)$partialReplay['reversed_b1_cent'] === 125, 'duplicate_partial_refund_is_idempotent');

    Db::name('store_order')->where('id', $order)->update(['refund_status' => 2, 'refund_price' => '100.00', 'status' => -2]);
    $automatic->refundMallOrder($order, ['refunded_amount_cent' => 10000]);
    $assert((string)Db::name('yfth_commission_accrual')->where('order_id', $order)->value('status') === 'reversed', 'full_refund_reverses_credited_accrual');
    $assert((int)Db::name('yfth_commission_ledger')->where('source_order_id', $order)->where('direction', 'debit')->count() === 3, 'full_refund_appends_linked_negative_ledgers');

    $observingRule = $automatic->saveRule([
        'scope_type' => 'product', 'scope_id' => $fixture['product_b'],
        'c1_ratio_bps' => 1000, 'b1_ratio_bps' => 500, 'observation_days' => 7,
        'enabled' => 1, 'note' => 'observing refund cancellation',
    ], 1);
    $automatic->publishRule((int)$observingRule['id'], 1);
    $observingRefundOrder = acCreateOrder($buyer, '30.00', 'observing-refund');
    acCreateOrderItem($observingRefundOrder, $buyer, $fixture['product_b'], 1, '30.00');
    $automatic->snapshotMallOrderPaid($observingRefundOrder);
    $automatic->completeMallOrder($observingRefundOrder);
    $automatic->refundMallOrder($observingRefundOrder, ['refunded_item_amounts_cent' => [(string)Db::name('store_order_cart_info')->where('oid', $observingRefundOrder)->value('id') => 3000], 'refunded_amount_cent' => 3000]);
    $assert((string)Db::name('yfth_commission_accrual')->where('order_id', $observingRefundOrder)->value('status') === 'cancelled', 'observing_full_refund_cancels_without_credit');

    $directRuleId = acCreateDirectRule();
    $ownBeforePackages = (int)acStoreAccount($storeA)['own_available_cent'];
    $proxyCreditsBeforePackages = (int)Db::name('yfth_commission_ledger')
        ->where('source_type', 'commission_proxy_credit')->count();
    $packageAmounts = [1500, 2500, 6000];
    foreach ($packageAmounts as $index => $rewardCent) {
        $candidate = acCreatePackageCandidate($c1, $buyer, $storeA, $directRuleId, $index + 1, $rewardCent);
        $automatic->creditPackageCandidate($candidate);
        $automatic->creditPackageCandidate($candidate);
    }
    $packageRows = Db::name('yfth_commission_accrual')->where('source_type', 'package_candidate')->order('candidate_id asc')->select()->toArray();
    $assert(array_map('intval', array_column($packageRows, 'c1_amount_cent')) === $packageAmounts, 'package_first_second_third_rewards_are_15_25_60');
    $assert((int)acStoreAccount($storeA)['own_available_cent'] === $ownBeforePackages, 'package_rewards_do_not_increase_b1_own_commission');
    $proxyCreditsAfterPackages = (int)Db::name('yfth_commission_ledger')
        ->where('source_type', 'commission_proxy_credit')->count();
    $assert($proxyCreditsAfterPackages - $proxyCreditsBeforePackages === 3, 'package_rewards_increase_b1_proxy_only_once_each');

    $finance->saveSettlementAccount(acContext($fixture['manager'], 'store_manager', $storeA), [
        'account_type' => 'company', 'account_name' => 'Isolation Test Store',
        'account_no' => '6222020000001234567', 'bank_name' => 'Isolation Bank',
        'bank_branch' => 'Validation Branch', 'reserved_phone' => '19900000001',
        'contact_name' => 'Test Operator', 'contact_phone' => '19900000002',
    ]);
    $hqBefore = acStoreAccount($storeA);
    $userBeforeHqWithdrawal = (int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent');
    $storeWithdrawal = $finance->requestStoreWithdrawal(acContext($fixture['manager'], 'store_manager', $storeA), 1000, 'ac-store-withdraw-1');
    $assert((int)$storeWithdrawal['amount_cent'] === 1000 && (int)$storeWithdrawal['own_amount_cent'] + (int)$storeWithdrawal['proxy_amount_cent'] === 1000, 'b1_arbitrary_withdrawal_auto_allocates_fifo_sources');
    $assert((int)Db::name('yfth_withdrawal_allocation')->where('withdrawal_id', (int)$storeWithdrawal['id'])->sum('amount_cent') === 1000, 'b1_fifo_allocation_snapshot_totals_exactly');
    $afterB1Request = acStoreAccount($storeA);
    $assert((int)$afterB1Request['hq_frozen_cent'] === (int)$hqBefore['hq_frozen_cent'] + 1000, 'b1_request_moves_total_to_hq_frozen');
    $assert((int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent') === $userBeforeHqWithdrawal, 'b1_hq_withdrawal_does_not_reduce_c1_balance');
    $pendingBeforeHqComplete = (int)$afterB1Request['c1_pending_cent'];
    $finance->completeStoreWithdrawal((int)$storeWithdrawal['id'], 1, 'isolated offline payment complete');
    $finance->completeStoreWithdrawal((int)$storeWithdrawal['id'], 1, 'idempotent replay');
    $afterB1Complete = acStoreAccount($storeA);
    $assert((int)$afterB1Complete['hq_frozen_cent'] === (int)$hqBefore['hq_frozen_cent'] && (int)$afterB1Complete['hq_withdrawn_cent'] === (int)$hqBefore['hq_withdrawn_cent'] + 1000, 'headquarters_two_state_withdrawal_completion_exact');
    $assert((int)$afterB1Complete['c1_pending_cent'] === $pendingBeforeHqComplete, 'hq_withdrawal_does_not_reduce_c1_pending');

    acAssertFifoWithdrawal($finance, $assert, $fixture['manager_b'], 9703, 5000, 5000, 0, 'fifty');
    acAssertFifoWithdrawal($finance, $assert, $fixture['manager_b'], 9704, 18000, 10000, 8000, 'one_eighty');
    acAssertFifoWithdrawal($finance, $assert, $fixture['manager_b'], 9705, 40000, 10000, 30000, 'four_hundred');

    $userAvailable = (int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent');
    $firstC1Amount = min(500, max(1, $userAvailable));
    $c1Withdrawal = $finance->requestUserWithdrawal($c1, $firstC1Amount, 'ac-c1-withdraw-manager');
    $finance->completeUserWithdrawal(acContext($fixture['manager'], 'store_manager', $storeA), (int)$c1Withdrawal['id'], [
        'request_id' => 'ac-c1-complete-manager', 'remark' => 'offline paid by manager',
    ]);
    $finance->completeUserWithdrawal(acContext($fixture['manager'], 'store_manager', $storeA), (int)$c1Withdrawal['id'], [
        'request_id' => 'ac-c1-complete-manager-replay', 'remark' => 'replay',
    ]);
    $secondAvailable = (int)Db::name('yfth_user_commission_account')->where('uid', $c1)->value('available_cent');
    $secondC1Amount = min(300, max(1, $secondAvailable));
    $staffWithdrawal = $finance->requestUserWithdrawal($c1, $secondC1Amount, 'ac-c1-withdraw-staff');
    $finance->completeUserWithdrawal(acContext($fixture['staff'], 'store_staff', $storeA), (int)$staffWithdrawal['id'], [
        'request_id' => 'ac-c1-complete-staff', 'remark' => 'offline paid by staff',
    ]);
    $assert((string)Db::name('yfth_c1_withdrawal')->where('id', (int)$staffWithdrawal['id'])->value('status') === 'paid', 'store_staff_can_complete_own_store_c1_withdrawal');
    $expectFailure(function () use ($finance, $fixture, $storeB, $staffWithdrawal) {
        $finance->completeUserWithdrawal(acContext($fixture['manager_b'], 'store_manager', $storeB), (int)$staffWithdrawal['id'], [
            'request_id' => 'ac-cross-store', 'remark' => 'must reject',
        ]);
    }, 'cross_store_c1_withdrawal_rejected');

    $negativeUid = $fixture['negative'];
    $finance->adjustUser($negativeUid, -1000, 1, 'isolated negative adjustment', 'ac-user-negative');
    $expectFailure(function () use ($finance, $negativeUid) {
        $finance->requestUserWithdrawal($negativeUid, 1, 'ac-negative-withdraw');
    }, 'negative_user_balance_cannot_withdraw');
    $finance->adjustUser($negativeUid, 500, 1, 'future credit offsets debt', 'ac-user-offset');
    $assert((int)Db::name('yfth_user_commission_account')->where('uid', $negativeUid)->value('available_cent') === -500, 'future_credit_offsets_negative_balance_first');

    $finance->adjustStore($storeB, 'store_own', 2000, 1, 'concurrency source balance', 'ac-store-b-seed');
    $finance->saveSettlementAccount(acContext($fixture['manager_b'], 'store_manager', $storeB), [
        'account_type' => 'personal', 'account_name' => 'Concurrent Test',
        'account_no' => '6222020000007654321', 'bank_name' => 'Isolation Bank',
        'bank_branch' => 'Concurrent Branch', 'reserved_phone' => '19900000003',
        'contact_name' => 'Concurrent Test', 'contact_phone' => '19900000004',
    ]);
    $workers = acConcurrentStoreWithdrawals($storeB, $fixture['manager_b']);
    $successes = array_values(array_filter($workers, function (array $row) { return !empty($row['ok']); }));
    $workerSummary = json_encode($workers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assert(count($successes) === 1, 'concurrent_b1_withdrawals_have_one_winner_without_overdraw:' . $workerSummary);
    $assert((int)Db::name('yfth_store_withdrawal')->where('store_id', $storeB)->sum('amount_cent') === 1500, 'concurrent_frozen_total_never_exceeds_available:' . $workerSummary);

    $assert(acCrmebMoneySnapshot([$c1, $buyer]) === $crmebBefore, 'commission_never_writes_crmeb_now_money_brokerage_or_user_bill');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'automatic_commission')->count() >= 8, 'finance_actions_write_audit_events');
} catch (Throwable $e) {
    $failures[] = 'exception:' . $e->getMessage() . '@' . basename($e->getFile()) . ':' . $e->getLine();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH automatic commission, restricted balances and withdrawals real flow verified.\n";

function acCleanup(): void
{
    foreach (['yfth_withdrawal_allocation', 'yfth_store_withdrawal', 'yfth_c1_withdrawal',
        'yfth_commission_ledger', 'yfth_store_settlement_account', 'yfth_store_commission_account',
        'yfth_user_commission_account', 'yfth_commission_accrual', 'yfth_mall_commission_order_snapshot',
        'yfth_commission_rule_version'] as $table) {
        Db::name($table)->delete(true);
    }
    Db::name('yfth_direct_referral_reward_candidate')->whereBetween('referrer_uid', [970001, 970020])->delete();
    Db::name('yfth_direct_referral_rule_version')->delete(true);
    Db::name('yfth_hq_active_referral_current')->whereBetween('referrer_uid', [970001, 970020])->delete();
    Db::name('yfth_hq_customer_attribution_current')->whereBetween('uid', [970001, 970020])->delete();
    $orderIds = Db::name('store_order')->whereBetween('uid', [970001, 970020])->column('id');
    if ($orderIds) Db::name('store_order_cart_info')->whereIn('oid', $orderIds)->delete();
    Db::name('store_order')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('user_bill')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('user')->whereBetween('uid', [970001, 970020])->delete();
    Db::name('system_store')->whereIn('id', [9701, 9702, 9703, 9704, 9705])->delete();
}

function acCreateFixtures(): array
{
    $now = time();
    $users = ['c1' => 970001, 'buyer' => 970002, 'self' => 970003, 'no_c1' => 970004,
        'unbound' => 970005, 'manager' => 970006, 'staff' => 970007, 'manager_b' => 970008,
        'negative' => 970009];
    foreach ($users as $name => $uid) {
        Db::name('user')->insert([
            'uid' => $uid, 'account' => 'ac_' . $name, 'nickname' => 'AC ' . $name,
            'phone' => '19' . substr((string)$uid . '000000000', 0, 9), 'status' => 1,
            'user_type' => 'wechat', 'uniqid' => 'ac' . $uid, 'now_money' => '12.34',
            'brokerage_price' => '56.78', 'add_time' => $now,
        ]);
    }
    foreach ([
        9701 => 'Automatic Commission Store A',
        9702 => 'Automatic Commission Store B',
        9703 => 'Automatic Commission FIFO 50',
        9704 => 'Automatic Commission FIFO 180',
        9705 => 'Automatic Commission FIFO 400',
    ] as $id => $name) {
        Db::name('system_store')->insert([
            'id' => $id, 'name' => $name, 'phone' => '19900000000', 'address' => 'isolated validation',
            'detailed_address' => 'isolated validation only', 'valid_time' => '00:00-23:59',
            'day_time' => '1,2,3,4,5,6,7', 'is_show' => 1, 'is_del' => 0, 'add_time' => $now,
        ]);
    }
    foreach ([$users['c1'], $users['buyer'], $users['self'], $users['no_c1'], $users['negative']] as $uid) {
        acInsertAttribution($uid, 9701);
    }
    acInsertAttribution($users['manager_b'], 9702);
    acInsertReferral($users['c1'], $users['buyer'], 9701, 'primary');
    $products = acCloneProducts();
    return $users + ['store_a' => 9701, 'store_b' => 9702, 'product_a' => $products[0], 'product_b' => $products[1]];
}

function acAssertFifoWithdrawal(
    CommissionFinanceServices $finance,
    callable $assert,
    int $operatorUid,
    int $storeId,
    int $amountCent,
    int $expectedOwnCent,
    int $expectedProxyCent,
    string $suffix
): void {
    $finance->adjustStore($storeId, 'store_own', 10000, 1, 'FIFO own source', 'ac-fifo-own-' . $suffix);
    $finance->adjustStore($storeId, 'store_proxy', 30000, 1, 'FIFO proxy source', 'ac-fifo-proxy-' . $suffix);
    $finance->saveSettlementAccount(acContext($operatorUid, 'store_manager', $storeId), [
        'account_type' => 'company', 'account_name' => 'FIFO ' . $suffix,
        'account_no' => '622202000000' . $storeId, 'bank_name' => 'Isolation Bank',
        'bank_branch' => 'FIFO Branch', 'reserved_phone' => '19900000005',
        'contact_name' => 'FIFO Operator', 'contact_phone' => '19900000006',
    ]);
    $withdrawal = $finance->requestStoreWithdrawal(
        acContext($operatorUid, 'store_manager', $storeId),
        $amountCent,
        'ac-fifo-withdraw-' . $suffix
    );
    $assert((int)$withdrawal['own_amount_cent'] === $expectedOwnCent, 'b1_fifo_' . $suffix . '_own_allocation_exact');
    $assert((int)$withdrawal['proxy_amount_cent'] === $expectedProxyCent, 'b1_fifo_' . $suffix . '_proxy_allocation_exact');
    $allocationRows = Db::name('yfth_withdrawal_allocation')
        ->where('withdrawal_id', (int)$withdrawal['id'])
        ->fieldRaw("bucket,COALESCE(SUM(amount_cent),0) AS amount_cent")
        ->group('bucket')
        ->select()
        ->toArray();
    $allocation = [];
    foreach ($allocationRows as $row) $allocation[(string)$row['bucket']] = (int)$row['amount_cent'];
    $assert((int)($allocation['store_own'] ?? 0) === $expectedOwnCent, 'b1_fifo_' . $suffix . '_own_ledger_exact');
    $assert((int)($allocation['store_proxy'] ?? 0) === $expectedProxyCent, 'b1_fifo_' . $suffix . '_proxy_ledger_exact');
}

function acCloneProducts(): array
{
    $source = (array)Db::name('store_product')->order('id asc')->find();
    if (!$source) throw new RuntimeException('isolated_product_fixture_required');
    unset($source['id']);
    $ids = [];
    foreach (['A', 'B'] as $suffix) {
        $row = $source;
        $row['store_name'] = 'Automatic Commission Product ' . $suffix;
        $row['spu'] = substr('AC' . date('His') . $suffix . random_int(100, 999), 0, 13);
        $row['add_time'] = time();
        $row['is_show'] = 1;
        $row['is_del'] = 0;
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

function acCreateOrder(int $uid, string $amount, string $suffix): int
{
    $now = time();
    return (int)Db::name('store_order')->insertGetId([
        'order_id' => 'AC' . strtoupper(substr(hash('sha256', $suffix . microtime(true)), 0, 22)),
        'uid' => $uid, 'pay_price' => $amount, 'total_price' => $amount, 'pay_postage' => '0.00',
        'paid' => 1, 'pay_time' => $now, 'pay_type' => 'test', 'add_time' => $now,
        'unique' => substr(hash('md5', $suffix . microtime(true)), 0, 32),
        'store_id' => 0, 'pid' => 0, 'status' => 2, 'refund_status' => 0,
        'refund_price' => '0.00', 'is_del' => 0, 'is_system_del' => 0, 'is_cancel' => 0,
    ]);
}

function acCreateOrderItem(int $orderId, int $uid, int $productId, int $cartNum, string $sumTruePrice): int
{
    return (int)Db::name('store_order_cart_info')->insertGetId([
        'oid' => $orderId, 'uid' => $uid, 'cart_id' => 'ac-' . $orderId . '-' . $productId,
        'product_id' => $productId, 'old_cart_id' => '', 'cart_num' => $cartNum,
        'refund_num' => 0, 'surplus_num' => $cartNum, 'split_status' => 0,
        'cart_info' => json_encode([
            'product_id' => $productId, 'cart_num' => $cartNum,
            'sum_true_price' => $sumTruePrice,
            'truePrice' => number_format(((float)$sumTruePrice) / max(1, $cartNum), 2, '.', ''),
            'productInfo' => ['id' => $productId],
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

function acCreatePackageCandidate(int $c1, int $buyer, int $storeId, int $ruleId, int $sequence, int $rewardCent): array
{
    $now = time();
    $row = [
        'candidate_no' => 'ACPC' . $sequence . strtoupper(substr(hash('sha256', microtime(true)), 0, 20)),
        'candidate_type' => 'package_activation', 'referrer_uid' => $c1, 'referred_uid' => $buyer,
        'store_id' => $storeId, 'relation_id' => 1, 'source_business_type' => 'package_instance',
        'source_business_id' => (string)(980000 + $sequence),
        'source_unique_key' => hash('sha256', 'ac-package-' . $sequence . microtime(true)),
        'reward_sequence_no' => $sequence, 'actual_paid_amount_cent' => 10000,
        'ratio_bps' => [1 => 1500, 2 => 2500, 3 => 6000][$sequence],
        'reward_amount_cent' => $rewardCent, 'rule_version_id' => $ruleId,
        'status' => 'pending', 'responsibility_type' => 'store_package_revenue',
        'add_time' => $now, 'update_time' => $now,
    ];
    $row['id'] = (int)Db::name('yfth_direct_referral_reward_candidate')->insertGetId($row);
    return $row;
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

function acConcurrentStoreWithdrawals(int $storeId, int $operatorUid): array
{
    $processes = [];
    foreach ([1, 2] as $index) {
        $command = [PHP_BINARY];
        $loadedIni = php_ini_loaded_file();
        if (is_string($loadedIni) && $loadedIni !== '') {
            $command[] = '-c';
            $command[] = $loadedIni;
        }
        $command = array_merge($command, [
            __DIR__ . '/yfth_automatic_commission_worker.php', 'store_withdrawal',
            (string)$storeId, '1500', 'ac-concurrent-' . $index, (string)$operatorUid,
        ]);
        $env = array_merge($_ENV, [
            'YFTH_AUTOMATIC_COMMISSION_WORKER' => '1',
            'YFTH_REAL_FLOW_ISOLATED_DB' => '1',
            'YFTH_SETTLEMENT_KEY' => (string)getenv('YFTH_SETTLEMENT_KEY'),
        ]);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('automatic_commission_worker_start_failed');
        $processes[] = compact('process', 'pipes');
    }
    $rows = [];
    foreach ($processes as $item) {
        $stdout = stream_get_contents($item['pipes'][1]);
        $stderr = stream_get_contents($item['pipes'][2]);
        fclose($item['pipes'][1]);
        fclose($item['pipes'][2]);
        $exit = proc_close($item['process']);
        $decoded = json_decode(trim($stdout), true);
        if ($exit !== 0 || !is_array($decoded)) {
            throw new RuntimeException('automatic_commission_worker_failed:' . $exit . ':' . trim($stderr . ' ' . $stdout));
        }
        $rows[] = $decoded;
    }
    return $rows;
}
