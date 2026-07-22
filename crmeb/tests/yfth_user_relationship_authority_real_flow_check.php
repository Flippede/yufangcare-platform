<?php

use app\services\yfth\HqAuthorityUserReadServices;
use app\services\yfth\PackageMembershipReferralServices;
use app\services\yfth\UserRelationshipAuthorityServices;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$assert = function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) $failures[] = $label;
};

if ((string)getenv('YFTH_USER_RELATIONSHIP_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    $uid = 981001;
    $partnerUid = 981002;
    $oldStoreId = 9811;
    $roleStoreId = 9812;
    $now = time();
    foreach (['yfth_franchise_recruit_source', 'yfth_franchise_application'] as $table) {
        Db::name($table)->where('applicant_uid', $uid)->delete();
    }
    Db::name('yfth_user_store_role')->where('uid', $uid)->delete();
    Db::name('yfth_partner_profile')->whereIn('uid', [$uid, $partnerUid])->delete();
    Db::name('yfth_hq_customer_attribution_event')->where('uid', $uid)->delete();
    Db::name('yfth_hq_customer_attribution_current')->where('uid', $uid)->delete();
    Db::name('user')->whereIn('uid', [$uid, $partnerUid])->delete();
    Db::name('system_store')->whereIn('id', [$oldStoreId, $roleStoreId])->delete();

    foreach ([$uid => 'Authority Manager', $partnerUid => 'County Upstream'] as $id => $name) {
        Db::name('user')->insert(['uid' => $id, 'account' => 'authority_' . $id, 'nickname' => $name,
            'phone' => '139' . substr((string)$id, -8), 'status' => 1, 'user_type' => 'wechat',
            'uniqid' => 'authority-' . $id, 'add_time' => $now]);
    }
    foreach ([$oldStoreId => 'Old Customer Store', $roleStoreId => 'Approved Manager Store'] as $id => $name) {
        Db::name('system_store')->insert(['id' => $id, 'name' => $name, 'phone' => '13800000000',
            'address' => 'isolated validation', 'detailed_address' => 'isolated validation',
            'valid_time' => '00:00-23:59', 'day_time' => '1,2,3,4,5,6,7',
            'is_show' => 1, 'is_del' => 0, 'add_time' => $now]);
    }
    $attributionId = (int)Db::name('yfth_hq_customer_attribution_current')->insertGetId([
        'uid' => $uid, 'store_id' => $oldStoreId, 'status' => 'active',
        'status_reason_code' => '', 'authority_version' => 1,
        'source_type' => 'store_qr_binding', 'source_id' => '981001', 'bound_at' => $now,
        'paused_at' => 0, 'closed_at' => 0, 'close_reason' => '', 'add_time' => $now, 'update_time' => $now,
    ]);
    Db::name('yfth_hq_customer_attribution_event')->insert([
        'event_no' => 'AUTH-EVENT-' . $uid, 'attribution_current_id' => $attributionId, 'uid' => $uid,
        'authority_version' => 1, 'event_type' => 'attribution_created', 'before_store_id' => 0,
        'after_store_id' => $oldStoreId, 'before_status' => 'unassigned', 'after_status' => 'active',
        'before_status_reason_code' => 'initial_placeholder', 'after_status_reason_code' => '',
        'source_type' => 'store_qr_binding', 'source_id' => '981001',
        'source_unique_key' => hash('sha256', 'authority-relationship-' . $uid),
        'operator_uid' => $uid, 'operator_role_code' => 'customer', 'reason' => 'isolated validation',
        'request_id' => 'authority-relationship-' . $uid, 'add_time' => $now,
    ]);
    Db::name('yfth_partner_profile')->insert([
        'uid' => $partnerUid, 'rank_code' => 'county_partner', 'primary_store_id' => $oldStoreId,
        'status' => 'active', 'qualification_status' => 'effective', 'active_key' => 'partner:' . $partnerUid,
        'valid_from' => $now, 'start_time' => $now, 'create_time' => $now, 'update_time' => $now,
    ]);
    $applicationId = (int)Db::name('yfth_franchise_application')->insertGetId([
        'application_no' => 'AUTH-' . $uid, 'applicant_uid' => $uid, 'name' => 'Authority Manager',
        'phone' => '13900000001', 'city' => 'Test', 'region' => 'Test', 'intention_area' => 'Test',
        'approved_store_id' => $roleStoreId, 'status' => 'approved', 'create_time' => $now, 'update_time' => $now,
    ]);
    Db::name('yfth_franchise_recruit_source')->insert([
        'application_id' => $applicationId, 'applicant_uid' => $uid, 'source_type' => 'partner_invite',
        'direct_partner_uid' => $partnerUid, 'status' => 'frozen', 'frozen_time' => $now,
        'create_time' => $now, 'update_time' => $now,
    ]);
    Db::name('yfth_user_store_role')->insert([
        'uid' => $uid, 'store_id' => $roleStoreId, 'role_code' => 'store_manager', 'status' => 'active',
        'permission_scope' => '{}', 'start_time' => $now, 'active_key' => $uid . ':' . $roleStoreId . ':store_manager',
        'add_time' => $now, 'update_time' => $now,
    ]);

    $resolved = app()->make(UserRelationshipAuthorityServices::class)->resolve($uid);
    $assert((string)$resolved['relationship_type'] === 'business_role', 'business_role_is_current_authority');
    $assert((int)$resolved['store_id'] === $roleStoreId, 'role_store_overrides_old_customer_store');
    $assert((string)$resolved['upstream']['rank_code'] === 'county_partner', 'approved_store_recruit_source_is_single_upstream');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $uid)->value('store_id') === $oldStoreId,
        'old_customer_projection_remains_immutable_history');
    $me = app()->make(HqAuthorityUserReadServices::class)->me($uid);
    $assert((int)$me['store_id'] === $roleStoreId, 'my_attribution_api_uses_single_authority');
    $profile = app()->make(PackageMembershipReferralServices::class)->me($uid);
    $assert((string)$profile['promotion']['code_type'] === 'store_acquisition'
        && (int)$profile['promotion']['store_id'] === $roleStoreId, 'promotion_profile_uses_store_acquisition_authority');
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
    fwrite(STDERR, '[FAIL] unexpected:' . $e->getMessage() . PHP_EOL);
}

exit($failures ? 1 : 0);
