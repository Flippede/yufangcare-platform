<?php

use app\services\user\LoginServices;
use app\services\user\UserAuthServices;
use app\services\yfth\UserAccountClosureServices;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};
$expectFailure = function (callable $operation, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ':no_exception');
    } catch (Throwable $e) {
        $assert(true, $label);
    }
};

if ((string)getenv('YFTH_USER_ACCOUNT_CLOSURE_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_USER_ACCOUNT_CLOSURE_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    Config::set(['user_account_closure_enabled' => true], 'yfth');
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    $service = app()->make(UserAccountClosureServices::class);
    $login = app()->make(LoginServices::class);
    $auth = app()->make(UserAuthServices::class);
    $hq = ['id' => 1, 'level' => 0];
    $now = time();
    $base = (int)Db::name('user')->max('uid') + 1000;
    $password = 'ClosureV2!2026';

    $insertUser = function (int $uid, string $account, string $phone, array $extra = []) use ($now, $password): void {
        Db::name('user')->insert(array_merge([
            'uid' => $uid,
            'account' => $account,
            'pwd' => md5($password),
            'nickname' => 'Closure V2 TEST ' . $uid,
            'phone' => $phone,
            'status' => 1,
            'is_del' => 0,
            'user_type' => 'h5',
            'login_type' => 'h5',
            'uniqid' => substr(hash('sha256', 'closure-v2-' . $uid . '-' . microtime(true)), 0, 32),
            'add_time' => $now,
        ], $extra));
    };
    $payload = function (array $extra = []) use ($password): array {
        return array_merge([
            'confirmation' => '确认注销',
            'agreement' => true,
            'verification_type' => 'password',
            'password' => $password,
        ], $extra);
    };
    $codes = function (array $preflight): array {
        return array_values(array_map(function ($item) {
            return (string)($item['code'] ?? '');
        }, (array)($preflight['blockers'] ?? [])));
    };
    $insertOrder = function (int $uid, string $suffix, array $extra = []) use ($now): int {
        return (int)Db::name('store_order')->insertGetId(array_merge([
            'order_id' => 'CLOSEV2-' . $suffix,
            'uid' => $uid,
            'unique' => md5('close-v2-' . $suffix),
            'real_name' => '销户测试姓名',
            'user_phone' => '13900009999',
            'user_address' => '销户测试地址',
            'custom_form' => json_encode(['phone' => '13900009999', 'uid' => $uid], JSON_UNESCAPED_UNICODE),
            'total_num' => 1,
            'total_price' => 100,
            'pay_price' => 100,
            'paid' => 1,
            'pay_time' => $now,
            'status' => 3,
            'refund_status' => 0,
            'add_time' => $now,
        ], $extra));
    };
    $insertAttribution = function (int $uid, int $storeId, string $suffix) use ($now): int {
        return (int)Db::name('yfth_hq_customer_attribution_current')->insertGetId([
            'uid' => $uid,
            'store_id' => $storeId,
            'status' => 'bound',
            'status_reason_code' => 'closure_v2_test',
            'authority_version' => 1,
            'source_type' => 'closure_v2_test',
            'source_id' => $suffix,
            'bound_at' => $now,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    };
    $insertReferral = function (int $referrerUid, int $referredUid, int $storeId, int $attributionId, string $suffix) use ($now): int {
        return (int)Db::name('yfth_hq_active_referral_current')->insertGetId([
            'relation_no' => 'CLOSEV2-REL-' . $suffix,
            'referrer_uid' => $referrerUid,
            'referred_uid' => $referredUid,
            'store_id' => $storeId,
            'attribution_current_id' => $attributionId,
            'status' => 'active',
            'active_referred_uid' => $referredUid,
            'source_type' => 'closure_v2_test',
            'source_id' => $suffix,
            'source_unique_key' => hash('sha256', 'closure-v2-rel-' . $suffix),
            'started_at' => $now,
            'relation_version' => 1,
            'request_id' => 'closure-v2-rel-' . $suffix,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    };
    $subjectFor = function (string $closureNo): array {
        return (array)Db::name('yfth_account_closure_subject')->where('closure_no', $closureNo)->find();
    };

    // 1, 3, 4, 14-18: self closure, security checks, immediate token invalidation and fresh registration.
    $uidPlain = $base + 1;
    $phonePlain = '188' . str_pad((string)(($base + 1) % 100000000), 8, '0', STR_PAD_LEFT);
    $insertUser($uidPlain, $phonePlain, $phonePlain);
    Db::name('wechat_user')->insert([
        'uid' => $uidPlain,
        'openid' => 'close_v2_openid_' . $uidPlain,
        'unionid' => 'close_v2_union_' . $uidPlain,
        'nickname' => 'Closure Wechat',
        'user_type' => 'wechat',
        'add_time' => $now,
    ]);
    $loginResult = $login->login($phonePlain, $password, 0, 0);
    $token = (string)$loginResult['token'];
    $assert((int)$auth->parseToken($token)['user']['uid'] === $uidPlain, 'old_token_valid_before_closure');
    $expectFailure(function () use ($service, $uidPlain, $payload) {
        $service->closeForUser($uidPlain, $payload(['password' => 'wrong-password']));
    }, 'wrong_password_rejected');
    $expectFailure(function () use ($service, $uidPlain, $payload) {
        $service->closeForUser($uidPlain, $payload(['agreement' => false]));
    }, 'agreement_required');
    $expectFailure(function () use ($service, $uidPlain, $payload) {
        $service->closeForUser($uidPlain, $payload(['confirmation' => '删除账号']));
    }, 'exact_confirmation_required');
    $plainResult = $service->closeForUser($uidPlain, $payload(), $token);
    $assert($plainResult['closed'] === true, 'plain_self_closure_completed');
    $oldUser = (array)Db::name('user')->where('uid', $uidPlain)->find();
    $assert((int)$oldUser['status'] === 0 && (int)$oldUser['is_del'] === 1 && (string)$oldUser['phone'] === '', 'old_account_disabled_and_deidentified');
    $expectFailure(function () use ($auth, $token) {
        $auth->parseToken($token);
    }, 'all_old_sessions_fail_immediately_at_database_gate');
    $uidAccount = $base + 2;
    $account = 'cv2' . $uidAccount;
    $insertUser($uidAccount, $account, '');
    $accountResult = $service->closeForUser($uidAccount, $payload());
    $assert($accountResult['closed'] === true, 'custom_account_closure_completed');

    // 2: headquarters uses the same core rules and creates a strict audit record.
    $uidHq = $base + 3;
    $insertUser($uidHq, 'closure_hq_' . $uidHq, '');
    $expectFailure(function () use ($service, $uidHq, $hq) {
        $service->closeForHeadquarters($uidHq, ['confirmation' => '确认注销', 'reason' => '短'], 1, $hq);
    }, 'headquarters_reason_minimum_enforced');
    $hqResult = $service->closeForHeadquarters($uidHq, ['confirmation' => '确认注销', 'reason' => '隔离环境总部代办注销'], 1, $hq);
    $assert($hqResult['closed'] === true, 'headquarters_closure_completed');
    $assert((int)Db::name('yfth_audit_event')->where('object_id', $hqResult['closure_no'])->where('action', 'headquarters_close_v2')->count() === 1, 'headquarters_closure_audit_persisted');

    // 5-8: financial and operational blockers cannot be bypassed by self or headquarters.
    $blockerCases = [
        'unfinished_orders' => function (int $uid, string $suffix) use ($insertOrder) {
            $insertOrder($uid, $suffix, ['paid' => 0, 'pay_time' => 0, 'status' => 0]);
        },
        'refund_processing' => function (int $uid, string $suffix) use ($insertOrder) {
            $insertOrder($uid, $suffix, ['status' => 3, 'refund_status' => 1]);
        },
        'cash_balance' => function (int $uid): void {
            Db::name('user')->where('uid', $uid)->update(['now_money' => 1]);
        },
        'unsettled_rewards' => function (int $uid, string $suffix) use ($now): void {
            Db::name('yfth_direct_referral_reward_candidate')->insert([
                'candidate_no' => 'CLOSEV2-CAND-' . $suffix,
                'referrer_uid' => $uid,
                'referred_uid' => $uid + 50000,
                'store_id' => 1,
                'source_business_type' => 'closure_v2_test',
                'source_business_id' => $suffix,
                'source_unique_key' => hash('sha256', 'close-v2-candidate-' . $suffix),
                'actual_paid_amount_cent' => 10000,
                'ratio_bps' => 1000,
                'reward_amount_cent' => 1000,
                'status' => 'pending',
                'add_time' => $now,
                'update_time' => $now,
            ]);
        },
        'unfinished_fulfillment' => function (int $uid, string $suffix) use ($now): void {
            Db::name('yfth_service_appointment')->insert([
                'appointment_no' => 'CLOSEV2-APPT-' . $suffix,
                'uid' => $uid,
                'status' => 'confirmed',
                'idempotency_key' => 'closure-v2-appointment-' . $suffix,
                'request_id' => 'closure-v2-appointment-' . $suffix,
                'add_time' => $now,
                'update_time' => $now,
            ]);
            Db::name('yfth_benefit_fulfillment')->insert([
                'fulfillment_no' => 'CLOSEV2-FUL-' . $suffix,
                'uid' => $uid,
                'status' => 'shipped',
                'idempotency_key' => 'closure-v2-fulfillment-' . $suffix,
                'active_key' => 'closure-v2-fulfillment-' . $suffix,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        },
        'franchise_pending' => function (int $uid, string $suffix) use ($now): void {
            Db::name('yfth_franchise_application')->insert([
                'application_no' => 'CLOSEV2-APP-' . $suffix,
                'applicant_uid' => $uid,
                'name' => 'Closure Pending Applicant',
                'phone' => '18800001111',
                'city' => 'Test City',
                'status' => 'submitted',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        },
        'risk_frozen' => function (int $uid): void {
            Db::name('user')->where('uid', $uid)->update(['status' => 0]);
        },
    ];
    $offset = 10;
    foreach ($blockerCases as $expectedCode => $prepare) {
        $uid = $base + $offset++;
        $insertUser($uid, 'closure_block_' . $uid, '');
        $prepare($uid, (string)$uid);
        $preflight = $service->preflightForHeadquarters($uid, $hq);
        $assert(in_array($expectedCode, $codes($preflight), true), $expectedCode . '_reported');
        $expectFailure(function () use ($service, $uid, $hq) {
            $service->closeForHeadquarters($uid, ['confirmation' => '确认注销', 'reason' => '总部不得绕过业务门禁'], 1, $hq);
        }, $expectedCode . '_cannot_be_bypassed_by_headquarters');
        $assert((int)Db::name('user')->where('uid', $uid)->where('is_del', 0)->count() === 1, $expectedCode . '_leaves_account_intact');
    }

    // 9-10: manager/staff are revoked; active partner/franchisee responsibility blocks closure.
    $uidStaff = $base + 20;
    $insertUser($uidStaff, 'closure_staff_' . $uidStaff, '');
    foreach (['store_manager', 'store_staff'] as $index => $role) {
        Db::name('yfth_user_store_role')->insert([
            'uid' => $uidStaff,
            'store_id' => $index + 1,
            'role_code' => $role,
            'status' => 'active',
            'start_time' => $now,
            'active_key' => 'closure-v2-role-' . $uidStaff . '-' . $role,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }
    $staffResult = $service->closeForUser($uidStaff, $payload());
    $assert($staffResult['closed'] === true && (int)Db::name('yfth_user_store_role')->where('uid', $uidStaff)->count() === 0, 'manager_and_staff_roles_revoked');

    $uidPartner = $base + 21;
    $insertUser($uidPartner, 'closure_partner_' . $uidPartner, '');
    Db::name('yfth_partner_profile')->insert([
        'uid' => $uidPartner,
        'rank_code' => 'county_partner',
        'status' => 'active',
        'start_time' => $now,
        'active_key' => 'closure-v2-partner-' . $uidPartner,
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $assert(in_array('business_responsibility', $codes($service->preflightForHeadquarters($uidPartner, $hq)), true), 'partner_responsibility_blocks_closure');

    $uidFranchisee = $base + 22;
    $insertUser($uidFranchisee, 'closure_franchisee_' . $uidFranchisee, '');
    Db::name('yfth_user_store_role')->insert([
        'uid' => $uidFranchisee,
        'store_id' => 1,
        'role_code' => 'franchisee',
        'status' => 'active',
        'start_time' => $now,
        'active_key' => 'closure-v2-franchisee-' . $uidFranchisee,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $assert(in_array('business_responsibility', $codes($service->preflightForHeadquarters($uidFranchisee, $hq)), true), 'active_store_responsibility_blocks_closure');

    // 11: C1 closure keeps C2's B1 attribution but closes referral and prevents future C1 rewards.
    $uidC1 = $base + 30;
    $uidC2 = $base + 31;
    $insertUser($uidC1, 'closure_c1_' . $uidC1, '');
    $insertUser($uidC2, 'closure_c2_kept_' . $uidC2, '');
    $attrC2 = $insertAttribution($uidC2, 1, 'c1-keeps-b1-' . $uidC2);
    $insertReferral($uidC1, $uidC2, 1, $attrC2, 'c1-' . $uidC1);
    $c1Result = $service->closeForUser($uidC1, $payload());
    $c1Subject = $subjectFor($c1Result['closure_no']);
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $uidC2)->where('store_id', 1)->count() === 1, 'c1_closure_keeps_c2_b1_attribution');
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $uidC2)->count() === 0, 'c1_closure_removes_active_referral');
    $assert((int)Db::name('yfth_hq_active_referral_event')->where('referrer_uid', (int)$c1Subject['subject_uid'])->where('referred_uid', $uidC2)->count() >= 1, 'c1_referral_history_anonymized');

    // 12: C2 closure closes its active relation and current attribution.
    $uidReferrer = $base + 32;
    $uidClosingC2 = $base + 33;
    $insertUser($uidReferrer, 'closure_ref_' . $uidReferrer, '');
    $insertUser($uidClosingC2, 'closure_c2_' . $uidClosingC2, '');
    $attrClosingC2 = $insertAttribution($uidClosingC2, 1, 'c2-closes-' . $uidClosingC2);
    $insertReferral($uidReferrer, $uidClosingC2, 1, $attrClosingC2, 'c2-' . $uidClosingC2);
    $service->closeForUser($uidClosingC2, $payload());
    $assert((int)Db::name('yfth_hq_active_referral_current')->where('referred_uid', $uidClosingC2)->count() === 0, 'c2_closure_closes_referral');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('uid', $uidClosingC2)->count() === 0, 'c2_closure_removes_current_attribution');

    // 13: completed transaction facts remain but cannot identify or reattach to the old/new account.
    $uidHistory = $base + 40;
    $historyPhone = '187' . str_pad((string)(($base + 40) % 100000000), 8, '0', STR_PAD_LEFT);
    $insertUser($uidHistory, $historyPhone, $historyPhone, ['nickname' => 'A']);
    $orderId = $insertOrder($uidHistory, 'history-' . $uidHistory, ['status' => 3, 'paid' => 1]);
    $unrelatedSnapshot = json_encode(['product_name' => 'A', 'note' => 'A remains unrelated'], JSON_UNESCAPED_UNICODE);
    $unrelatedAuditId = (int)Db::name('yfth_audit_event')->insertGetId([
        'business_domain' => 'catalog',
        'object_type' => 'product',
        'object_id' => 'unrelated-' . $uidHistory,
        'action' => 'view',
        'before_state' => $unrelatedSnapshot,
        'after_state' => '{}',
        'operator_uid' => 0,
        'role_code' => '',
        'store_id' => 0,
        'request_id' => 'unrelated-short-name-' . $uidHistory,
        'reason' => 'A remains unrelated',
        'ip' => '',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $relatedAuditId = (int)Db::name('yfth_audit_event')->insertGetId([
        'business_domain' => 'yfth_user_account',
        'object_type' => 'user_profile',
        'object_id' => (string)$uidHistory,
        'action' => 'profile_update',
        'before_state' => json_encode(['uid' => $uidHistory, 'phone' => $historyPhone, 'nickname' => 'A'], JSON_UNESCAPED_UNICODE),
        'after_state' => json_encode(['user_id' => (string)$uidHistory, 'account' => $historyPhone], JSON_UNESCAPED_UNICODE),
        'operator_uid' => $uidHistory,
        'role_code' => 'customer_self',
        'store_id' => 0,
        'request_id' => 'related-user-audit-' . $uidHistory,
        'reason' => 'profile ' . $historyPhone,
        'ip' => '127.0.0.1',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $adminAuditState = json_encode(['note' => 'headquarters actor remains'], JSON_UNESCAPED_UNICODE);
    $adminAuditId = (int)Db::name('yfth_audit_event')->insertGetId([
        'business_domain' => 'system_config',
        'object_type' => 'configuration',
        'object_id' => 'admin-' . $uidHistory,
        'action' => 'review',
        'before_state' => $adminAuditState,
        'after_state' => '{}',
        'operator_uid' => $uidHistory,
        'role_code' => 'headquarters_admin',
        'store_id' => 0,
        'request_id' => 'admin-same-numeric-id-' . $uidHistory,
        'reason' => 'headquarters record',
        'ip' => '',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $idempotencyId = (int)Db::name('yfth_idempotency_record')->insertGetId([
        'business_domain' => 'yfth_user_account',
        'action_type' => 'profile_update',
        'idempotency_key' => 'closure-v2-user-history-' . $uidHistory,
        'object_id' => (string)$uidHistory,
        'request_hash' => hash('sha256', 'closure-v2-' . $uidHistory),
        'process_status' => 'completed',
        'result_summary' => json_encode(['uid' => $uidHistory, 'phone' => $historyPhone, 'nickname' => 'A'], JSON_UNESCAPED_UNICODE),
        'fail_reason' => 'account ' . $historyPhone,
        'finish_time' => $now,
        'expire_time' => $now + 86400,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $historyResult = $service->closeForUser($uidHistory, $payload());
    $historySubject = $subjectFor($historyResult['closure_no']);
    $order = (array)Db::name('store_order')->where('id', $orderId)->find();
    $assert((int)$order['uid'] === (int)$historySubject['subject_uid'], 'completed_order_preserved_under_anonymous_subject');
    $assert((string)$order['real_name'] === '' && (string)$order['user_phone'] === '' && (string)$order['user_address'] === '', 'completed_order_pii_removed');
    $assert(strpos((string)$order['custom_form'], $historyPhone) === false && strpos((string)$order['custom_form'], (string)$uidHistory) === false, 'completed_order_snapshot_redacted');
    $assert((int)Db::name('yfth_account_closure_history_link')->where('closure_no', $historyResult['closure_no'])->where('table_name', 'store_order')->count() === 1, 'anonymous_history_link_recorded');
    $unrelatedAudit = (array)Db::name('yfth_audit_event')->where('id', $unrelatedAuditId)->find();
    $assert((string)$unrelatedAudit['before_state'] === $unrelatedSnapshot && (string)$unrelatedAudit['reason'] === 'A remains unrelated', 'unrelated_short_nickname_text_not_modified');
    $relatedAudit = (array)Db::name('yfth_audit_event')->where('id', $relatedAuditId)->find();
    $relatedBefore = json_decode((string)$relatedAudit['before_state'], true);
    $relatedAfter = json_decode((string)$relatedAudit['after_state'], true);
    $assert((int)$relatedAudit['operator_uid'] === (int)$historySubject['subject_uid'] && (string)$relatedAudit['object_id'] === (string)$historySubject['subject_uid'], 'related_user_audit_subject_anonymized');
    $assert((int)$relatedBefore['uid'] === (int)$historySubject['subject_uid'] && $relatedBefore['phone'] === '[redacted]' && $relatedBefore['nickname'] === '[redacted]', 'related_user_audit_snapshot_pii_redacted');
    $assert((string)$relatedAfter['user_id'] === (string)$historySubject['subject_uid'] && $relatedAfter['account'] === '[redacted]' && strpos((string)$relatedAudit['reason'], $historyPhone) === false, 'related_user_audit_after_and_reason_redacted');
    $adminAudit = (array)Db::name('yfth_audit_event')->where('id', $adminAuditId)->find();
    $assert((int)$adminAudit['operator_uid'] === $uidHistory && (string)$adminAudit['before_state'] === $adminAuditState, 'headquarters_audit_actor_with_same_numeric_id_preserved');
    $idempotency = (array)Db::name('yfth_idempotency_record')->where('id', $idempotencyId)->find();
    $idempotencySummary = json_decode((string)$idempotency['result_summary'], true);
    $assert((string)$idempotency['object_id'] === (string)$historySubject['subject_uid'] && (int)$idempotencySummary['uid'] === (int)$historySubject['subject_uid'], 'user_idempotency_subject_anonymized');
    $assert($idempotencySummary['phone'] === '[redacted]' && $idempotencySummary['nickname'] === '[redacted]' && strpos((string)$idempotency['fail_reason'], $historyPhone) === false, 'user_idempotency_pii_redacted');
    $assert((int)Db::name('yfth_account_closure_history_link')->where('closure_no', $historyResult['closure_no'])->whereIn('table_name', ['yfth_audit_event', 'yfth_idempotency_record'])->count() === 2, 'detached_history_links_recorded_without_unrelated_rows');

    // Franchise opening children are linked by application_id and must not be missed or confuse admin IDs with user UIDs.
    $uidOpening = $base + 41;
    $insertUser($uidOpening, 'closure_opening_' . $uidOpening, '18600001234');
    $applicationId = (int)Db::name('yfth_franchise_application')->insertGetId([
        'application_no' => 'CLOSEV2-HISTORY-' . $uidOpening,
        'applicant_uid' => $uidOpening,
        'name' => 'Opening Applicant PII',
        'phone' => '18600001234',
        'city' => 'History City',
        'status' => 'closed',
        'assigned_uid' => $uidOpening,
        'remark' => 'private opening note',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $paymentId = (int)Db::name('yfth_franchise_payment_proof')->insertGetId([
        'application_id' => $applicationId,
        'amount_snapshot' => 5980,
        'attachment_ids' => '[101,102]',
        'status' => 'finance_confirmed',
        'finance_uid' => $uidOpening,
        'reject_reason' => 'private payment note',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $profileId = (int)Db::name('yfth_franchise_store_profile')->insertGetId([
        'application_id' => $applicationId,
        'store_name' => 'Private Store Name',
        'province' => 'Private Province',
        'city' => 'Private City',
        'district' => 'Private District',
        'address' => 'Private Store Address',
        'status' => 'verified',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $taskId = (int)Db::name('yfth_franchise_preparation_task')->insertGetId([
        'application_id' => $applicationId,
        'store_profile_id' => $profileId,
        'task_code' => 'closure_v2_history',
        'task_name' => 'Historical preparation',
        'status' => 'approved',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $applicantTaskRecordId = (int)Db::name('yfth_franchise_preparation_task_record')->insertGetId([
        'task_id' => $taskId,
        'application_id' => $applicationId,
        'operator_type' => 'applicant',
        'operator_uid' => $uidOpening,
        'action' => 'task_submit',
        'content' => 'private applicant evidence',
        'attachment_ids' => '[201]',
        'create_time' => $now,
    ]);
    $adminTaskRecordId = (int)Db::name('yfth_franchise_preparation_task_record')->insertGetId([
        'task_id' => $taskId,
        'application_id' => $applicationId,
        'operator_type' => 'headquarters',
        'operator_uid' => $uidOpening,
        'action' => 'task_approve',
        'content' => 'private review note',
        'attachment_ids' => '',
        'create_time' => $now,
    ]);
    $acceptanceId = (int)Db::name('yfth_store_opening_acceptance')->insertGetId([
        'application_id' => $applicationId,
        'store_profile_id' => $profileId,
        'status' => 'passed',
        'reviewer_uid' => $uidOpening,
        'reject_reason' => 'private acceptance note',
        'create_time' => $now,
        'update_time' => $now,
    ]);
    $acceptanceItemId = (int)Db::name('yfth_store_opening_acceptance_item')->insertGetId([
        'acceptance_id' => $acceptanceId,
        'item_code' => 'closure_v2_item',
        'item_name' => 'Historical item',
        'result' => 'pass',
        'evidence_attachment_ids' => '[301]',
        'reviewer_uid' => $uidOpening,
        'remark' => 'private item note',
    ]);
    $openingResult = $service->closeForUser($uidOpening, $payload());
    $openingSubject = $subjectFor($openingResult['closure_no']);
    $openingApplication = (array)Db::name('yfth_franchise_application')->where('id', $applicationId)->find();
    $assert((int)$openingApplication['applicant_uid'] === (int)$openingSubject['subject_uid'] && (string)$openingApplication['phone'] === '' && (string)$openingApplication['name'] === '', 'franchise_application_anonymized');
    $assert((int)$openingApplication['assigned_uid'] === $uidOpening, 'headquarters_assignee_id_not_confused_with_user_uid');
    $payment = (array)Db::name('yfth_franchise_payment_proof')->where('id', $paymentId)->find();
    $assert((string)$payment['attachment_ids'] === '' && (string)$payment['reject_reason'] === '' && (int)$payment['finance_uid'] === $uidOpening, 'payment_history_retained_without_private_evidence_or_admin_corruption');
    $profile = (array)Db::name('yfth_franchise_store_profile')->where('id', $profileId)->find();
    $assert((string)$profile['store_name'] === '' && (string)$profile['address'] === '', 'store_preparation_profile_deidentified');
    $applicantTaskRecord = (array)Db::name('yfth_franchise_preparation_task_record')->where('id', $applicantTaskRecordId)->find();
    $adminTaskRecord = (array)Db::name('yfth_franchise_preparation_task_record')->where('id', $adminTaskRecordId)->find();
    $assert((int)$applicantTaskRecord['operator_uid'] === (int)$openingSubject['subject_uid'] && (string)$applicantTaskRecord['content'] === '' && (string)$applicantTaskRecord['attachment_ids'] === '', 'applicant_task_evidence_anonymized');
    $assert((int)$adminTaskRecord['operator_uid'] === $uidOpening && (string)$adminTaskRecord['content'] === '', 'headquarters_task_operator_not_rewritten');
    $acceptance = (array)Db::name('yfth_store_opening_acceptance')->where('id', $acceptanceId)->find();
    $acceptanceItem = (array)Db::name('yfth_store_opening_acceptance_item')->where('id', $acceptanceItemId)->find();
    $assert((int)$acceptance['reviewer_uid'] === $uidOpening && (string)$acceptance['reject_reason'] === '', 'acceptance_reviewer_not_rewritten_and_note_removed');
    $assert((string)$acceptanceItem['evidence_attachment_ids'] === '' && (string)$acceptanceItem['remark'] === '', 'acceptance_item_evidence_removed');
    $assert((int)Db::name('yfth_account_closure_history_link')->where('closure_no', $openingResult['closure_no'])->where('table_name', 'yfth_franchise_payment_proof')->count() === 1, 'linked_franchise_history_registered');

    // 19: retry is idempotent and creates no second closure subject.
    $repeat = $service->closeForUser($uidHistory, $payload());
    $assert($repeat['already_closed'] === true && $repeat['closure_no'] === $historyResult['closure_no'], 'repeated_closure_is_idempotent');
    $assert((int)Db::name('yfth_account_closure_subject')->where('closure_no', $historyResult['closure_no'])->count() === 1, 'repeated_closure_creates_no_duplicate_subject');

    // 20: strict audit failure rolls back user, authority and closure subject changes atomically.
    $uidRollback = $base + 50;
    $insertUser($uidRollback, 'closure_rollback_' . $uidRollback, '');
    $rollbackAttrId = $insertAttribution($uidRollback, 1, 'rollback-' . $uidRollback);
    $trigger = 'trg_close_v2_audit_' . $uidRollback;
    Db::execute('CREATE TRIGGER `' . $trigger . '` BEFORE INSERT ON `eb_yfth_audit_event` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'closure_v2_forced_audit_failure\'');
    try {
        $expectFailure(function () use ($service, $uidRollback, $payload) {
            $service->closeForUser($uidRollback, $payload());
        }, 'forced_audit_failure_rejects_closure');
    } finally {
        Db::execute('DROP TRIGGER IF EXISTS `' . $trigger . '`');
    }
    $assert((int)Db::name('user')->where('uid', $uidRollback)->where('is_del', 0)->where('status', 1)->count() === 1, 'transaction_failure_keeps_user_active');
    $assert((int)Db::name('yfth_hq_customer_attribution_current')->where('id', $rollbackAttrId)->where('uid', $uidRollback)->count() === 1, 'transaction_failure_keeps_authority_current');
    $assert((int)Db::name('yfth_account_closure_subject')->where('former_uid_digest', hash_hmac('sha256', 'yfth-account-closure|' . $uidRollback, (string)env('app.app_key', 'default')))->count() === 0, 'transaction_failure_creates_no_closure_subject');

    // Registration is intentionally last so auto-increment UIDs cannot collide with fixture UIDs.
    $registeredByPhone = $login->register($phonePlain, $password, 0, 'h5');
    $newPhoneUid = (int)$registeredByPhone->uid;
    $assert($newPhoneUid !== $uidPlain, 'same_phone_registers_new_uid');
    Db::name('wechat_user')->insert([
        'uid' => $newPhoneUid,
        'openid' => 'close_v2_openid_' . $uidPlain,
        'unionid' => 'close_v2_union_' . $uidPlain,
        'nickname' => 'Closure Wechat Fresh',
        'user_type' => 'wechat',
        'add_time' => $now,
    ]);
    $assert((int)Db::name('wechat_user')->where('uid', $newPhoneUid)->count() === 1, 'wechat_identity_can_bind_fresh_uid');
    foreach (['yfth_permanent_membership', 'yfth_hq_customer_attribution_current', 'yfth_user_store_role'] as $table) {
        $assert((int)Db::name($table)->where('uid', $newPhoneUid)->count() === 0, 'fresh_uid_inherits_no_' . $table);
    }
    $assert((int)Db::name('store_order')->where('uid', $newPhoneUid)->count() === 0, 'fresh_uid_inherits_no_order');

    $freshAccount = $login->register($account, $password, 0, 'h5');
    $assert((int)$freshAccount->uid !== $uidAccount, 'same_account_registers_new_uid');
} catch (Throwable $e) {
    $failures[] = 'unexpected_exception:' . $e->getMessage() . '@' . $e->getFile() . ':' . $e->getLine();
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
echo "[OK] YFTH account closure V2 isolated real flow verified.\n";
