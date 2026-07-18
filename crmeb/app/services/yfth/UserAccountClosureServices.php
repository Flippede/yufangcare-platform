<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use crmeb\services\CacheService;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;
use think\facade\Log;

/**
 * Formal account closure with explicit business-domain gates and anonymous history retention.
 * No schema-wide UID discovery is allowed here: every mutable or retained domain is named below.
 */
class UserAccountClosureServices
{
    private const CONFIRMATION_PHRASE = '确认注销';

    /** Runtime and personal data that has no retention value. */
    private const PERSONAL_DELETE_REFERENCES = [
        'wechat_user' => ['uid'],
        'user_address' => ['uid'],
        'user_cancel' => ['uid'],
        'user_friends' => ['uid', 'friends_uid'],
        'user_invoice' => ['uid'],
        'user_label_relation' => ['uid'],
        'user_level' => ['uid'],
        'user_notice' => ['uid'],
        'user_notice_see' => ['uid'],
        'user_search' => ['uid'],
        'user_sign' => ['uid'],
        'user_spread' => ['uid', 'spread_uid'],
        'user_visit' => ['uid'],
        'store_cart' => ['uid'],
        'store_coupon_issue_user' => ['uid'],
        'store_coupon_user' => ['uid'],
        'store_product_log' => ['uid'],
        'store_product_relation' => ['uid'],
        'store_product_reply' => ['uid'],
        'store_service' => ['uid'],
        'store_service_feedback' => ['uid'],
        'store_service_log' => ['uid', 'to_uid'],
        'store_service_record' => ['user_id', 'to_uid'],
        'store_visit' => ['uid'],
        'system_store_staff' => ['uid'],
        'yfth_customer_relation' => ['uid'],
        'yfth_direct_referral_invite' => ['owner_uid', 'accepted_uid'],
        'yfth_referral_code' => ['owner_uid'],
        'yfth_referral_attribution' => ['referrer_uid', 'referred_uid'],
        'yfth_store_acquisition_code' => ['issuer_uid'],
        'yfth_user_identity' => ['uid'],
        'yfth_user_store_role' => ['uid'],
        'yfth_permanent_membership' => ['uid'],
        'yfth_partner_invite_code' => ['partner_uid'],
    ];

    /**
     * Retained business facts. UID columns become the random closure subject; PII is removed.
     * json fields preserve product/amount facts while sensitive values are recursively redacted.
     */
    private const RETAINED_HISTORY = [
        'store_order' => ['domain' => 'mall_order', 'uids' => ['uid', 'pay_uid', 'spread_uid', 'spread_two_uid', 'gift_uid'], 'plain' => ['real_name', 'user_phone', 'user_address', 'mark', 'remark'], 'json' => ['custom_form']],
        'store_order_refund' => ['domain' => 'mall_refund', 'uids' => ['uid'], 'plain' => ['refund_phone', 'refund_explain', 'refund_reason', 'refuse_reason', 'remark'], 'json' => ['refund_img', 'cart_info']],
        'store_order_cart_info' => ['domain' => 'mall_order', 'uids' => ['uid'], 'plain' => [], 'json' => ['cart_info']],
        'other_order' => ['domain' => 'financial_order', 'uids' => ['uid'], 'plain' => ['real_name', 'user_phone', 'user_address', 'mark', 'remark'], 'json' => []],
        'user_bill' => ['domain' => 'financial_ledger', 'uids' => ['uid'], 'plain' => ['mark'], 'json' => []],
        'user_money' => ['domain' => 'financial_ledger', 'uids' => ['uid'], 'plain' => ['mark'], 'json' => []],
        'user_recharge' => ['domain' => 'financial_ledger', 'uids' => ['uid'], 'plain' => [], 'json' => []],
        'user_extract' => ['domain' => 'financial_ledger', 'uids' => ['uid'], 'plain' => ['real_name', 'bank_code', 'alipay_code', 'wechat'], 'json' => []],
        'yfth_permanent_membership_event' => ['domain' => 'membership', 'uids' => ['uid'], 'plain' => [], 'json' => []],
        'yfth_hq_customer_attribution_event' => ['domain' => 'attribution', 'uids' => ['uid'], 'plain' => ['reason'], 'json' => []],
        'yfth_hq_active_referral_event' => ['domain' => 'referral', 'uids' => ['referrer_uid', 'referred_uid'], 'plain' => ['reason'], 'json' => []],
        'yfth_direct_referral_reward_candidate' => ['domain' => 'reward', 'uids' => ['referrer_uid', 'referred_uid'], 'plain' => [], 'json' => ['rule_snapshot', 'referral_snapshot', 'business_snapshot']],
        'yfth_direct_referral_reward_settlement_ledger' => ['domain' => 'reward_settlement', 'uids' => ['referrer_uid', 'referred_uid'], 'plain' => ['remark'], 'json' => []],
        'yfth_reward_ledger' => ['domain' => 'reward', 'uids' => ['referrer_uid', 'referred_uid'], 'plain' => [], 'json' => ['condition_snapshot', 'payload_snapshot']],
        'yfth_partner_reward_candidate' => ['domain' => 'partner_reward', 'uids' => ['beneficiary_uid', 'source_partner_uid'], 'plain' => [], 'json' => ['rule_snapshot', 'source_snapshot']],
        'yfth_partner_reward_settlement' => ['domain' => 'partner_reward', 'uids' => ['beneficiary_uid'], 'plain' => ['reason', 'remark'], 'json' => ['evidence_snapshot']],
        'yfth_package_purchase' => ['domain' => 'package', 'uids' => ['uid'], 'plain' => ['manual_retry_reason', 'last_activation_error'], 'json' => ['route_snapshot', 'validation_snapshot']],
        'yfth_package_instance' => ['domain' => 'package', 'uids' => ['uid'], 'plain' => [], 'json' => ['rule_snapshot', 'store_snapshot']],
        'yfth_service_appointment' => ['domain' => 'service_appointment', 'uids' => ['uid'], 'plain' => ['user_note', 'cancel_reason', 'reject_reason'], 'json' => ['store_snapshot', 'service_snapshot', 'benefit_snapshot']],
        'yfth_service_writeoff_record' => ['domain' => 'service_writeoff', 'uids' => ['uid'], 'plain' => ['reason'], 'json' => ['snapshot']],
        'yfth_benefit_fulfillment' => ['domain' => 'benefit_fulfillment', 'uids' => ['uid'], 'plain' => ['recipient_name_masked', 'recipient_phone_masked', 'reason'], 'json' => ['address_snapshot', 'delivery_snapshot', 'pickup_store_snapshot', 'product_snapshot', 'benefit_snapshot']],
        'yfth_franchise_application' => ['domain' => 'franchise', 'uids' => ['applicant_uid'], 'plain' => ['applicant_name', 'name', 'phone', 'contact_name', 'contact_phone', 'remark'], 'json' => ['form_snapshot']],
        'yfth_franchise_contract' => ['domain' => 'franchise_contract', 'uids' => ['applicant_uid'], 'plain' => ['contact_name', 'contact_phone', 'remark', 'attachment_ids'], 'json' => ['contract_snapshot']],
        'yfth_franchise_identity_grant' => ['domain' => 'franchise_opening', 'uids' => ['target_uid'], 'plain' => ['reason'], 'json' => []],
        'yfth_partner_profile' => ['domain' => 'partner', 'uids' => ['uid'], 'plain' => [], 'json' => []],
        'yfth_partner_relation' => ['domain' => 'partner', 'uids' => ['partner_uid', 'parent_uid'], 'plain' => ['reason'], 'json' => []],
        'yfth_partner_rank_event' => ['domain' => 'partner', 'uids' => ['partner_uid'], 'plain' => ['reason'], 'json' => ['evidence_snapshot']],
        'yfth_franchise_recruit_source' => ['domain' => 'partner', 'uids' => ['partner_uid', 'applicant_uid'], 'plain' => [], 'json' => []],
        'yfth_partner_opening_performance' => ['domain' => 'partner', 'uids' => ['partner_uid', 'applicant_uid'], 'plain' => [], 'json' => ['performance_snapshot']],
        'yfth_partner_promotion_application' => ['domain' => 'partner', 'uids' => ['partner_uid'], 'plain' => ['reason', 'review_reason'], 'json' => ['evidence_snapshot']],
        'yfth_partner_warning' => ['domain' => 'partner', 'uids' => ['partner_uid'], 'plain' => ['reason'], 'json' => ['evidence_snapshot']],
    ];

