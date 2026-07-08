<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];

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

$migration = $read('database/migrations/20260710100000_create_yfth_referral_reward_tables.php');
$service = $read('app/services/yfth/ReferralRewardServices.php');
$apiController = $read('app/api/controller/v1/yfth/ReferralRewardController.php');
$adminController = $read('app/adminapi/controller/v1/yfth/ReferralReward.php');
$apiRoute = $read('app/api/route/yfth_service.php');
$adminRoute = $read('app/adminapi/route/yfth.php');
$adminApi = $read('../template/admin/src/api/yfth.js');
$adminPage = $read('../template/admin/src/pages/yfth/referralReward/index.vue');
$uniApi = $read('../template/uni-app/api/yfth.js');
$pagesJson = $read('../template/uni-app/pages.json');

foreach ([
    'yfth_referral_code',
    'yfth_referral_candidate',
    'yfth_referral_event',
    'yfth_referral_attribution',
    'yfth_reward_rule_version',
    'yfth_reward_rule_item',
    'yfth_reward_ledger',
    'yfth_reward_ledger_snapshot',
    'yfth_reward_adjustment',
    'yfth_reward_settlement_record',
] as $table) {
    $assert($contains($migration, $table), 'migration_contains_' . $table);
}

foreach ([
    'uniq_yfth_referral_code_code',
    'uniq_yfth_referral_candidate_active_key',
    'uniq_yfth_referral_event_idempotency',
    'uniq_yfth_referral_attr_business',
    'uniq_yfth_referral_attr_candidate_business',
    'uniq_yfth_reward_rule_scene_version',
    'uniq_yfth_reward_ledger_no',
    'uniq_yfth_reward_ledger_active_key',
    'uniq_yfth_reward_adjustment_dedupe',
    'uniq_yfth_reward_settlement_no',
    'uniq_yfth_reward_settlement_active',
] as $index) {
    $assert($contains($migration, $index), 'migration_contains_index_' . $index);
}

$assert($contains($migration, "'auth_type' => 2"), 'migration_seeds_api_permissions');
$assert($contains($migration, 'yfth-referral-reward-index'), 'migration_seeds_referral_page_menu');
$assert($contains($migration, 'DELETE FROM `') && $contains($migration, 'system_menus'), 'migration_down_removes_seeded_menus');

$assert($contains($service, "private const DOMAIN = 'yfth_referral_reward'"), 'service_uses_yfth_referral_reward_domain');
$assert($contains($service, 'CurrentBusinessContextServices::class'), 'service_uses_current_business_context_for_store_scope');
$assert($contains($service, 'AdminStoreContextServices::class') && $contains($service, 'assertHeadquarterScope'), 'service_requires_headquarter_scope');
$assert($contains($service, 'assertNoClientOwnerFields'), 'service_rejects_client_owner_fields');
$assert($contains($apiController, '$request->post()'), 'api_controller_checks_raw_post_fields');
$assert($contains($service, 'referral_code_create'), 'service_audits_referral_code_create');
$assert($contains($service, 'referral_candidate_bind'), 'service_audits_candidate_bind');
$assert($contains($service, 'referral_event_record'), 'service_audits_event_record');
$assert($contains($service, 'referral_attribution_create'), 'service_audits_attribution');
$assert($contains($service, 'reward_rule_publish'), 'service_audits_rule_publish');
$assert($contains($service, 'reward_ledger_create'), 'service_audits_ledger_create');
$assert($contains($service, 'reward_settlement_mark'), 'service_audits_settlement_mark');
$assert($contains($service, 'reward_reverse'), 'service_audits_reverse');
$assert($contains($service, 'sanitizeState'), 'service_sanitizes_snapshots');

$assert($contains($service, 'published_reward_rule_immutable'), 'service_blocks_published_rule_update');
$assert($contains($service, 'amount_cent') && !$contains($service, '(float)'), 'service_uses_integer_cents_without_float');
$assert($contains($service, 'active_key') && $contains($service, 'candidateActiveKey'), 'service_has_candidate_active_key_guard');
$assert($contains($service, 'idempotency_key') && $contains($migration, 'uniq_yfth_referral_event_idempotency'), 'service_and_migration_cover_event_idempotency');
$assert($contains($service, 'createLedgerForAttribution') && $contains($service, 'activeKey = implode'), 'service_generates_unique_ledger_active_key');
$assert($contains($service, 'YfthRewardLedgerSnapshotDao::class') && $contains($migration, 'yfth_reward_ledger_snapshot'), 'service_writes_ledger_snapshots');
$assert($contains($service, 'YfthRewardAdjustmentDao::class'), 'service_writes_append_only_adjustments');
$assert($contains($service, 'marked_settled') && $contains($service, 'offline_ref_no'), 'service_marks_offline_settlement_only');
$assert($contains($service, 'package_activated') && $contains($service, 'franchise_opened'), 'service_creates_observing_ledger_only_on_valid_business_events');
$assert($contains($service, 'package_refunded') && $contains($service, 'franchise_terminated'), 'service_reverses_on_negative_business_events');
$assert($contains($service, 'adminScan') && $contains($service, 'observe_end_time'), 'service_supports_observing_scan_to_valid');

foreach ([
    'user_spread',
    'user_brokerage',
    'user_bill',
    'now_money',
    'withdraw',
    'store_order',
    'store_product_attr_value',
    'decStockIncSales',
    'incStockDecSales',
] as $forbidden) {
    $assert(!$contains($service, $forbidden), 'service_does_not_touch_' . $forbidden);
}

$assert($contains($apiRoute, 'yfth/referral/code') && $contains($apiRoute, 'AuthTokenMiddleware::class'), 'user_routes_use_user_token');
$assert($contains($apiRoute, 'yfth/referral/ledger/:id'), 'user_routes_include_ledger_detail');
$assert($contains($adminRoute, "Route::group('referral_reward'"), 'admin_route_has_referral_group');
$assert($contains($adminRoute, 'AdminAuthTokenMiddleware::class') && $contains($adminRoute, 'AdminCheckRoleMiddleware::class'), 'admin_route_uses_admin_middlewares');
$assert($contains($adminController, 'assertApiAuthForAdmin'), 'admin_controller_explicit_permission_assertion');
$assert($contains($adminController, "yfth/referral_reward/ledger/<id>/settle"), 'admin_controller_asserts_settlement_permission');

$assert($contains($adminApi, 'yfthReferralRewardRuleList') && $contains($adminApi, 'yfthRewardLedgerReverse'), 'admin_api_wrappers_exist');
$assert($contains($adminPage, 'yfthReferralRewardRuleList') && $contains($adminPage, '线下结算'), 'admin_page_uses_real_api_and_offline_settlement_text');
$assert(!$contains($adminPage, '提现') && !$contains($adminPage, '钱包到账'), 'admin_page_avoids_withdraw_wallet_text');
$assert($contains($uniApi, 'createYfthReferralCode') && $contains($uniApi, 'getYfthRewardLedgerDetail'), 'uni_api_wrappers_exist');
foreach ([
    'referral/index',
    'referral/code',
    'referral/candidates',
    'referral/ledger',
    'referral/ledger_detail',
] as $page) {
    $assert($contains($pagesJson, $page), 'pages_json_registers_' . str_replace('/', '_', $page));
}

if ($failures) {
    echo "YFTH referral reward contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo '[OK] YFTH referral reward contract check passed with ' . count($passes) . " assertions.\n";
