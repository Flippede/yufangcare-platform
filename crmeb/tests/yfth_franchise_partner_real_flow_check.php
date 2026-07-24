<?php

use app\services\yfth\FranchisePartnerServices;
use app\services\yfth\HqAcceptanceFixtureServices;
use app\Request;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

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

if ((string)getenv('YFTH_FRANCHISE_PARTNER_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_FRANCHISE_PARTNER_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $default = (string)Config::get('database.default');
    $database = (string)Config::get('database.connections.' . $default . '.database');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test|staging|v1)/i', $database), 'database_name_is_isolated:' . $database);
    if ($failures) {
        throw new RuntimeException('isolated_database_guard_failed');
    }

    $credentialFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth-partner-fixture-' . getmypid() . '.txt';
    @unlink($credentialFile);
    Config::set(['acceptance_fixture_enabled' => true, 'acceptance_account_file' => $credentialFile], 'yfth');
    $hq = ['id' => 1, 'level' => 0];
    $fixture = app()->make(HqAcceptanceFixtureServices::class)->generate([
        'reason' => 'isolated five-rank partner flow',
        'request_id' => 'partner-real-flow-fixture-' . getmypid(),
    ], 1, $hq);
    $storeId = (int)$fixture['store']['id'];
    $accounts = array_column($fixture['accounts'], null, 'fixture_role');
    $uids = [];
    foreach ($accounts as $role => $account) {
        $uids[$role] = (int)$account['uid'];
    }
    $rankUids = [
        'county_partner' => (int)$uids['franchisee'],
        'prefecture_partner' => (int)$uids['prefecture_partner'],
        'province_partner' => (int)$uids['province_partner'],
        'regional_director' => (int)$uids['regional_director'],
        'platform_director' => (int)$uids['platform_director'],
    ];

    $profiles = Db::name('yfth_partner_profile')->whereIn('uid', array_values($rankUids))->select()->toArray();
    $profileRanks = array_column($profiles, 'rank_code', 'uid');
    foreach ($rankUids as $rank => $uid) {
        $assert(($profileRanks[$uid] ?? '') === $rank, 'fixture_rank_ready:' . $rank);
    }
    $assert(count($profiles) === 5, 'five_real_partner_profiles');
    $assert((int)Db::name('yfth_partner_relation')->whereIn('partner_uid', array_values($rankUids))->where('status', 'active')->count() === 4, 'four_active_hierarchy_edges');
    $countyProfile = Db::name('yfth_partner_profile')->where('uid', $rankUids['county_partner'])->find();
    $assert((int)($countyProfile['legacy_franchisee_role_id'] ?? 0) === 0, 'county_partner_has_no_legacy_store_role');

    $rule = Db::name('yfth_partner_rule_version')->where('rule_no', 'YFTH-PARTNER-V1')->find();
    Db::transaction(function () use ($rule) {
        Db::name('yfth_partner_rule_version')->where('active_key', 'published')->where('id', '<>', (int)$rule['id'])->update([
            'status' => 'disabled', 'active_key' => null, 'update_time' => time(),
        ]);
        Db::name('yfth_partner_rule_version')->where('id', (int)$rule['id'])->update([
            'status' => 'published', 'active_key' => 'published', 'effective_time' => time(), 'update_time' => time(),
        ]);
    });
    $rule = Db::name('yfth_partner_rule_version')->where('id', (int)$rule['id'])->find();
    $rankRules = Db::name('yfth_partner_rank_rule')->where('rule_version_id', (int)$rule['id'])->order('rank_level asc')->select()->toArray();
    $rewardPerBottle = array_column($rankRules, 'reward_per_bottle', 'rank_code');
    $assert((string)$rule['order_amount'] === '89100.00' && (int)$rule['bottle_count'] === 440, 'default_opening_rule_89100_440');
    $assert($rewardPerBottle === [
        'county_partner' => '40.00',
        'prefecture_partner' => '17.00',
        'province_partner' => '10.00',
        'regional_director' => '8.00',
        'platform_director' => '5.00',
    ], 'default_rank_rewards_40_17_10_8_5');

    $seedUser = Db::name('user')->where('uid', (int)$uids['customer'])->find();
    unset($seedUser['uid']);
    $suffix = (string)getmypid();
    $seedUser['account'] = 'yfth_partner_applicant_' . $suffix;
    $seedUser['phone'] = '19888' . str_pad(substr($suffix, -6), 6, '0', STR_PAD_LEFT);
    $seedUser['nickname'] = 'TEST partner opening applicant';
    $seedUser['mark'] = '[YFTH-PARTNER-REAL-FLOW]';
    $seedUser['status'] = 1;
    $seedUser['is_del'] = 0;
    $seedUser['add_time'] = time();
    $seedUser['last_time'] = time();
    foreach (['openid', 'unionid', 'routine_openid', 'wx_profile', 'spread_open'] as $field) {
        if (array_key_exists($field, $seedUser)) {
            $seedUser[$field] = '';
        }
    }
    $applicantUid = (int)Db::name('user')->insertGetId($seedUser);
    $applicationNo = 'YFTH-PARTNER-TEST-' . $suffix;
    $applicationId = (int)Db::name('yfth_franchise_application')->insertGetId([
        'application_no' => $applicationNo,
        'applicant_uid' => $applicantUid,
        'name' => 'TEST partner applicant',
        'phone' => $seedUser['phone'],
        'city' => 'TEST City',
        'region' => 'TEST Region',
        'intention_area' => 'TEST Area',
        'budget' => '89100.00',
        'source' => 'partner_invite',
        'status' => 'approved',
        'assigned_uid' => 1,
        'create_time' => time(),
        'update_time' => time(),
    ]);

    $token = 'partner-real-flow-token-' . $suffix;
    Db::name('yfth_partner_invite')->where('active_key', 'partner:' . $rankUids['county_partner'])->update([
        'status' => 'replaced', 'invalidated_time' => time(), 'active_key' => null, 'update_time' => time(),
    ]);
    Db::name('yfth_partner_invite')->insert([
        'partner_uid' => $rankUids['county_partner'],
        'token_hash' => hash('sha256', $token),
        'code_tail' => substr($token, -12),
        'status' => 'active',
        'expire_time' => time() + 3600,
        'invalidated_time' => 0,
        'active_key' => 'partner:' . $rankUids['county_partner'],
        'create_time' => time(),
        'update_time' => time(),
    ]);
    $partner = app()->make(FranchisePartnerServices::class);
    $manualRankUids = [];
    $manualRanks = ['platform_director', 'regional_director', 'province_partner', 'prefecture_partner', 'county_partner'];
    $manualRankAccountCodes = ['pd', 'rd', 'pp', 'pf', 'cp'];
    foreach ($manualRanks as $index => $rankCode) {
        $manualUser = $seedUser;
        $manualUser['account'] = 'yfth_manual_' . $manualRankAccountCodes[$index] . '_' . $suffix;
        $manualUser['phone'] = '196' . str_pad((string)(((int)$suffix * 10 + $index) % 100000000), 8, '0', STR_PAD_LEFT);
        $manualUser['nickname'] = 'TEST manual ' . $rankCode;
        $manualRankUids[$rankCode] = (int)Db::name('user')->insertGetId($manualUser);
    }
    $metadata = $partner->adminGrantOptions('', '', $hq);
    $assert(count($metadata['rank_options']) === 5 && !$metadata['parent_required'], 'manual_grant_metadata_lists_five_ranks');
    $platformGrant = $partner->adminGrantPartner($manualRankUids['platform_director'], [
        'rank_code' => 'platform_director', 'parent_uid' => 0, 'reason' => 'isolated platform grant',
    ], 1, $hq);
    $assert((string)$platformGrant['partner']['rank_code'] === 'platform_director' && !$platformGrant['relation'], 'platform_director_granted_without_parent');
    $expect(function () use ($partner, $manualRankUids, $hq) {
        $partner->adminGrantPartner($manualRankUids['regional_director'], [
            'rank_code' => 'regional_director', 'parent_uid' => 0, 'reason' => 'missing parent must fail',
        ], 1, $hq);
    }, 'partner_parent_required', 'regional_director_requires_parent');
    $partner->adminGrantPartner($manualRankUids['regional_director'], [
        'rank_code' => 'regional_director', 'parent_uid' => $manualRankUids['platform_director'], 'reason' => 'isolated regional grant',
    ], 1, $hq);
    $expect(function () use ($partner, $manualRankUids, $hq) {
        $partner->adminGrantPartner($manualRankUids['province_partner'], [
            'rank_code' => 'province_partner', 'parent_uid' => $manualRankUids['platform_director'], 'reason' => 'wrong adjacent rank',
        ], 1, $hq);
    }, 'partner_parent_rank_invalid', 'province_partner_rejects_non_adjacent_parent');
    $partner->adminGrantPartner($manualRankUids['province_partner'], [
        'rank_code' => 'province_partner', 'parent_uid' => $manualRankUids['regional_director'], 'reason' => 'isolated province grant',
    ], 1, $hq);
    $partner->adminGrantPartner($manualRankUids['prefecture_partner'], [
        'rank_code' => 'prefecture_partner', 'parent_uid' => $manualRankUids['province_partner'], 'reason' => 'isolated prefecture grant',
    ], 1, $hq);
    $countyGrant = $partner->adminGrantPartner($manualRankUids['county_partner'], [
        'rank_code' => 'county_partner', 'parent_uid' => $manualRankUids['prefecture_partner'], 'reason' => 'isolated county grant',
    ], 1, $hq);
    $countyGrantAgain = $partner->adminGrantPartner($manualRankUids['county_partner'], [
        'rank_code' => 'county_partner', 'parent_uid' => $manualRankUids['prefecture_partner'], 'reason' => 'isolated county replay',
    ], 1, $hq);
    $assert(!$countyGrant['idempotent'] && $countyGrantAgain['idempotent'], 'duplicate_manual_grant_is_idempotent');
    $assert((int)Db::name('yfth_partner_relation')->whereIn('partner_uid', array_values($manualRankUids))->where('status', 'active')->count() === 4, 'manual_chain_has_one_relation_per_non_top_rank');
    $provinceOptions = $partner->adminGrantOptions('province_partner', 'manual', $hq);
    $assert($provinceOptions['required_parent_rank'] === 'regional_director'
        && in_array($manualRankUids['regional_director'], array_column($provinceOptions['parent_options'], 'uid'), true), 'parent_options_only_return_required_adjacent_rank');
    $expect(function () use ($partner, $manualRankUids, $hq) {
        $partner->adminGrantPartner($manualRankUids['county_partner'], [
            'rank_code' => 'prefecture_partner', 'parent_uid' => $manualRankUids['province_partner'], 'reason' => 'must not overwrite active rank',
        ], 1, $hq);
    }, 'partner_already_active', 'manual_grant_cannot_overwrite_active_rank');
    $expect(function () use ($partner, $manualRankUids, $hq) {
        $partner->adminChangeParent($manualRankUids['county_partner'], [
            'parent_uid' => $manualRankUids['platform_director'], 'reason' => 'wrong rank parent change',
        ], 1, $hq);
    }, 'partner_parent_rank_invalid', 'parent_change_rejects_non_adjacent_rank');
    $assert((int)Db::name('yfth_partner_rank_event')->whereIn('partner_uid', array_values($manualRankUids))->where('action', 'headquarters_grant')->count() === 5, 'manual_grants_write_rank_events');
    $expect(function () use ($partner, $manualRankUids, $hq) {
        $partner->adminRevokePartner($manualRankUids['platform_director'], [
            'confirmation' => '确认撤销合伙人', 'reason' => 'active child must be reassigned first',
        ], 1, $hq);
    }, 'partner_active_children_must_be_reassigned', 'partner_with_active_children_cannot_be_revoked');
    $countyRevoke = $partner->adminRevokePartner($manualRankUids['county_partner'], [
        'confirmation' => '确认撤销合伙人', 'reason' => 'isolated county revoke',
    ], 1, $hq);
    $countyRevokeAgain = $partner->adminRevokePartner($manualRankUids['county_partner'], [
        'confirmation' => '确认撤销合伙人', 'reason' => 'isolated county revoke replay',
    ], 1, $hq);
    $assert((string)$countyRevoke['partner']['status'] === 'exited' && !$countyRevoke['idempotent'] && $countyRevokeAgain['idempotent'], 'leaf_partner_revoke_is_idempotent');
    $assert((int)Db::name('yfth_partner_relation')->where('partner_uid', $manualRankUids['county_partner'])->where('status', 'active')->count() === 0, 'partner_revoke_closes_active_parent_relation');
    $assert((int)Db::name('yfth_partner_rank_event')->where('partner_uid', $manualRankUids['county_partner'])->where('action', 'headquarters_revoke')->count() === 1, 'partner_revoke_writes_rank_event');
    $captured = $partner->captureRecruitSource($applicationId, $applicantUid, $token);
    $capturedChain = json_decode((string)$captured['chain_snapshot'], true) ?: [];
    $assert((string)$captured['source_type'] === 'partner_invite' && (int)$captured['direct_partner_uid'] === $rankUids['county_partner'], 'partner_qr_captures_direct_source');
    $assert(array_column($capturedChain, 'rank_code') === array_keys($rankUids), 'partner_qr_captures_five_rank_chain');
    $frozen = Db::transaction(function () use ($partner, $applicationId) {
        return $partner->freezeRecruitSource($applicationId, 1);
    });
    $assert((string)$frozen['status'] === 'frozen' && (int)$frozen['frozen_time'] > 0, 'finance_confirmation_freezes_source');
    $expect(function () use ($partner, $applicationId, $hq) {
        $partner->adminCorrectSource($applicationId, ['direct_partner_uid' => 0, 'reason' => 'must fail after freeze'], 1, $hq);
    }, 'franchise_recruit_source_frozen', 'frozen_source_cannot_be_corrected');
    $expect(function () use ($partner, $rankUids, $hq) {
        $partner->adminChangeParent($rankUids['county_partner'], [
            'parent_uid' => $rankUids['county_partner'], 'reason' => 'self parent must fail',
        ], 1, $hq);
    }, 'partner_relation_cycle_forbidden', 'self_parent_rejected');
    $expect(function () use ($partner, $rankUids, $hq) {
        $partner->adminChangeParent($rankUids['platform_director'], [
            'parent_uid' => $rankUids['county_partner'], 'reason' => 'cycle must fail',
        ], 1, $hq);
    }, 'partner_relation_cycle_forbidden', 'hierarchy_cycle_rejected');

    $openingStore = Db::name('system_store')->where('id', $storeId)->find();
    unset($openingStore['id']);
    $openingStore['name'] = 'TEST opened store ' . $suffix;
    $openingStore['phone'] = '195' . str_pad(substr($suffix, -8), 8, '0', STR_PAD_LEFT);
    $openingStore['add_time'] = time();
    $openingStoreId = (int)Db::name('system_store')->insertGetId($openingStore);
    $legacyRoleId = 0;
    $assetFields = 'uid,now_money,integral,brokerage_price';
    $assetBefore = Db::name('user')->whereIn('uid', array_merge([$applicantUid], array_values($rankUids)))->field($assetFields)->order('uid asc')->select()->toArray();
    $application = Db::name('yfth_franchise_application')->where('id', $applicationId)->find();
    $opened = Db::transaction(function () use ($partner, $application, $openingStoreId, $legacyRoleId) {
        return $partner->finalizeOpeningInTransaction($application, $openingStoreId, $legacyRoleId, 1);
    });
    $assert((int)$opened['partner']['uid'] === $rankUids['county_partner'], 'opening_keeps_existing_county_partner_as_sponsor');
    $assert((int)Db::name('yfth_partner_profile')->where('uid', $applicantUid)->count() === 0, 'opening_does_not_turn_manager_applicant_into_partner');
    $assert((int)Db::name('yfth_partner_store_binding')->where([
        'partner_uid' => $rankUids['county_partner'], 'store_id' => $openingStoreId, 'status' => 'active',
    ])->count() === 1, 'opened_store_is_bound_to_recruiting_county_partner');
    $assert((int)Db::name('yfth_partner_opening_performance')->where('application_id', $applicationId)->count() === 1, 'one_opening_performance');
    $assert((int)Db::name('yfth_partner_reward_candidate')->where('application_id', $applicationId)->count() === 0,
        'opening_does_not_reintroduce_hierarchy_cash_candidates');
    $assert((int)Db::name('yfth_reward_event')->where('id', (int)$opened['reward_event_id'])
        ->where('event_type', 'partner_store_opened')->count() === 1, 'opening_enqueues_unified_partner_reward_event');

    Db::transaction(function () use ($partner, $application, $openingStoreId, $legacyRoleId) {
        $partner->finalizeOpeningInTransaction($application, $openingStoreId, $legacyRoleId, 1);
    });
    $assert((int)Db::name('yfth_partner_opening_performance')->where('application_id', $applicationId)->count() === 1, 'duplicate_opening_keeps_one_performance');
    $assert((int)Db::name('yfth_reward_event')->where('event_type', 'partner_store_opened')
        ->where('source_id', (string)$applicationId)->count() === 1, 'duplicate_opening_keeps_one_reward_event');

    if (!Request::hasMacro('uid')) {
        Request::macro('uid', function () {
            return (int)($this->yfthTestUid ?? 0);
        });
    }
    $promotionRequest = new Request();
    $promotionRequest->yfthTestUid = $rankUids['county_partner'];
    $promotion = $partner->applyPromotion($promotionRequest, ['reason' => 'TEST requirements achieved']);
    $promotionAgain = $partner->applyPromotion($promotionRequest, ['reason' => 'TEST replay']);
    $assert((string)$promotion['application']['status'] === 'pending'
        && (string)$promotion['application']['target_rank'] === 'prefecture_partner'
        && $promotionAgain['idempotent'] === true, 'promotion_application_is_recorded_and_idempotent');
    $promotionReview = $partner->adminReviewPromotion((int)$promotion['application']['id'], [
        'action' => 'approve', 'reason' => 'TEST headquarters approval',
    ], 1, $hq);
    $assert((string)$promotionReview['application']['status'] === 'approved'
        && (string)Db::name('yfth_partner_profile')->where('uid', $rankUids['county_partner'])->value('rank_code') === 'prefecture_partner',
        'headquarters_approval_changes_rank');

    $performanceBeforeRuleChange = Db::name('yfth_partner_opening_performance')->where('application_id', $applicationId)->find();
    $candidateSnapshotsBeforeRuleChange = Db::name('yfth_partner_reward_candidate')->where('application_id', $applicationId)
        ->field('id,rule_version_id,bottle_count,reward_per_bottle,amount,rank_code')->order('id asc')->select()->toArray();
    $draft = $partner->adminSaveRule([
        'reason' => 'verify historical snapshot immutability',
        'order_amount' => '99000.00',
        'bottle_count' => 500,
        'rank_rules' => ['county_partner' => ['reward_per_bottle' => '41.00']],
    ], 1, $hq);
    $partner->adminPublishRule((int)$draft['rule']['id'], ['reason' => 'publish isolated test rule'], 1, $hq);
    $performanceAfterRuleChange = Db::name('yfth_partner_opening_performance')->where('application_id', $applicationId)->find();
    $candidateSnapshotsAfterRuleChange = Db::name('yfth_partner_reward_candidate')->where('application_id', $applicationId)
        ->field('id,rule_version_id,bottle_count,reward_per_bottle,amount,rank_code')->order('id asc')->select()->toArray();
    $assert($performanceBeforeRuleChange['rule_version_id'] === $performanceAfterRuleChange['rule_version_id']
        && $performanceBeforeRuleChange['order_amount'] === $performanceAfterRuleChange['order_amount']
        && $performanceBeforeRuleChange['bottle_count'] === $performanceAfterRuleChange['bottle_count'], 'rule_change_does_not_rewrite_performance_snapshot');
    $assert($candidateSnapshotsBeforeRuleChange === $candidateSnapshotsAfterRuleChange, 'rule_change_does_not_rewrite_reward_snapshots');

    $partnerList = $partner->adminPartners(['keyword' => 'yfth_stg_partner', 'page' => 1, 'limit' => 20], $hq);
    $assert((int)$partnerList['count'] >= 4, 'admin_partner_keyword_search_works');
    $assetAfter = Db::name('user')->whereIn('uid', array_merge([$applicantUid], array_values($rankUids)))->field($assetFields)->order('uid asc')->select()->toArray();
    $assert($assetBefore === $assetAfter, 'partner_rewards_do_not_write_crmeb_assets');
    $assert((int)Db::name('yfth_audit_event')->where('business_domain', 'yfth_franchise_partner')->count() > 0, 'partner_actions_write_unified_audit');
    @unlink($credentialFile);
} catch (Throwable $e) {
    $failures[] = 'unexpected:' . $e->getMessage();
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
echo "[OK] YFTH franchise partner five-rank real flow verified.\n";
