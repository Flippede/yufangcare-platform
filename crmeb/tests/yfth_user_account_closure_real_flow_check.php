<?php

use app\services\yfth\UserAccountClosureServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$expectFailure = function (callable $operation, string $message, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ':no_exception');
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $message) !== false, $label . ':' . $e->getMessage());
    }
};

if ((string)getenv('YFTH_USER_ACCOUNT_CLOSURE_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_USER_ACCOUNT_CLOSURE_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

$uidSelf = 989931;
$uidHeadquarters = 989932;
$uidBlocked = 989933;
$uids = [$uidSelf, $uidHeadquarters, $uidBlocked];

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $connection = 'database.connections.' . $default . '.';
    $database = (string)Config::get($connection . 'database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) throw new RuntimeException('isolated_database_guard_failed');

    Config::set(['user_account_closure_enabled' => true], 'yfth');
    $now = time();

    $uidReferences = function (int $uid) use ($database): array {
        $rows = Db::query(
            "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA=? AND (COLUMN_NAME='uid' OR COLUMN_NAME='user_id' OR COLUMN_NAME LIKE '%\\_uid') "
            . 'ORDER BY TABLE_NAME,COLUMN_NAME',
            [$database]
        );
        $found = [];
        foreach ($rows as $row) {
            $table = (string)$row['TABLE_NAME'];
            $column = (string)$row['COLUMN_NAME'];
            $count = (int)(Db::query(
                'SELECT COUNT(*) AS aggregate FROM `' . $table . '` WHERE `' . $column . '`=?',
                [$uid]
            )[0]['aggregate'] ?? 0);
            if ($count > 0) $found[] = $table . '.' . $column . ':' . $count;
        }
        return $found;
    };

    // Remove only the isolated test UIDs from prior interrupted runs.
    foreach ($uids as $uid) {
        Db::name('store_order')->where('uid', $uid)->delete();
        Db::name('yfth_customer_follow_record')->where('uid', $uid)->delete();
        Db::name('yfth_customer_relation')->where('uid', $uid)->delete();
        Db::name('yfth_permanent_membership_event')->where('uid', $uid)->delete();
        Db::name('yfth_permanent_membership')->where('uid', $uid)->delete();
        Db::name('yfth_hq_customer_attribution_event')->where('uid', $uid)->delete();
        Db::name('yfth_hq_customer_attribution_current')->where('uid', $uid)->delete();
        Db::name('yfth_user_store_role')->where('uid', $uid)->delete();
        Db::name('user')->where('uid', $uid)->delete();
    }

    $insertUser = function (int $uid, string $account, string $phone) use ($now): void {
        Db::name('user')->insert([
            'uid' => $uid,
            'account' => $account,
            'nickname' => 'Closure Validation ' . $uid,
            'phone' => $phone,
            'status' => 1,
            'user_type' => 'h5',
            'login_type' => 'h5',
            'uniqid' => substr(hash('sha256', 'closure-' . $uid), 0, 32),
            'add_time' => $now,
        ]);
    };
    $insertAttribution = function (int $uid, int $storeId, string $suffix) use ($now): void {
        $currentId = (int)Db::name('yfth_hq_customer_attribution_current')->insertGetId([
            'uid' => $uid,
            'store_id' => $storeId,
            'status' => 'bound',
            'status_reason_code' => 'account_closure_validation',
            'authority_version' => 1,
            'source_type' => 'account_closure_validation',
            'source_id' => $suffix,
            'bound_at' => $now,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        Db::name('yfth_hq_customer_attribution_event')->insert([
            'event_no' => 'CLOSE-ATTR-' . $suffix,
            'attribution_current_id' => $currentId,
            'uid' => $uid,
            'authority_version' => 1,
            'event_type' => 'attribution_bound',
            'after_store_id' => $storeId,
            'after_status' => 'bound',
            'after_status_reason_code' => 'account_closure_validation',
            'source_type' => 'account_closure_validation',
            'source_id' => $suffix,
            'source_unique_key' => hash('sha256', 'close-attr-' . $suffix),
            'request_id' => 'close-attr-' . $suffix,
            'add_time' => $now,
        ]);
    };

    $insertUser($uidSelf, 'closure_self_validation', '13900989931');
    $insertAttribution($uidSelf, 1, 'self-' . $uidSelf);
    $relationId = (int)Db::name('yfth_customer_relation')->insertGetId([
        'uid' => $uidSelf,
        'store_id' => 1,
        'owner_uid' => 0,
        'source' => 'account_closure_validation',
        'reference_id' => $uidSelf,
        'customer_status' => 'registered',
        'status' => 'active',
        'bind_time' => $now,
        'create_time' => $now,
        'update_time' => $now,
        'active_key' => 'account-closure-' . $uidSelf,
    ]);
    Db::name('yfth_customer_follow_record')->insert([
        'customer_relation_id' => $relationId,
        'uid' => $uidSelf,
        'store_id' => 1,
        'operator_uid' => 0,
        'follow_type' => 'other',
        'content' => 'isolated account closure validation',
        'create_time' => $now,
    ]);
    $membershipId = (int)Db::name('yfth_permanent_membership')->insertGetId([
        'membership_no' => 'CLOSE-MEMBER-' . $uidSelf,
        'uid' => $uidSelf,
        'store_id' => 1,
        'source_package_instance_id' => null,
        'status' => 'active',
        'authority_version' => 1,
        'source_type' => 'headquarters_grant',
        'activated_at' => $now,
        'request_id' => 'close-member-' . $uidSelf,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::name('yfth_permanent_membership_event')->insert([
        'event_no' => 'CLOSE-MEMBER-EVENT-' . $uidSelf,
        'membership_id' => $membershipId,
        'uid' => $uidSelf,
        'store_id' => 1,
        'authority_version' => 1,
        'event_type' => 'membership_granted_by_headquarters',
        'source_type' => 'headquarters_grant',
        'source_id' => (string)$uidSelf,
        'source_unique_key' => hash('sha256', 'close-member-' . $uidSelf),
        'request_id' => 'close-member-' . $uidSelf,
        'add_time' => $now,
    ]);
    Db::name('yfth_user_store_role')->insert([
        'uid' => $uidSelf,
        'store_id' => 1,
        'role_code' => 'store_staff',
        'status' => 'active',
        'start_time' => $now,
        'active_key' => 'account-closure-role-' . $uidSelf,
        'add_time' => $now,
        'update_time' => $now,
    ]);

    $service = app()->make(UserAccountClosureServices::class);
    $hq = ['id' => 1, 'level' => 0];
    $publicPreflight = $service->preflightForUser($uidSelf);
    $assert($publicPreflight['can_close'] === true, 'self_preflight_allows_deletable_profile_membership_role_and_customer_data');
    $assert(!array_key_exists('references', $publicPreflight) && !array_key_exists('blocking_references', $publicPreflight), 'self_preflight_hides_schema_details');
    $expectFailure(function () use ($service, $uidSelf) {
        $service->closeForUser($uidSelf, ['confirmation' => '注销确认']);
    }, '确认注销', 'self_closure_requires_exact_phrase');

    $selfAuditBefore = (int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_account_closure')->where('action', 'self_close')->count();
    $result = $service->closeForUser($uidSelf, ['confirmation' => '确认注销']);
    $assert($result['closed'] === true, 'self_closure_completed');
    $assert((int)Db::name('user')->where('uid', $uidSelf)->count() === 0, 'self_user_row_deleted');
    $assert((int)Db::name('yfth_customer_relation')->where('uid', $uidSelf)->count() === 0, 'store_customer_list_relation_deleted');
    $assert((int)Db::name('yfth_customer_follow_record')->where('uid', $uidSelf)->count() === 0, 'store_customer_follow_record_deleted');
    $assert($uidReferences($uidSelf) === [], 'self_closure_has_zero_uid_shaped_database_references');
    $selfAudit = (array)Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_account_closure')
        ->where('action', 'self_close')->order('id desc')->find();
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_user_account_closure')->where('action', 'self_close')->count() === $selfAuditBefore + 1, 'self_closure_audited');
    $assert((string)($selfAudit['object_type'] ?? '') === 'closed_account'
        && strpos((string)($selfAudit['object_id'] ?? ''), (string)$uidSelf) === false
        && strpos((string)($selfAudit['before_state'] ?? ''), (string)$uidSelf) === false
        && strpos((string)($selfAudit['after_state'] ?? ''), (string)$uidSelf) === false,
        'closure_audit_retains_no_deleted_user_identifier');

    $insertUser($uidSelf, 'closure_self_validation', '13900989931');
    $assert((int)Db::name('user')->where('uid', $uidSelf)->count() === 1, 'same_account_and_phone_can_register_as_fresh_user_after_closure');
    Db::name('user')->where('uid', $uidSelf)->delete();

    $insertUser($uidHeadquarters, 'closure_hq_validation', '13900989932');
    $insertAttribution($uidHeadquarters, 1, 'hq-' . $uidHeadquarters);
    $hqPreflight = $service->preflightForHeadquarters($uidHeadquarters, $hq);
    $assert($hqPreflight['can_close'] === true && !empty($hqPreflight['references']), 'headquarters_preflight_exposes_controlled_reference_details');
    $expectFailure(function () use ($service, $uidHeadquarters, $hq) {
        $service->closeForHeadquarters($uidHeadquarters, ['confirmation' => '确认注销', 'reason' => '短'], 1, $hq);
    }, '不少于4个字', 'headquarters_reason_required');
    $hqResult = $service->closeForHeadquarters($uidHeadquarters, [
        'confirmation' => '确认注销',
        'reason' => '总部受控代办销户',
    ], 1, $hq);
    $assert($hqResult['closed'] === true && $uidReferences($uidHeadquarters) === [], 'headquarters_closure_removes_all_uid_references');

    $insertUser($uidBlocked, 'closure_blocked_validation', '13900989933');
    Db::name('store_order')->insert([
        'order_id' => 'CLOSE-BLOCK-' . $uidBlocked,
        'uid' => $uidBlocked,
        'unique' => md5('close-block-' . $uidBlocked),
        'total_num' => 1,
        'total_price' => 1,
        'pay_price' => 1,
        'paid' => 1,
        'pay_time' => $now,
        'add_time' => $now,
    ]);
    $blocked = $service->preflightForUser($uidBlocked);
    $assert($blocked['can_close'] === false, 'immutable_order_fact_blocks_complete_account_closure');
    $expectFailure(function () use ($service, $uidBlocked) {
        $service->closeForUser($uidBlocked, ['confirmation' => '确认注销']);
    }, '不可逆业务事实', 'blocked_account_is_not_partially_deleted');
    $assert((int)Db::name('user')->where('uid', $uidBlocked)->count() === 1, 'blocked_user_row_preserved');
    $assert((int)Db::name('store_order')->where('uid', $uidBlocked)->count() === 1, 'blocked_order_fact_preserved');

    Db::name('store_order')->where('uid', $uidBlocked)->delete();
    Db::name('user')->where('uid', $uidBlocked)->delete();
} catch (Throwable $e) {
    $failures[] = 'unexpected_exception:' . $e->getMessage();
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
foreach ($passes as $pass) echo "[PASS] {$pass}\n";
echo "[OK] YFTH formal user account closure real flow verified.\n";
