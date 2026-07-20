<?php

$root = dirname(__DIR__);
$failures = [];
$assert = function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$read = function (string $file) use ($root, $assert): string {
    $path = $root . '/' . $file;
    $assert(is_file($path), 'missing:' . $file);
    return is_file($path) ? (string)file_get_contents($path) : '';
};

$automatic = $read('app/services/yfth/AutomaticCommissionServices.php');
$finance = $read('app/services/yfth/CommissionFinanceServices.php');
$migration = $read('database/migrations/20260720200000_create_yfth_automatic_commission_accounts_v1.php');
$orchestrator = $read('app/services/yfth/UnifiedRewardOrchestratorServices.php');
$listener = $read('app/listener/yfth/MallConsumptionRewardCustomEventListener.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$adminPage = $read('../template/admin/src/pages/yfth/commissionFinance/index.vue');
$userPage = $read('../template/uni-app/pages/yfth/commission/account.vue');
$storePage = $read('../template/uni-app/pages/yfth/workbench/commission/index.vue');
$legacyReport = $read('crmeb/command/YfthCommissionLegacyReport.php');
$console = $read('config/console.php');

foreach ([
    'yfth_commission_rule_version', 'yfth_mall_commission_order_snapshot', 'yfth_commission_accrual',
    'yfth_user_commission_account', 'yfth_store_commission_account', 'yfth_commission_ledger',
    'yfth_c1_withdrawal', 'yfth_store_withdrawal', 'yfth_withdrawal_allocation',
    'yfth_store_settlement_account',
] as $table) {
    $assert(strpos($migration, $table) !== false, 'migration_table_missing:' . $table);
}
foreach ([
    'uniq_yfth_commission_rule_version', 'uniq_yfth_mall_commission_order',
    'uniq_yfth_commission_accrual_source', 'uniq_yfth_commission_ledger_source',
    'uniq_yfth_c1_withdrawal_request', 'uniq_yfth_store_withdrawal_request',
] as $index) {
    $assert(strpos($migration, $index) !== false, 'migration_unique_guard_missing:' . $index);
}
foreach ([
    'snapshotMallOrderPaid', 'completeMallOrder', 'processDue', 'refundMallOrder',
    'activeMallRule', "['product', \$productId]", "['category', \$categoryId]", "['all', 0]",
    'remainingItemBase', 'syncMallSnapshotStatus', "\$referrerUid === \$buyerUid",
    'money_snapshot_invalid', 'remaining_withdrawable_cent',
] as $needle) {
    $assert(strpos($automatic, $needle) !== false, 'automatic_service_missing:' . $needle);
}
foreach ([
    'requestUserWithdrawal', 'completeUserWithdrawal', 'requestStoreWithdrawal', 'completeStoreWithdrawal',
    'settlementAccountForWithdrawal', 'store_withdrawal_fifo_inconsistent', 'lock(true)',
    'c1_pending_cent', 'hq_frozen_cent', 'hq_withdrawn_cent', 'syncStoreC1Pending',
    "\$this->assertStoreContext(\$context, false)", "\$write && \$role === 'store_staff'",
] as $needle) {
    $assert(strpos($finance, $needle) !== false, 'finance_service_missing:' . $needle);
}
foreach (['mall_order_completed', 'mall_order_refunded', 'creditPackageCandidate', 'reversePackageCandidate'] as $needle) {
    $assert(strpos($orchestrator, $needle) !== false, 'orchestrator_missing:' . $needle);
}
foreach (['order_take', 'admin_order_refund_success'] as $needle) {
    $assert(strpos($listener, $needle) !== false, 'listener_missing:' . $needle);
}
foreach (['yfth/commission/summary', 'store_workbench/commission'] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'api_route_missing:' . $needle);
}
foreach (["Route::group('commission'", 'withdrawal/:id/complete'] as $needle) {
    $assert(strpos($adminRoute, $needle) !== false, 'admin_route_missing:' . $needle);
}
foreach ([$adminPage, $userPage, $storePage] as $page) {
    $assert(strpos($page, '提现') !== false && strpos($page, '佣金') !== false, 'page_surface_missing');
}
foreach (["now_money')->update", "brokerage_price')->update", "user_bill')->insert"] as $forbidden) {
    $assert(strpos($automatic, $forbidden) === false && strpos($finance, $forbidden) === false,
        'restricted_commission_must_not_become_spendable:' . $forbidden);
}
$assert(strpos($console, 'yfth:commission-legacy-report') !== false, 'legacy_reconciliation_command_not_registered');
foreach (['read_only', 'yfth_direct_referral_reward_candidate', 'yfth_direct_referral_reward_settlement_ledger',
    'yfth_reward_settlement_record', 'row_count', 'amount_cent'] as $needle) {
    $assert(strpos($legacyReport, $needle) !== false, 'legacy_reconciliation_report_missing:' . $needle);
}
foreach (['uid', 'phone', 'openid', 'unionid'] as $forbidden) {
    $assert(strpos($legacyReport, "'" . $forbidden . "'") === false, 'legacy_report_must_not_emit_personal_field:' . $forbidden);
}

if ($failures) {
    fwrite(STDERR, "YFTH automatic commission contract check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "YFTH automatic commission contract check passed\n";