    private $adminScope;
    private $audit;
    private $columns = [];

    public function __construct(AdminStoreContextServices $adminScope, AuditEventServices $audit)
    {
        $this->adminScope = $adminScope;
        $this->audit = $audit;
    }

    public function preflightForUser(int $uid): array
    {
        return $this->publicProjection($this->preflightData($uid));
    }

    public function preflightForHeadquarters(int $uid, array $adminInfo): array
    {
        $this->adminScope->assertHeadquarterScope($adminInfo);
        return $this->preflightData($uid);
    }

    public function closeForUser(int $uid, array $data, string $token = ''): array
    {
        return $this->close($uid, $data, 0, 'customer_self', false, $token);
    }

    public function closeForHeadquarters(int $uid, array $data, int $adminId, array $adminInfo): array
    {
        $this->adminScope->assertHeadquarterScope($adminInfo);
        return $this->close($uid, $data, $adminId, 'headquarters_admin', true, '');
    }

    private function close(int $uid, array $data, int $operatorId, string $operatorRole, bool $headquarters, string $token): array
    {
        $this->assertEnabled($headquarters);
        $existing = $this->closedSubject($uid);
        if ($existing) {
            return ['closed' => true, 'already_closed' => true, 'closure_no' => (string)$existing['closure_no']];
        }

        $confirmation = trim((string)($data['confirmation'] ?? ''));
        $reason = trim((string)($data['reason'] ?? ''));
        if (!hash_equals(self::CONFIRMATION_PHRASE, $confirmation)) {
            $this->fail('请输入“确认注销”四个字', $headquarters);
        }
        if ($headquarters && mb_strlen($reason) < 4) {
            $this->fail('总部代办销户必须填写不少于4个字的原因', true);
        }
        if (!$headquarters && empty($data['agreement'])) {
            $this->fail('请阅读并勾选账号注销协议', false);
        }

        $before = $this->preflightData($uid);
        if (!$before['can_close']) {
            $this->fail('账号仍有必须先处理的业务事项，请按预检提示完成后重试', $headquarters);
        }
        if (!$headquarters) {
            $this->verifySecurity($before['user'], $data);
        }

        $result = Db::transaction(function () use ($uid, $before, $headquarters, $operatorId, $operatorRole, $reason) {
            $user = (array)Db::name('user')->where('uid', $uid)->lock(true)->find();
            if (!$user || (int)($user['is_del'] ?? 0) !== 0 || (string)$user['uniqid'] !== (string)$before['user']['uniqid']) {
                $this->fail('销户目标已发生变化，请重新预检', $headquarters);
            }
            $fresh = $this->preflightData($uid, $user);
            if (!$fresh['can_close']) {
                $this->fail('销户预检结果已变化，请重新检查', $headquarters);
            }

            $closureNo = 'YFTH-CLOSE-' . strtoupper(bin2hex(random_bytes(12)));
            $subjectUid = $this->newAnonymousSubjectUid();
            $now = time();
            $closureId = (int)Db::name('yfth_account_closure_subject')->insertGetId([
                'closure_no' => $closureNo,
                'subject_uid' => $subjectUid,
                'former_uid_digest' => $this->uidDigest($uid),
                'source' => $operatorRole,
                'operator_id' => $operatorId,
                'operator_role' => $operatorRole,
                'status' => 'processing',
                'reason' => $headquarters ? mb_substr($reason, 0, 255) : '用户本人完成安全验证并确认注销',
                'statistics' => '{}',
                'add_time' => $now,
                'update_time' => $now,
            ]);

            $statistics = [];
            $sensitiveValues = $this->sensitiveValues($user);
            $this->closeAuthorityCurrents($uid, $operatorId, $operatorRole, $closureNo, $statistics);
            $this->deleteCustomerRuntime($uid, $statistics);
            $this->deletePersonalRuntime($uid, $statistics);
            $this->anonymizeFranchiseLinkedHistory($uid, $subjectUid, $closureId, $closureNo, $sensitiveValues, $statistics);

            foreach (self::RETAINED_HISTORY as $table => $definition) {
                $count = $this->anonymizeHistory($table, $definition, $uid, $subjectUid, $closureId, $closureNo, $sensitiveValues);
                if ($count > 0) {
                    $statistics['retained.' . $table] = $count;
                }
            }
            $this->scrubDetachedSnapshots($uid, $subjectUid, $closureId, $closureNo, $sensitiveValues, $statistics);
            $this->anonymizeUser($uid, $closureNo, $statistics);

            Db::name('yfth_account_closure_subject')->where('id', $closureId)->update([
                'status' => 'closed',
                'statistics' => json_encode($statistics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => time(),
            ]);

            // Deliberately strict: any audit failure rolls the entire closure transaction back.
            $this->audit->record(
                'yfth_user_account_closure',
                'anonymous_subject',
                $closureNo,
                $headquarters ? 'headquarters_close_v2' : 'self_close_v2',
                ['preflight_passed' => true],
                ['closed' => true, 'subject_uid' => (string)$subjectUid, 'statistics' => $statistics],
                $headquarters ? $operatorId : 0,
                $operatorRole,
                0,
                $headquarters ? $reason : '用户本人完成安全验证并确认注销',
                $closureNo
            );

            return ['closed' => true, 'already_closed' => false, 'closure_no' => $closureNo, 'subject_uid' => $subjectUid];
        });

        if (!$headquarters && ($data['verification_type'] ?? '') === 'sms') {
            CacheService::delete('code_' . (string)($before['user']['phone'] ?? ''));
        }
        $this->revokeSessions($uid, $token);
        unset($result['subject_uid']);
        return $result;
    }

    private function preflightData(int $uid, array $lockedUser = []): array
    {
        $user = $lockedUser ?: $this->user($uid);
        $blockers = $this->businessBlockers($uid, $user);
        $forfeitures = $this->forfeitures($uid, $user);
        $passwordRequired = $this->hasReliablePassword($user);
        $verificationMethods = $passwordRequired ? ['password'] : [];
        if ($this->validPhone((string)($user['phone'] ?? ''))) {
            $verificationMethods[] = 'sms';
        }
        return [
            'enabled' => $this->enabled(),
            'uid' => $uid,
            'account' => (string)($user['account'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            'can_close' => $this->enabled() && !$blockers && (bool)$verificationMethods,
            'confirmation_phrase' => self::CONFIRMATION_PHRASE,
            'agreement_required' => true,
            'password_required' => $passwordRequired,
            'verification_methods' => array_values(array_unique($verificationMethods)),
            'blockers' => $blockers,
            'blocking_categories' => array_values(array_map(function ($item) {
                return (string)$item['label'];
            }, $blockers)),
            'forfeitures' => $forfeitures,
            'processing_policy' => [
                'delete' => ['登录身份与会话', '个人资料与微信绑定', '地址收藏购物车等个人数据', '当前会员归属推荐与经营角色'],
                'anonymize' => ['订单支付退款与售后', '套餐权益预约配送与核销', '奖励结算与加盟开店历史', '审计与权威事件'],
            ],
            'safety_note' => $blockers
                ? '存在必须先处理的订单、资金、履约、合同或经营责任，当前不能销户'
                : '必要交易与财务历史将使用随机销户主体匿名保存；重新注册是全新账号且不继承任何历史',
            'user' => $user,
        ];
    }

    private function publicProjection(array $preflight): array
    {
        unset($preflight['uid'], $preflight['account'], $preflight['nickname'], $preflight['user'], $preflight['processing_policy']);
        return $preflight;
    }

    private function businessBlockers(int $uid, array $user): array
    {
        $blockers = [];
        $this->appendBlocker($blockers, 'unfinished_orders', '存在待付款、待发货、待收货或售后中的订单', $this->unfinishedOrderCount($uid));
        $this->appendBlocker($blockers, 'refund_processing', '存在退款处理中订单', $this->countWhereIn('store_order', 'uid', $uid, 'refund_status', [1, 4]));

        $balance = (float)($user['now_money'] ?? 0) + (float)($user['brokerage_price'] ?? 0);
        $this->appendBlocker($blockers, 'cash_balance', '商城余额或可提现金额不为零', abs($balance) > 0.00001 ? 1 : 0);

        $rewardCount = $this->countWhereIn('yfth_direct_referral_reward_candidate', 'referrer_uid', $uid, 'status', ['pending', 'confirmed', 'disputed'])
            + $this->countWhereIn('yfth_partner_reward_candidate', 'beneficiary_uid', $uid, 'status', ['pending', 'confirmed', 'disputed'])
            + $this->countWhereNotIn('yfth_reward_ledger', 'referrer_uid', $uid, 'status', ['settled', 'cancelled', 'reversed']);
        $this->appendBlocker($blockers, 'unsettled_rewards', '存在未结算、争议中或待处理奖励', $rewardCount);

        $appointmentCount = $this->countWhereIn('yfth_service_appointment', 'uid', $uid, 'status', ['pending_confirm', 'confirmed', 'signed_in']);
        $fulfillmentCount = $this->countWhereNotIn('yfth_benefit_fulfillment', 'uid', $uid, 'status', ['completed', 'cancelled', 'rejected']);
        $this->appendBlocker($blockers, 'unfinished_fulfillment', '存在未完成预约、套餐权益或配送履约', $appointmentCount + $fulfillmentCount);

        $partnerCount = $this->countWhereIn('yfth_partner_profile', 'uid', $uid, 'status', ['active', 'paused'])
            + $this->activePartnerRelationCount($uid)
            + $this->activeStoreResponsibilityCount($uid)
            + $this->countWhereIn('yfth_franchise_identity_grant', 'target_uid', $uid, 'status', ['active', 'pending']);
        $this->appendBlocker($blockers, 'business_responsibility', '仍是招商合伙人或正式门店负责人，请先完成责任转移或终止', $partnerCount);

        $franchiseCount = $this->countWhereNotIn('yfth_franchise_application', 'applicant_uid', $uid, 'status', ['rejected', 'withdrawn', 'closed', 'completed', 'opened'])
            + $this->countWhereNotIn('yfth_franchise_contract', 'applicant_uid', $uid, 'status', ['cancelled', 'terminated', 'completed', 'signed']);
        $this->appendBlocker($blockers, 'franchise_pending', '存在待处理加盟合同、付款、财务或开店事项', $franchiseCount);

        if ((int)($user['status'] ?? 1) === 0 && (int)($user['is_del'] ?? 0) === 0) {
            $this->appendBlocker($blockers, 'risk_frozen', '账号处于停用、争议、审计或风险状态，请先由总部处理', 1);
        }
        return $blockers;
    }

    private function unfinishedOrderCount(int $uid): int
    {
        if (!$this->tableExists('store_order') || !$this->hasColumn('store_order', 'uid')) {
            return 0;
        }
        $query = Db::name('store_order')->where('uid', $uid);
        if ($this->hasColumn('store_order', 'is_del')) {
            $query->where('is_del', 0);
        }
        if ($this->hasColumn('store_order', 'is_system_del')) {
            $query->where('is_system_del', 0);
        }
        $query->where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('paid', 0);
                if ($this->hasColumn('store_order', 'is_cancel')) {
                    $sub->where('is_cancel', 0);
                }
            })->whereOr('status', 'in', [0, 1, 4]);
        });
        return (int)$query->count();
    }

    private function forfeitures(int $uid, array $user): array
    {
        $items = [];
        $integral = (float)($user['integral'] ?? 0);
        if ($integral > 0) {
            $items[] = ['code' => 'integral', 'label' => '商城积分', 'amount' => $integral];
        }
        $couponCount = $this->countWhereIn('store_coupon_user', 'uid', $uid, 'status', [0]);
        if ($couponCount > 0) {
            $items[] = ['code' => 'coupon', 'label' => '未使用优惠券', 'amount' => $couponCount];
        }
        return $items;
    }

    private function closeAuthorityCurrents(int $uid, int $operatorId, string $operatorRole, string $closureNo, array &$statistics): void
    {
        $now = time();
        if ($this->tableExists('yfth_hq_active_referral_current')) {
            $rows = Db::name('yfth_hq_active_referral_current')
                ->where(function ($q) use ($uid) {
                    $q->where('referrer_uid', $uid)->whereOr('referred_uid', $uid);
                })->lock(true)->select()->toArray();
            foreach ($rows as $row) {
                $version = (int)$row['relation_version'] + 1;
                if (in_array((string)$row['status'], ['active', 'paused'], true)) {
                    Db::name('yfth_hq_active_referral_current')->where('id', $row['id'])->update([
                        'status' => 'closed', 'active_referred_uid' => null, 'closed_at' => $now,
                        'close_reason' => 'account_closed', 'relation_version' => $version,
                        'request_id' => $closureNo, 'update_time' => $now,
                    ]);
                    if ($this->tableExists('yfth_hq_active_referral_event')) {
                        Db::name('yfth_hq_active_referral_event')->insert([
                            'event_no' => 'HRE-' . strtoupper(bin2hex(random_bytes(8))),
                            'referral_current_id' => (int)$row['id'], 'relation_no' => (string)$row['relation_no'],
                            'relation_version' => $version, 'referrer_uid' => (int)$row['referrer_uid'],
                            'referred_uid' => (int)$row['referred_uid'], 'store_id' => (int)$row['store_id'],
                            'event_type' => 'relation_closed', 'before_status' => (string)$row['status'], 'after_status' => 'closed',
                            'source_type' => 'account_closure', 'source_id' => $closureNo,
                            'source_unique_key' => hash('sha256', 'referral-close|' . $closureNo . '|' . $row['id']),
                            'operator_uid' => $operatorId, 'operator_role_code' => $operatorRole,
                            'reason' => 'account_closed', 'request_id' => $closureNo, 'add_time' => $now,
                        ]);
                    }
                }
                Db::name('yfth_hq_active_referral_current')->where('id', $row['id'])->delete();
                $statistics['closed_referral_current'] = ($statistics['closed_referral_current'] ?? 0) + 1;
            }
        }

        if ($this->tableExists('yfth_hq_customer_attribution_current')) {
            $row = (array)Db::name('yfth_hq_customer_attribution_current')->where('uid', $uid)->lock(true)->find();
            if ($row) {
                $version = (int)$row['authority_version'] + 1;
                if (in_array((string)$row['status'], ['active', 'paused', 'bound'], true) && $this->tableExists('yfth_hq_customer_attribution_event')) {
                    Db::name('yfth_hq_customer_attribution_event')->insert([
                        'event_no' => 'HAE-' . strtoupper(bin2hex(random_bytes(8))),
                        'attribution_current_id' => (int)$row['id'], 'uid' => $uid, 'authority_version' => $version,
                        'event_type' => 'attribution_closed', 'before_store_id' => (int)$row['store_id'], 'after_store_id' => 0,
                        'before_status' => (string)$row['status'], 'after_status' => 'closed',
                        'before_status_reason_code' => (string)$row['status_reason_code'], 'after_status_reason_code' => 'account_closed',
                        'source_type' => 'account_closure', 'source_id' => $closureNo,
                        'source_unique_key' => hash('sha256', 'attribution-close|' . $closureNo),
                        'operator_uid' => $operatorId, 'operator_role_code' => $operatorRole,
                        'reason' => 'account_closed', 'request_id' => $closureNo, 'add_time' => $now,
                    ]);
                }
                Db::name('yfth_hq_customer_attribution_current')->where('id', $row['id'])->delete();
                $statistics['closed_attribution_current'] = 1;
            }
        }
    }

    private function deleteCustomerRuntime(int $uid, array &$statistics): void
    {
        if ($this->tableExists('yfth_customer_relation')) {
            $ids = Db::name('yfth_customer_relation')->where('uid', $uid)->column('id');
            if ($ids && $this->tableExists('yfth_customer_follow_record') && $this->hasColumn('yfth_customer_follow_record', 'customer_relation_id')) {
                $statistics['deleted.yfth_customer_follow_record'] = (int)Db::name('yfth_customer_follow_record')->whereIn('customer_relation_id', $ids)->delete();
            }
        }
    }

    private function deletePersonalRuntime(int $uid, array &$statistics): void
    {
        if ($this->tableExists('user') && $this->hasColumn('user', 'spread_uid')) {
            $update = ['spread_uid' => 0];
            if ($this->hasColumn('user', 'spread_time')) {
                $update['spread_time'] = 0;
            }
            $statistics['detached.user_spread_uid'] = (int)Db::name('user')->where('spread_uid', $uid)->update($update);
        }
        foreach (self::PERSONAL_DELETE_REFERENCES as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $usable = array_values(array_filter($columns, function ($column) use ($table) {
                return $this->hasColumn($table, $column);
            }));
            if (!$usable) {
                continue;
            }
            $query = Db::name($table)->where(function ($q) use ($usable, $uid) {
                foreach ($usable as $index => $column) {
                    $index === 0 ? $q->where($column, $uid) : $q->whereOr($column, $uid);
                }
            });
            $count = (int)$query->delete();
            if ($count > 0) {
                $statistics['deleted.' . $table] = $count;
            }
        }
    }

    private function anonymizeHistory(string $table, array $definition, int $uid, int $subjectUid, int $closureId, string $closureNo, array $sensitiveValues): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $uidColumns = array_values(array_filter($definition['uids'], function ($column) use ($table) {
            return $this->hasColumn($table, $column);
        }));
        if (!$uidColumns) {
            return 0;
        }
        $query = Db::name($table)->where(function ($q) use ($uidColumns, $uid) {
            foreach ($uidColumns as $index => $column) {
                $index === 0 ? $q->where($column, $uid) : $q->whereOr($column, $uid);
            }
        });
        $rows = $query->select()->toArray();
        if (!$rows) {
            return 0;
        }

        foreach ($rows as $row) {
            $update = [];
            foreach ($uidColumns as $column) {
                if ((int)($row[$column] ?? 0) === $uid) {
                    $update[$column] = $subjectUid;
                }
            }
            foreach ($definition['plain'] as $column) {
                if ($this->hasColumn($table, $column) && array_key_exists($column, $row)) {
                    $update[$column] = '';
                }
            }
            foreach ($definition['json'] as $column) {
                if ($this->hasColumn($table, $column) && array_key_exists($column, $row) && (string)$row[$column] !== '') {
                    $update[$column] = $this->redactText((string)$row[$column], $uid, $subjectUid, $sensitiveValues);
                }
            }
            $recordId = $this->hasColumn($table, 'id') ? (string)($row['id'] ?? '') : '';
            if ($recordId !== '') {
                Db::name($table)->where('id', $recordId)->update($update);
            } else {
                foreach ($uidColumns as $column) {
                    if ((int)($row[$column] ?? 0) === $uid) {
                        Db::name($table)->where($column, $uid)->update($update);
                        break;
                    }
                }
            }
            foreach (array_keys($update) as $field) {
                if (in_array($field, $uidColumns, true)) {
                    Db::name('yfth_account_closure_history_link')->insert([
                        'closure_id' => $closureId, 'closure_no' => $closureNo,
                        'business_domain' => (string)$definition['domain'], 'table_name' => $table,
                        'record_id' => $recordId, 'relation_field' => $field, 'add_time' => time(),
                    ]);
                }
            }
        }
        return count($rows);
    }

    /**
     * Opening child tables identify the applicant through application_id rather than a user column.
     * They must be handled from the locked applicant relation; matching admin/operator IDs by number
     * would corrupt unrelated headquarters history when an admin ID happens to equal a user UID.
     */
    private function anonymizeFranchiseLinkedHistory(int $uid, int $subjectUid, int $closureId, string $closureNo, array $sensitiveValues, array &$statistics): void
    {
        if (!$this->tableExists('yfth_franchise_application') || !$this->hasColumn('yfth_franchise_application', 'applicant_uid')) {
            return;
        }
        $applicationIds = array_values(array_map('intval', Db::name('yfth_franchise_application')->where('applicant_uid', $uid)->column('id')));
        if (!$applicationIds) {
            return;
        }

        $definitions = [
            'yfth_franchise_payment_proof' => ['plain' => ['attachment_ids', 'reject_reason'], 'json' => []],
            'yfth_franchise_store_profile' => ['plain' => ['store_name', 'province', 'city', 'district', 'address'], 'json' => []],
            'yfth_franchise_preparation_task' => ['plain' => ['reject_reason'], 'json' => []],
            'yfth_franchise_preparation_task_record' => ['plain' => ['content', 'attachment_ids'], 'json' => []],
            'yfth_store_opening_acceptance' => ['plain' => ['reject_reason'], 'json' => []],
        ];
        foreach ($definitions as $table => $definition) {
            $count = $this->anonymizeLinkedRows(
                $table,
                'application_id',
                $applicationIds,
                $definition,
                $uid,
                $subjectUid,
                $closureId,
                $closureNo,
                $sensitiveValues
            );
            if ($count > 0) {
                $statistics['retained.' . $table] = $count;
            }
        }

        if ($this->tableExists('yfth_store_opening_acceptance')) {
            $acceptanceIds = array_values(array_map('intval', Db::name('yfth_store_opening_acceptance')->whereIn('application_id', $applicationIds)->column('id')));
            if ($acceptanceIds) {
                $count = $this->anonymizeLinkedRows(
                    'yfth_store_opening_acceptance_item',
                    'acceptance_id',
                    $acceptanceIds,
                    ['plain' => ['evidence_attachment_ids', 'remark'], 'json' => []],
                    $uid,
                    $subjectUid,
                    $closureId,
                    $closureNo,
                    $sensitiveValues
                );
                if ($count > 0) {
                    $statistics['retained.yfth_store_opening_acceptance_item'] = $count;
                }
            }
        }
    }

    private function anonymizeLinkedRows(string $table, string $relationField, array $relationIds, array $definition, int $uid, int $subjectUid, int $closureId, string $closureNo, array $sensitiveValues): int
    {
        if (!$this->tableExists($table) || !$this->hasColumn($table, 'id') || !$this->hasColumn($table, $relationField)) {
            return 0;
        }
        $rows = Db::name($table)->whereIn($relationField, $relationIds)->select()->toArray();
        foreach ($rows as $row) {
            $update = [];
            foreach ((array)($definition['plain'] ?? []) as $field) {
                if ($this->hasColumn($table, $field) && array_key_exists($field, $row)) {
                    $update[$field] = '';
                }
            }
            foreach ((array)($definition['json'] ?? []) as $field) {
                if ($this->hasColumn($table, $field) && array_key_exists($field, $row) && (string)$row[$field] !== '') {
                    $update[$field] = $this->redactText((string)$row[$field], $uid, $subjectUid, $sensitiveValues);
                }
            }
            if ($table === 'yfth_franchise_preparation_task_record'
                && (string)($row['operator_type'] ?? '') === 'applicant'
                && (int)($row['operator_uid'] ?? 0) === $uid
                && $this->hasColumn($table, 'operator_uid')) {
                $update['operator_uid'] = $subjectUid;
            }
            if ($update) {
                Db::name($table)->where('id', (int)$row['id'])->update($update);
            }
            Db::name('yfth_account_closure_history_link')->insert([
                'closure_id' => $closureId,
                'closure_no' => $closureNo,
                'business_domain' => 'franchise_opening',
                'table_name' => $table,
                'record_id' => (string)$row['id'],
                'relation_field' => $relationField,
                'add_time' => time(),
            ]);
        }
        return count($rows);
    }

    private function scrubDetachedSnapshots(int $uid, int $subjectUid, int $closureId, string $closureNo, array $sensitiveValues, array &$statistics): void
    {
        foreach ([
            'yfth_idempotency_record' => ['result_summary', 'request_hash', 'fail_reason'],
            'yfth_audit_event' => ['before_state', 'after_state', 'reason'],
        ] as $table => $fields) {
            $usable = array_values(array_filter($fields, function ($field) use ($table) {
                return $this->hasColumn($table, $field);
            }));
            if (!$this->tableExists($table) || !$this->hasColumn($table, 'id') || !$usable) {
                continue;
            }

            // Audit and idempotency tables can be very large. Resolve a bounded
            // candidate set through indexed relations and exact PII needles first;
            // the structured redactor below is the final authority on each change.
            $rows = $this->detachedSnapshotCandidates($table, $usable, $uid, $sensitiveValues);
            $changed = 0;
            foreach ($rows as $row) {
                $update = [];
                foreach ($usable as $field) {
                    $value = (string)($row[$field] ?? '');
                    $redacted = $this->redactText($value, $uid, $subjectUid, $sensitiveValues);
                    if ($redacted !== $value) {
                        $update[$field] = $redacted;
                    }
                }

                if ($table === 'yfth_audit_event') {
                    $roleCode = strtolower(trim((string)($row['role_code'] ?? '')));
                    if ((int)($row['operator_uid'] ?? 0) === $uid && $this->isUserAuditActorRole($roleCode)) {
                        $update['operator_uid'] = $subjectUid;
                    }
                    if ((string)($row['object_id'] ?? '') === (string)$uid
                        && $this->isUserAuditObjectType((string)($row['object_type'] ?? ''))) {
                        $update['object_id'] = (string)$subjectUid;
                    }
                }
                if ($table === 'yfth_idempotency_record'
                    && (string)($row['object_id'] ?? '') === (string)$uid
                    && $this->isUserIdempotencyOperation((string)($row['business_domain'] ?? ''), (string)($row['action_type'] ?? ''))) {
                    $update['object_id'] = (string)$subjectUid;
                }

                if (!$update) {
                    continue;
                }
                Db::name($table)->where('id', $row['id'])->update($update);
                Db::name('yfth_account_closure_history_link')->insert([
                    'closure_id' => $closureId,
                    'closure_no' => $closureNo,
                    'business_domain' => $table === 'yfth_audit_event' ? 'audit' : 'idempotency',
                    'table_name' => $table,
                    'record_id' => (string)$row['id'],
                    'relation_field' => implode(',', array_keys($update)),
                    'add_time' => time(),
                ]);
                $changed++;
            }
            if ($changed) {
                $statistics['scrubbed.' . $table] = $changed;
            }
        }
    }

    private function detachedSnapshotCandidates(string $table, array $fields, int $uid, array $sensitiveValues): array
    {
        $ids = [];
        $appendIds = function (array $candidateIds) use (&$ids): void {
            foreach ($candidateIds as $id) {
                $ids[(string)$id] = $id;
            }
        };

        if ($this->hasColumn($table, 'object_id')) {
            $appendIds(Db::name($table)->where('object_id', (string)$uid)->column('id'));
        }
        if ($table === 'yfth_audit_event' && $this->hasColumn($table, 'operator_uid')) {
            $appendIds(Db::name($table)->where('operator_uid', $uid)->column('id'));
        }

        foreach ($fields as $field) {
            foreach ($this->snapshotSearchNeedles($uid, $sensitiveValues) as $needle) {
                $appendIds(Db::name($table)->whereLike($field, '%' . $needle . '%')->column('id'));
            }
        }
        if (!$ids) {
            return [];
        }

        $select = array_merge(['id'], $fields);
        foreach (['object_id', 'object_type', 'operator_uid', 'role_code', 'business_domain', 'action_type'] as $field) {
            if ($this->hasColumn($table, $field)) {
                $select[] = $field;
            }
        }
        return Db::name($table)->whereIn('id', array_values($ids))->field(array_values(array_unique($select)))->select()->toArray();
    }

    private function snapshotSearchNeedles(int $uid, array $sensitiveValues): array
    {
        $uidText = (string)$uid;
        $needles = [
            '"uid":' . $uidText,
            '"uid": ' . $uidText,
            '"uid":"' . $uidText . '"',
            '"user_id":' . $uidText,
            '"user_id": ' . $uidText,
            '"user_id":"' . $uidText . '"',
            '_uid":' . $uidText,
            '_uid": ' . $uidText,
            '_uid":"' . $uidText . '"',
            'uid=' . $uidText,
            'uid = ' . $uidText,
        ];
        foreach ($sensitiveValues as $sensitive) {
            if ($this->isSearchableSensitive((string)$sensitive)) {
                $needles[] = (string)$sensitive;
            }
        }
        return array_values(array_unique($needles));
    }

    private function anonymizeUser(int $uid, string $closureNo, array &$statistics): void
    {
        $values = [
            'account' => 'closed_' . strtolower(substr(hash('sha256', $closureNo), 0, 24)),
            'pwd' => md5(bin2hex(random_bytes(24))), 'real_name' => '', 'birthday' => 0,
            'card_id' => '', 'mark' => '', 'nickname' => '已注销用户', 'avatar' => '', 'phone' => '',
            'add_ip' => '', 'last_ip' => '', 'now_money' => 0, 'brokerage_price' => 0, 'integral' => 0,
            'spread_uid' => 0, 'spread_time' => 0, 'agent_id' => 0, 'staff_id' => 0, 'division_id' => 0,
            'status' => 0, 'is_del' => 1, 'uniqid' => strtolower(bin2hex(random_bytes(16))),
        ];
        $update = [];
        foreach ($values as $column => $value) {
            if ($this->hasColumn('user', $column)) {
                $update[$column] = $value;
            }
        }
        $statistics['anonymized.user'] = (int)Db::name('user')->where('uid', $uid)->update($update);
        if ($statistics['anonymized.user'] !== 1) {
            throw new ApiException('账号匿名化失败，销户事务已回滚');
        }
    }

    private function verifySecurity(array $user, array $data): void
    {
        $type = trim((string)($data['verification_type'] ?? ''));
        if ($type === 'password' && $this->hasReliablePassword($user)) {
            if (!hash_equals((string)$user['pwd'], md5((string)($data['password'] ?? '')))) {
                throw new ApiException('账号密码验证失败');
            }
            return;
        }
        if ($type === 'sms' && $this->validPhone((string)($user['phone'] ?? ''))) {
            if (!hash_equals((string)$user['phone'], trim((string)($data['sms_phone'] ?? '')))) {
                throw new ApiException('安全验证手机号与当前账号不一致');
            }
            $code = (string)CacheService::get('code_' . (string)$user['phone']);
            if ($code === '' || !hash_equals(substr($code, 0, 6), trim((string)($data['sms_code'] ?? '')))) {
                throw new ApiException('短信验证码验证失败');
            }
            return;
        }
        throw new ApiException('请先完成账号密码或短信安全验证');
    }

    private function revokeSessions(int $uid, string $currentToken): void
    {
        try {
            if ($currentToken !== '') {
                CacheService::delete(md5($currentToken));
            }
            $handler = Cache::store('redis')->handler();
            $key = 'yfth:user_tokens:' . $uid;
            $members = (array)$handler->sMembers($key);
            foreach ($members as $cacheKey) {
                CacheService::delete((string)$cacheKey);
            }
            $handler->del($key);
            foreach (['user_' . $uid, 'userinfo_' . $uid, 'yfth_context_uid_' . $uid] as $cacheKey) {
                CacheService::delete($cacheKey);
            }
        } catch (\Throwable $e) {
            // The database status/is_del gate already makes every old token unusable.
            Log::error(['msg' => 'yfth_account_closure_session_purge_failed', 'error' => $e->getMessage()]);
        }
    }

    private function countWhereIn(string $table, string $uidColumn, int $uid, string $statusColumn, array $statuses): int
    {
        if (!$this->tableExists($table) || !$this->hasColumn($table, $uidColumn) || !$this->hasColumn($table, $statusColumn)) {
            return 0;
        }
        return (int)Db::name($table)->where($uidColumn, $uid)->whereIn($statusColumn, $statuses)->count();
    }

    private function countWhereNotIn(string $table, string $uidColumn, int $uid, string $statusColumn, array $terminal): int
    {
        if (!$this->tableExists($table) || !$this->hasColumn($table, $uidColumn) || !$this->hasColumn($table, $statusColumn)) {
            return 0;
        }
        return (int)Db::name($table)->where($uidColumn, $uid)->whereNotIn($statusColumn, $terminal)->count();
    }

    private function activeStoreResponsibilityCount(int $uid): int
    {
        $table = 'yfth_user_store_role';
        if (!$this->tableExists($table)
            || !$this->hasColumn($table, 'uid')
            || !$this->hasColumn($table, 'role_code')) {
            return 0;
        }
        $query = Db::name($table)->where('uid', $uid)->where('role_code', 'franchisee');
        if ($this->hasColumn($table, 'status')) {
            $query->where('status', 'active');
        }
        return (int)$query->count();
    }

    private function activePartnerRelationCount(int $uid): int
    {
        if (!$this->tableExists('yfth_partner_relation')
            || !$this->hasColumn('yfth_partner_relation', 'partner_uid')
            || !$this->hasColumn('yfth_partner_relation', 'parent_uid')) {
            return 0;
        }
        $query = Db::name('yfth_partner_relation')->where(function ($q) use ($uid) {
            $q->where('partner_uid', $uid)->whereOr('parent_uid', $uid);
        });
        if ($this->hasColumn('yfth_partner_relation', 'status')) {
            $query->where('status', 'active');
        }
        return (int)$query->count();
    }

    private function appendBlocker(array &$blockers, string $code, string $label, int $count): void
    {
        if ($count > 0) {
            $blockers[] = ['code' => $code, 'label' => $label, 'count' => $count];
        }
    }

    private function newAnonymousSubjectUid(): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            // Some retained legacy user-reference columns are signed INT.
            // Keep the random subject far outside the real UID sequence but
            // compatible with both signed and unsigned historical columns.
            $subject = random_int(1500000000, 2000000000);
            if (!(int)Db::name('user')->where('uid', $subject)->count()
                && !(int)Db::name('yfth_account_closure_subject')->where('subject_uid', $subject)->count()) {
                return $subject;
            }
        }
        throw new ApiException('无法生成销户匿名主体，请稍后重试');
    }

    private function closedSubject(int $uid): array
    {
        if (!$this->tableExists('yfth_account_closure_subject')) {
            return [];
        }
        return (array)Db::name('yfth_account_closure_subject')->where('former_uid_digest', $this->uidDigest($uid))->find();
    }

    private function uidDigest(int $uid): string
    {
        return hash_hmac('sha256', 'yfth-account-closure|' . $uid, (string)Env::get('app.app_key', 'default'));
    }

    private function sensitiveValues(array $user): array
    {
        $values = [];
        foreach (['account', 'real_name', 'card_id', 'nickname', 'phone', 'avatar', 'add_ip', 'last_ip'] as $field) {
            if (trim((string)($user[$field] ?? '')) !== '') {
                $values[] = (string)$user[$field];
            }
        }
        if ($this->tableExists('wechat_user')) {
            $rows = Db::name('wechat_user')->where('uid', (int)$user['uid'])->select()->toArray();
            foreach ($rows as $row) {
                foreach (['openid', 'unionid', 'nickname', 'headimgurl'] as $field) {
                    if (trim((string)($row[$field] ?? '')) !== '') {
                        $values[] = (string)$row[$field];
                    }
                }
            }
        }
        return array_values(array_unique($values));
    }

    private function redactText(string $value, int $uid, int $subjectUid, array $sensitiveValues): string
    {
        if ($value === '') {
            return $value;
        }
        $decoded = json_decode($value);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
            $redacted = $this->redactStructuredValue($decoded, '', $uid, $subjectUid, $sensitiveValues);
            return json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = $this->replaceSearchableSensitive($value, $sensitiveValues);
        return (string)preg_replace_callback(
            '/((?:[\"\']?)([A-Za-z][A-Za-z0-9_]*)(?:[\"\']?)\s*[:=]\s*(?:[\"\']?))([0-9]+)((?:[\"\'])?)/',
            function (array $matches) use ($uid, $subjectUid) {
                if ((int)$matches[3] !== $uid || !$this->isUserSubjectUidKey((string)$matches[2])) {
                    return $matches[0];
                }
                return $matches[1] . $subjectUid . $matches[4];
            },
            $value
        );
    }

    private function redactStructuredValue($value, string $key, int $uid, int $subjectUid, array $sensitiveValues)
    {
        if (is_object($value)) {
            foreach ($value as $childKey => $childValue) {
                $value->{$childKey} = $this->redactStructuredValue($childValue, (string)$childKey, $uid, $subjectUid, $sensitiveValues);
            }
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = $this->redactStructuredValue($childValue, (string)$childKey, $uid, $subjectUid, $sensitiveValues);
            }
            return $value;
        }
        if (!is_scalar($value) && $value !== null) {
            return $value;
        }
        if ($this->isUserSubjectUidKey($key) && (string)$value === (string)$uid) {
            return is_int($value) ? $subjectUid : (string)$subjectUid;
        }
        if ($this->isPersonalSnapshotKey($key) && trim((string)$value) !== '') {
            return '[redacted]';
        }
        return is_string($value) ? $this->replaceSearchableSensitive($value, $sensitiveValues) : $value;
    }

    private function replaceSearchableSensitive(string $value, array $sensitiveValues): string
    {
        foreach ($sensitiveValues as $sensitive) {
            $sensitive = (string)$sensitive;
            if ($this->isSearchableSensitive($sensitive)) {
                $value = str_replace($sensitive, '[redacted]', $value);
            }
        }
        return $value;
    }

    private function isSearchableSensitive(string $value): bool
    {
        $value = trim($value);
        return $value !== '' && (strlen($value) >= 6 || mb_strlen($value) >= 4);
    }

    private function isUserSubjectUidKey(string $key): bool
    {
        $key = strtolower(trim($key));
        if (in_array($key, ['operator_uid', 'reviewer_uid', 'finance_uid', 'assigned_uid', 'admin_uid'], true)) {
            return false;
        }
        return $key === 'uid' || $key === 'user_id' || substr($key, -4) === '_uid';
    }

    private function isPersonalSnapshotKey(string $key): bool
    {
        $key = strtolower(trim($key));
        if (in_array($key, [
            'account', 'real_name', 'nickname', 'applicant_name', 'contact_name', 'recipient_name',
            'phone', 'mobile', 'user_phone', 'contact_phone', 'recipient_phone',
            'openid', 'unionid', 'avatar', 'headimgurl', 'card_id', 'id_card',
            'address', 'user_address', 'recipient_address', 'email', 'ip', 'add_ip', 'last_ip',
        ], true)) {
            return true;
        }
        return (bool)preg_match('/_(?:phone|mobile|openid|unionid|avatar|address)$/', $key);
    }

    private function isUserAuditActorRole(string $roleCode): bool
    {
        if ($roleCode === '') {
            return false;
        }
        return !in_array($roleCode, ['admin', 'administrator', 'super_admin', 'headquarters', 'headquarters_admin', 'hq_admin'], true);
    }

    private function isUserAuditObjectType(string $objectType): bool
    {
        return (bool)preg_match('/(^|_)(?:user|customer|member|account)(_|$)/i', trim($objectType));
    }

    private function isUserIdempotencyOperation(string $domain, string $actionType): bool
    {
        return (bool)preg_match('/(^|_)(?:user|customer|member|account)(_|$)/i', trim($domain . '_' . $actionType));
    }

    private function user(int $uid): array
    {
        $user = $uid > 0 ? (array)Db::name('user')->where('uid', $uid)->find() : [];
        if (!$user || (int)($user['is_del'] ?? 0) !== 0) {
            if ($this->closedSubject($uid)) {
                throw new ApiException('账号已不存在或已经注销');
            }
            throw new ApiException('用户不存在或已经注销');
        }
        return $user;
    }

    private function hasReliablePassword(array $user): bool
    {
        $pwd = (string)($user['pwd'] ?? '');
        return $pwd !== '' && !hash_equals($pwd, md5('123456'));
    }

    private function validPhone(string $phone): bool
    {
        return (bool)preg_match('/^1\d{10}$/', $phone);
    }

    private function tableExists(string $table): bool
    {
        return (bool)$this->columns($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function columns(string $table): array
    {
        if (array_key_exists($table, $this->columns)) {
            return $this->columns[$table];
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
            throw new ApiException('销户业务域配置错误');
        }
        $default = (string)Config::get('database.default');
        $prefix = (string)Config::get('database.connections.' . $default . '.prefix', '');
        $physical = $prefix . $table;
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $physical . '`');
        } catch (\Throwable $e) {
            return $this->columns[$table] = [];
        }
        return $this->columns[$table] = array_values(array_map(function ($row) {
            return (string)($row['Field'] ?? $row['field'] ?? '');
        }, $rows));
    }

    private function enabled(): bool
    {
        return filter_var(Config::get('yfth.user_account_closure_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function assertEnabled(bool $headquarters): void
    {
        if (!$this->enabled()) {
            $this->fail('账号销户功能当前未启用', $headquarters);
        }
    }

    private function fail(string $message, bool $headquarters): void
    {
        if ($headquarters) {
            throw new AdminException($message);
        }
        throw new ApiException($message);
    }

    private function maskPhone(string $phone): string
    {
        return preg_match('/^(\d{3})\d+(\d{4})$/', $phone, $matches) ? $matches[1] . '****' . $matches[2] : '';
    }
}
