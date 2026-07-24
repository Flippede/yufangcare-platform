<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) throw new RuntimeException('missing_file:' . $path);
    return (string)file_get_contents($full);
};
$assert = function (bool $ok, string $label) use (&$failures, &$passes): void { $ok ? $passes[] = $label : $failures[] = $label; };
$has = function (string $text, string $needle): bool { return strpos($text, $needle) !== false; };

$migration = $read('database/migrations/20260720100000_create_yfth_partner_reward_unification_v1.php');
$orchestrator = $read('app/services/yfth/UnifiedRewardOrchestratorServices.php');
$opening = $read('app/services/yfth/FranchiseOpeningServices.php');
$partner = $read('app/services/yfth/FranchisePartnerServices.php');
$reward = $read('app/services/yfth/DirectReferralRewardServices.php');
$quota = $read('app/services/yfth/ProductQuotaPurchaseServices.php');
$supply = $read('app/services/yfth/SupplyChainServices.php');
$brokerage = $read('app/services/order/StoreOrderTakeServices.php');
$payListener = $read('app/listener/yfth/MallConsumptionRewardPayListener.php');
$refundListener = $read('app/listener/yfth/MallConsumptionRewardCustomEventListener.php');
$context = $read('app/services/yfth/CurrentBusinessContextServices.php');
$identities = $read('app/services/yfth/UserIdentityServices.php');
$constants = $read('app/services/yfth/YfthConstants.php');

foreach (['yfth_partner_store_binding', 'yfth_partner_store_binding_event', 'yfth_reward_event',
          'yfth_reward_adjustment_ledger', 'yfth_partner_opening_quota_award',
          'yfth_product_quota_reservation', 'yfth_partner_migration_issue'] as $table) {
    $assert($has($migration, $table), 'migration_table:' . $table);
}
$assert($has($migration, 'canonical_key') && $has($migration, "['unique' => true"), 'canonical_events_are_unique');
$assert($has($migration, 'backfillPartnerBindings') && $has($migration, 'duplicate_store_owner'), 'legacy_backfill_has_exception_report');
$assert($has($opening, "\$roles = ['store_manager']") && !$has($opening, "['county_partner', 'store_manager', 'all']"), 'formal_opening_grants_store_manager_only');
$assert($has($partner, 'opening_store_attributed') && !$has($partner, 'activatePartnerIdentityGrant($application'), 'opening_applicant_is_not_promoted_to_partner');
$assert($has($partner, 'ensurePartnerStoreBinding') && $has($partner, 'qualification_status'), 'partner_qualification_and_store_binding');
$assert($has($partner, 'V1 deliberately creates no hierarchy cash candidate'), 'no_multilevel_opening_cash_reward');
$assert($has($orchestrator, '$ratios = [1 => 2000, 2 => 3000, 3 => 5000]'), 'opening_quota_20_30_50');
$assert($has($orchestrator, "\$status = \$ratio > 0 ? 'pending' : 'ineligible'") && $has($orchestrator, 'confirmOpeningQuota'), 'opening_quota_requires_hq_confirmation');
$assert($has($orchestrator, 'consistencyIssues') && $has($orchestrator, 'event_result_missing'), 'reward_consistency_scan');
$assert($has($orchestrator, "'ineligible'") && $has($orchestrator, 'direct_partner_uid'), 'fourth_opening_has_no_reward');
$assert($has($orchestrator, 'retryDue') && $has($orchestrator, "'failed'"), 'durable_retry_queue');
$assert($has($payListener, 'UnifiedRewardOrchestratorServices') && $has($refundListener, 'UnifiedRewardOrchestratorServices'), 'mall_hooks_use_unified_orchestrator');
$assert($has($reward, 'partial_refund') && $has($reward, 'reversal') && $has($reward, 'yfth_reward_adjustment_ledger'), 'partial_full_settled_reversal_is_append_only');
foreach (['reserve', 'useForStockIn', 'release', 'refundUsed', 'reverseRefund'] as $method) {
    $assert($has($quota, 'function ' . $method), 'quota_purchase_method:' . $method);
}
$assert($has($supply, 'quota_amount_cent') && $has($supply, 'quota_payment'), 'purchase_supports_quota_plus_online');
$assert($has($brokerage, 'isYfthUnifiedRewardOrder') && $has($brokerage, 'yfth_package_purchase'), 'crmeb_brokerage_guard');
$assert($has($constants, 'partnerRoles') && $has($constants, "'platform_director'"), 'all_partner_ranks_are_business_roles');
$assert($has($context, 'server_partner_profile') && !$has($context, 'partner_store_binding_not_found'), 'partner_context_is_profile_scoped');
$assert($has($identities, "'source_type' => 'partner_profile'") && $has($identities, "'store_id' => 0"), 'partner_identity_is_not_store_operating_identity');
foreach (['user_brokerage', 'user_bill', 'now_money', 'integral'] as $forbidden) {
    $assert(!$has($orchestrator, $forbidden), 'orchestrator_does_not_write_crmeb_asset:' . $forbidden);
}

if ($failures) {
    fwrite(STDERR, "YFTH partner reward unification V1 contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo '[OK] YFTH partner reward unification V1 contract passed with ' . count($passes) . " assertions.\n";
