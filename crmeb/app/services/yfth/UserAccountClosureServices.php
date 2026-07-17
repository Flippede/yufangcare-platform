<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use crmeb\services\CacheService;
use think\facade\Config;
use think\facade\Db;

/**
 * Hard account closure for users without immutable financial or fulfilment facts.
 * Every discovered UID reference must be explicitly deleted, detached, or block closure.
 */
class UserAccountClosureServices
{
    private const CONFIRMATION_PHRASE = '确认注销';

    private const DELETE_REFERENCES = [
        'user' => ['uid'],
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
        'yfth_customer_follow_record' => ['uid'],
        'yfth_direct_referral_invite' => ['owner_uid', 'accepted_uid'],
        'yfth_hq_active_referral_current' => ['referrer_uid', 'referred_uid', 'active_referred_uid'],
        'yfth_hq_active_referral_event' => ['referrer_uid', 'referred_uid'],
        'yfth_hq_customer_attribution_current' => ['uid'],
        'yfth_hq_customer_attribution_event' => ['uid'],
        'yfth_permanent_membership' => ['uid'],
        'yfth_permanent_membership_event' => ['uid'],
        'yfth_referral_attribution' => ['referrer_uid', 'referred_uid'],
        'yfth_referral_code' => ['owner_uid'],
        'yfth_store_acquisition_acceptance' => ['customer_uid'],
        'yfth_store_acquisition_code' => ['issuer_uid'],
        'yfth_user_identity' => ['uid'],
        'yfth_user_store_role' => ['uid'],
        'yfth_franchise_identity_grant' => ['target_uid'],
    ];

    private const DETACH_COLUMNS = [
        'assigned_uid', 'audit_uid', 'created_uid', 'creator_uid', 'delivery_uid',
        'disabled_uid', 'finance_uid', 'gift_uid', 'grant_uid', 'inviter_uid',
        'operator_uid', 'pay_uid', 'publish_uid', 'published_uid', 'reversed_uid',
        'reviewer_uid', 'revoke_uid', 'settled_uid', 'spread_two_uid', 'spread_uid',
        'updated_uid', 'verified_uid',
    ];

    private const DETACH_REFERENCES = [
        'yfth_customer_relation' => ['owner_uid'],
        'yfth_store_acquisition_acceptance' => ['issuer_uid'],
    ];

