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
$adminApi = $read('../template/admin/src/api/yfth.js');
$uniApi = $read('../template/uni-app/api/yfth.js');
$adminPage = $read('../template/admin/src/pages/yfth/commissionFinance/index.vue');
$userPage = $read('../template/uni-app/pages/yfth/commission/account.vue');
$storePage = $read('../template/uni-app/pages/yfth/workbench/commission/index.vue');
$legacyReport = $read('crmeb/command/YfthCommissionLegacyReport.php');
$console = $read('config/console.php');

foreach ([
    'yfth_commission_rule_version', 'yfth_mall_commission_order_snapshot', 'yfth_commission_accrual',
    'yfth_user_commission_account', 'yfth_store_commission_account', 'yfth_commission_ledger',
    'yfth_c1_settlement_request', 'yfth_store_settlement_receiver', 'yfth_store_settlement_batch',
    'yfth_store_settlement_batch_item', 'yfth_store_settlement_return', 'yfth_store_settlement_callback',
] as $table) {
    $assert(strpos($migration, $table) !== false, 'migration_table_missing:' . $table);
}
foreach ([
    'uniq_yfth_commission_rule_version', 'uniq_yfth_mall_commission_order',
    'uniq_yfth_commission_accrual_source', 'uniq_yfth_commission_ledger_source',
    'uniq_yfth_c1_settlement_request', 'uniq_yfth_store_settlement_receiver',
    'uniq_yfth_store_settlement_request', 'uniq_yfth_store_settlement_ledger',
    'uniq_yfth_store_settlement_return_no', 'uniq_yfth_store_settlement_return_request',
    'uniq_yfth_store_settlement_callback',
] as $index) {
    $assert(strpos($migration, $index) !== false, 'migration_unique_guard_missing:' . $index);
}
foreach ([
    'unsettled_cent', 'settled_cent', 'c1_pending_cent', 'c1_paid_cent',
    'receiver_type', 'receiver_account_enc', 'merchant_no_enc', 'wechat_batch_no',
    'wechat_detail_no', 'wechat_return_no', 'return_id', 'callback_type',
    'callback_event_id', 'callback_json',
] as $field) {
    $assert(strpos($migration, $field) !== false, 'settlement_schema_field_missing:' . $field);
}
foreach ([
    'snapshotMallOrderPaid', 'completeMallOrder', 'processDue', 'refundMallOrder',
    'creditPackageCandidate', 'reversePackageCandidate', 'activeMallRule',
    "['product', \$productId]", "['category', \$categoryId]", "['all', 0]",
    'remainingItemBase', 'syncMallSnapshotStatus', "\$referrerUid === \$buyerUid",
    'money_snapshot_invalid', 'commission_b1_credit', 'commission_c1_responsibility_credit',
    'syncStoreC1Pending',
] as $needle) {
    $assert(strpos($automatic, $needle) !== false, 'automatic_service_missing:' . $needle);
}
foreach ([
    'requestUserSettlement', 'completeUserSettlement', 'storeSettlementBatches',
    'saveSettlementReceiver', 'generateSettlementBatches', 'startSettlementBatch',
    'recordSettlementCallback', 'unsettled_cent', 'settled_cent', 'c1_pending_cent',
    'wechat_batch_no', 'lock(true)', 'store_commission',
] as $needle) {
    $assert(strpos($finance, $needle) !== false, 'finance_service_missing:' . $needle);
}
foreach (['mall_order_completed', 'mall_order_refunded', 'creditPackageCandidate', 'reversePackageCandidate'] as $needle) {
    $assert(strpos($orchestrator, $needle) !== false, 'orchestrator_missing:' . $needle);
}
foreach (['order_take', 'admin_order_refund_success'] as $needle) {
    $assert(strpos($listener, $needle) !== false, 'listener_missing:' . $needle);
}
foreach (['yfth/commission/summary', 'yfth/commission/settlement', 'store_workbench/commission/settlement_batch'] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'api_route_missing:' . $needle);
}
foreach (["Route::group('commission'", "Route::get('settlement_receiver'", "Route::post('settlement_batch/generate'",
    "Route::post('settlement_batch/:id/start'", "Route::post('settlement_batch/:id/callback'"] as $needle) {
    $assert(strpos($adminRoute, $needle) !== false, 'admin_route_missing:' . $needle);
}
foreach (['yfthCommissionSettlementBatchList', 'yfthCommissionSettlementBatchGenerate',
    'yfthCommissionSettlementBatchStart', 'yfthCommissionSettlementReceiverSave'] as $needle) {
    $assert(strpos($adminApi, $needle) !== false, 'admin_api_missing:' . $needle);
}
foreach (['requestYfthCommissionSettlement', 'getYfthStoreC1Settlements',
    'completeYfthStoreC1Settlement', 'getYfthStoreCommissionSettlementBatches'] as $needle) {
    $assert(strpos($uniApi, $needle) !== false, 'uni_api_missing:' . $needle);
}
foreach (['结算批次', '微信分账', '待结算', '已结算'] as $needle) {
    $assert(strpos($adminPage . $storePage, $needle) !== false, 'settlement_page_surface_missing:' . $needle);
}
foreach (['应结算佣金', '已申请结算', '已完成结算'] as $needle) {
    $assert(strpos($userPage, $needle) !== false, 'c1_page_surface_missing:' . $needle);
}

$allFeatureSources = implode("\n", [$finance, $migration, $apiRoute, $adminRoute, $adminApi, $uniApi, $adminPage, $storePage]);
foreach ([
    'yfth_store_withdrawal', 'yfth_withdrawal_allocation', 'requestStoreWithdrawal',
    'completeStoreWithdrawal', 'own_available_cent', 'proxy_available_cent',
    'hq_frozen_cent', 'hq_withdrawn_cent', 'remaining_withdrawable_cent',
] as $forbidden) {
    $assert(strpos($allFeatureSources, $forbidden) === false, 'b1_withdrawal_model_must_be_absent:' . $forbidden);
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
echo "YFTH automatic commission settlement contract check passed\n";
