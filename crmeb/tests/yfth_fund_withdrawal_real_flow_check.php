<?php

use app\services\yfth\FundWithdrawalServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

if ((string)getenv('YFTH_FUND_WITHDRAWAL_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_FUND_WITHDRAWAL_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$expect = function (callable $operation, string $message, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ':no_exception');
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $message) !== false, $label . ':' . $e->getMessage());
    }
};

try {
    putenv('YFTH_SETTLEMENT_KEY=fund-withdrawal-isolated-validation-key');
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    $service = app()->make(FundWithdrawalServices::class);
    $create = new ReflectionMethod(FundWithdrawalServices::class, 'createRequest');
    $create->setAccessible(true);
    $summary = new ReflectionMethod(FundWithdrawalServices::class, 'ownerSummary');
    $summary->setAccessible(true);
    $storeApplicant = new ReflectionMethod(FundWithdrawalServices::class, 'assertStoreApplicant');
    $storeApplicant->setAccessible(true);
    $storeReader = new ReflectionMethod(FundWithdrawalServices::class, 'assertStoreReader');
    $storeReader->setAccessible(true);
    $run = 970000000 + random_int(1000, 9999);
    $now = time();

    Db::startTrans();
    try {
        Db::name('yfth_fund_withdrawal_setting')->where('id', 1)->update([
            'partner_observation_days' => 7,
            'store_observation_days' => 0,
            'enabled' => 1,
            'update_time' => $now,
        ]);

        $partnerUid = $run + 1;
        Db::name('yfth_procurement_profit_ledger')->insert([
            'snapshot_id' => 0,
            'purchase_order_id' => $run,
            'store_id' => 0,
            'beneficiary_uid' => $partnerUid,
            'rank_code' => 'county_partner',
            'entry_type' => 'procurement_profit',
            'base_amount_cent' => 500000,
            'rate_bps' => 2000,
            'amount_cent' => 100000,
            'status' => 'pending',
            'source_unique_key' => 'withdrawal-real-flow-' . $run,
            'settled_time' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $observing = $summary->invoke($service, 'partner', $partnerUid, 'county_partner', false);
        $assert((int)$observing['available_cent'] === 0 && (int)$observing['observing_cent'] === 100000, 'partner_observation_period_blocks_early_withdrawal');
        $expect(function () use ($create, $service, $partnerUid) {
            $create->invoke($service, 'partner', $partnerUid, $partnerUid, 'county_partner', 0, withdrawalPayload('partner-early', 10000));
        }, 'fund_withdrawal_available_insufficient', 'early_partner_request_rejected');

        Db::name('yfth_procurement_profit_ledger')->where('source_unique_key', 'withdrawal-real-flow-' . $run)
            ->update(['create_time' => $now - 8 * 86400]);
        $partnerRequest = $create->invoke(
            $service, 'partner', $partnerUid, $partnerUid, 'county_partner', 0,
            withdrawalPayload('partner-paid-' . $run, 60000)
        );
        $partnerReplay = $create->invoke(
            $service, 'partner', $partnerUid, $partnerUid, 'county_partner', 0,
            withdrawalPayload('partner-paid-' . $run, 60000)
        );
        $assert((int)$partnerRequest['id'] === (int)$partnerReplay['id'], 'partner_request_idempotent');
        $expect(function () use ($service, $partnerRequest) {
            $service->confirmPaid((int)$partnerRequest['id'], 'BANK-BEFORE-APPROVE', 'must fail before approval', 1);
        }, 'fund_withdrawal_pay_status_invalid', 'finance_cannot_pay_before_approval');
        $service->review((int)$partnerRequest['id'], 'approve', 'isolated approval', 1);
        $service->confirmPaid((int)$partnerRequest['id'], 'BANK-PARTNER-' . $run, 'isolated bank payment', 1);
        $service->confirmPaid((int)$partnerRequest['id'], 'BANK-PARTNER-' . $run, 'isolated bank replay', 1);
        $partnerAfter = $summary->invoke($service, 'partner', $partnerUid, 'county_partner', false);
        $assert((int)$partnerAfter['available_cent'] === 40000 && (int)$partnerAfter['paid_cent'] === 60000, 'partner_paid_offsets_available_once');

        Db::name('yfth_partner_opening_reward_ledger')->insert([
            'application_id' => $run,
            'store_id' => 0,
            'partner_uid' => $partnerUid,
            'rank_code' => 'county_partner',
            'rule_version_id' => 0,
            'amount_cent' => 20000,
            'status' => 'pending',
            'source_unique_key' => 'withdrawal-reject-' . $run,
            'create_time' => $now - 8 * 86400,
            'update_time' => $now,
        ]);
        $rejected = $create->invoke(
            $service, 'partner', $partnerUid, $partnerUid, 'county_partner', 0,
            withdrawalPayload('partner-reject-' . $run, 20000)
        );
        $service->review((int)$rejected['id'], 'reject', 'isolated rejection', 1);
        $assert((int)Db::name('yfth_fund_withdrawal_allocation')->where('request_id', (int)$rejected['id'])
            ->where('status', 'released')->count() > 0, 'rejection_releases_frozen_sources');

        $changed = $create->invoke(
            $service, 'partner', $partnerUid, $partnerUid, 'county_partner', 0,
            withdrawalPayload('partner-changed-' . $run, 20000)
        );
        Db::name('yfth_partner_opening_reward_ledger')->where('source_unique_key', 'withdrawal-reject-' . $run)
            ->update(['status' => 'reversed', 'update_time' => time()]);
        $expect(function () use ($service, $changed) {
            $service->review((int)$changed['id'], 'approve', 'source changed before review', 1);
        }, 'fund_withdrawal_funds_changed', 'refund_or_dispute_change_blocks_approval');

        $storeId = $run + 2;
        $managerUid = $run + 3;
        $staffUid = $run + 4;
        $managerContext = ['uid' => $managerUid, 'store_id' => $storeId, 'role_code' => 'store_manager'];
        $staffContext = ['uid' => $staffUid, 'store_id' => $storeId, 'role_code' => 'store_staff'];
        $assert((int)$storeApplicant->invoke($service, $managerContext) === $storeId, 'store_manager_can_create_withdrawal');
        $assert((int)$storeReader->invoke($service, $managerContext) === $storeId, 'store_manager_can_read_withdrawal');
        $assert((int)$storeReader->invoke($service, $staffContext) === $storeId, 'store_staff_can_read_withdrawal');
        $expect(function () use ($storeApplicant, $service, $staffContext) {
            $storeApplicant->invoke($service, $staffContext);
        }, 'store_withdrawal_manager_required', 'store_staff_cannot_create_withdrawal');
        $expect(function () use ($storeReader, $service, $storeId) {
            $storeReader->invoke($service, ['uid' => 1, 'store_id' => $storeId, 'role_code' => 'customer']);
        }, 'store_withdrawal_scope_forbidden', 'non_store_role_cannot_read_withdrawal');

        Db::name('yfth_store_commission_account')->insert([
            'store_id' => $storeId,
            'unsettled_cent' => 100000,
            'settled_cent' => 0,
            'c1_pending_cent' => 0,
            'c1_paid_cent' => 0,
            'reversed_cent' => 0,
            'version' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        $storeRequest = $create->invoke(
            $service, 'store', $storeId, $managerUid, '', $storeId,
            withdrawalPayload('store-paid-' . $run, 40000)
        );
        $accountPending = Db::name('yfth_store_commission_account')->where('store_id', $storeId)->find();
        $assert((int)$accountPending['unsettled_cent'] === 100000 && (int)$accountPending['settled_cent'] === 0, 'store_request_does_not_offset_before_payment');
        $service->review((int)$storeRequest['id'], 'approve', 'store withdrawal approved', 1);
        $accountApproved = Db::name('yfth_store_commission_account')->where('store_id', $storeId)->find();
        $assert((int)$accountApproved['unsettled_cent'] === 100000, 'store_approval_still_does_not_offset');
        $service->confirmPaid((int)$storeRequest['id'], 'BANK-STORE-' . $run, 'store bank payment complete', 1);
        $service->confirmPaid((int)$storeRequest['id'], 'BANK-STORE-' . $run, 'store bank payment replay', 1);
        $accountPaid = Db::name('yfth_store_commission_account')->where('store_id', $storeId)->find();
        $assert((int)$accountPaid['unsettled_cent'] === 60000 && (int)$accountPaid['settled_cent'] === 40000, 'store_paid_offsets_account_once');
        $assert((int)Db::name('yfth_commission_ledger')->where([
            'account_type' => 'store',
            'account_id' => $storeId,
            'source_type' => 'store_manual_withdrawal_paid',
        ])->count() === 1, 'store_payment_writes_one_immutable_debit_ledger');

        Db::rollback();
    } catch (Throwable $e) {
        Db::rollback();
        throw $e;
    }
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
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
echo "[OK] YFTH fund withdrawal finance real flow verified.\n";

function withdrawalPayload(string $requestId, int $amountCent): array
{
    return [
        'request_id' => $requestId,
        'amount_cent' => $amountCent,
        'receiver_name' => 'TEST Receiver',
        'receiver_account' => '6222020202020202020',
        'bank_name' => 'TEST Bank',
        'remark' => 'isolated validation only',
    ];
}