    private $adminScope;
    private $audit;

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
        $result = $this->close($uid, $data, 0, 'customer_self', false);
        if ($token !== '') {
            CacheService::delete(md5($token));
        }
        return $result;
    }

    public function closeForHeadquarters(int $uid, array $data, int $adminId, array $adminInfo): array
    {
        $this->adminScope->assertHeadquarterScope($adminInfo);
        return $this->close($uid, $data, $adminId, 'headquarters_admin', true);
    }

    private function close(int $uid, array $data, int $operatorId, string $operatorRole, bool $headquarters): array
    {
        $this->assertEnabled($headquarters);
        $confirmation = trim((string)($data['confirmation'] ?? ''));
        $reason = trim((string)($data['reason'] ?? ''));
        if (!hash_equals(self::CONFIRMATION_PHRASE, $confirmation)) {
            $this->fail('请输入“确认注销”四个字', $headquarters);
        }
        if ($headquarters && mb_strlen($reason) < 4) {
            $this->fail('总部代办销户必须填写不少于4个字的原因', true);
        }

        $before = $this->preflightData($uid);
        if (!$before['can_close']) {
            $this->fail('该账号存在不可逆业务事实，无法执行完整销户', $headquarters);
        }

        $deleted = Db::transaction(function () use ($uid, $before, $headquarters) {
            $locked = (array)Db::name('user')->where('uid', $uid)->lock(true)->find();
            if (!$locked || (int)($locked['is_del'] ?? 0) !== 0 || (string)$locked['account'] !== (string)$before['account']) {
                $this->fail('销户目标已发生变化，请重新预检', $headquarters);
            }
            $fresh = $this->preflightData($uid);
            if (!$fresh['can_close']) {
                $this->fail('销户预检结果已变化，请重新检查', $headquarters);
            }

            $result = [];
            foreach ($fresh['references'] as $reference) {
                if ($reference['table'] === 'user' && $reference['column'] === 'uid') {
                    continue;
                }
                $key = $reference['table'] . '.' . $reference['column'];
                if ($reference['action'] === 'delete') {
                    $result[$key] = (int)Db::table($reference['physical_table'])
                        ->where($reference['column'], $uid)->delete();
                } elseif ($reference['action'] === 'detach') {
                    $update = [$reference['column'] => 0];
                    if ($reference['table'] === 'user' && $reference['column'] === 'spread_uid') {
                        $update['spread_time'] = 0;
                    }
                    $result[$key] = (int)Db::table($reference['physical_table'])
                        ->where($reference['column'], $uid)->update($update);
                }
            }
            $result['user.uid'] = (int)Db::name('user')->where('uid', $uid)->delete();
            if ($result['user.uid'] !== 1 || $this->discoverReferences($uid)) {
                $this->fail('销户后仍检测到用户数据库引用，事务已回滚', $headquarters);
            }
            return $result;
        });

        $closureNo = 'ACCOUNT-CLOSED-' . strtoupper(bin2hex(random_bytes(12)));
        $this->audit->recordSafely(
            'yfth_user_account_closure',
            'closed_account',
            $closureNo,
            $headquarters ? 'headquarters_close' : 'self_close',
            [],
            ['closed' => true, 'deleted_reference_count' => array_sum($deleted)],
            $operatorId,
            $operatorRole,
            0,
            $headquarters ? $reason : '用户本人确认注销',
            $closureNo
        );

        return ['closed' => true];
    }

    private function preflightData(int $uid): array
    {
        $user = $this->user($uid);
        $references = $this->discoverReferences($uid);
        $blockers = array_values(array_filter($references, function ($reference) {
            return $reference['action'] === 'block';
        }));
        return [
            'enabled' => $this->enabled(),
            'uid' => $uid,
            'account' => (string)($user['account'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            'can_close' => $this->enabled() && !$blockers,
            'confirmation_phrase' => self::CONFIRMATION_PHRASE,
            'references' => $references,
            'blocking_references' => $blockers,
            'blocking_categories' => $this->blockingCategories($blockers),
            'safety_note' => $blockers
                ? '存在必须保留的订单、支付、履约、结算或其他不可逆业务事实，已拒绝完整销户'
                : '销户将删除账号、身份、归属、推荐和门店客户关系，操作不可恢复',
        ];
    }

    private function publicProjection(array $preflight): array
    {
        return [
            'enabled' => $preflight['enabled'],
            'can_close' => $preflight['can_close'],
            'confirmation_phrase' => $preflight['confirmation_phrase'],
            'blocking_categories' => $preflight['blocking_categories'],
            'safety_note' => $preflight['safety_note'],
        ];
    }

    private function discoverReferences(int $uid): array
    {
        $default = (string)Config::get('database.default');
        $connection = 'database.connections.' . $default . '.';
        $database = (string)Config::get($connection . 'database');
        $prefix = (string)Config::get($connection . 'prefix', '');
        $rows = Db::query(
            "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA=? AND (COLUMN_NAME='uid' OR COLUMN_NAME='user_id' OR COLUMN_NAME LIKE '%\\_uid') "
            . 'ORDER BY TABLE_NAME,COLUMN_NAME',
            [$database]
        );
        $references = [];
        foreach ($rows as $row) {
            $physical = (string)$row['TABLE_NAME'];
            $column = (string)$row['COLUMN_NAME'];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $physical) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                throw new AdminException('account_closure_schema_identifier_invalid');
            }
            $count = (int)(Db::query(
                'SELECT COUNT(*) AS aggregate FROM `' . $physical . '` WHERE `' . $column . '`=?',
                [$uid]
            )[0]['aggregate'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $logical = $prefix !== '' && strpos($physical, $prefix) === 0 ? substr($physical, strlen($prefix)) : $physical;
            $references[] = [
                'table' => $logical,
                'physical_table' => $physical,
                'column' => $column,
                'count' => $count,
                'action' => $this->referenceAction($logical, $column),
            ];
        }
        return $references;
    }

    private function referenceAction(string $table, string $column): string
    {
        if (isset(self::DELETE_REFERENCES[$table]) && in_array($column, self::DELETE_REFERENCES[$table], true)) {
            return 'delete';
        }
        if ((isset(self::DETACH_REFERENCES[$table]) && in_array($column, self::DETACH_REFERENCES[$table], true))
            || in_array($column, self::DETACH_COLUMNS, true)) {
            return 'detach';
        }
        return 'block';
    }

    private function blockingCategories(array $blockers): array
    {
        $categories = [];
        foreach ($blockers as $blocker) {
            $table = (string)$blocker['table'];
            if (strpos($table, 'order') !== false || strpos($table, 'pay') !== false || strpos($table, 'refund') !== false
                || strpos($table, 'recharge') !== false || strpos($table, 'extract') !== false || strpos($table, 'bill') !== false) {
                $categories['订单、支付、退款或资金记录'] = true;
            } elseif (strpos($table, 'reward') !== false || strpos($table, 'candidate') !== false || strpos($table, 'ledger') !== false) {
                $categories['奖励、收益或结算记录'] = true;
            } elseif (strpos($table, 'franchise') !== false || strpos($table, 'partner') !== false || strpos($table, 'opening') !== false) {
                $categories['加盟、合伙人或开店记录'] = true;
            } elseif (strpos($table, 'package') !== false || strpos($table, 'benefit') !== false
                || strpos($table, 'appointment') !== false || strpos($table, 'writeoff') !== false) {
                $categories['套餐、权益、预约或核销记录'] = true;
            } else {
                $categories['其他必须保留的业务记录'] = true;
            }
        }
        return array_keys($categories);
    }

    private function user(int $uid): array
    {
        $user = $uid > 0 ? (array)Db::name('user')->where('uid', $uid)->find() : [];
        if (!$user || (int)($user['is_del'] ?? 0) !== 0) {
            throw new ApiException('用户不存在或已经注销');
        }
        return $user;
    }

    private function enabled(): bool
    {
        return filter_var(Config::get('yfth.user_account_closure_enabled', true), FILTER_VALIDATE_BOOLEAN);
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
