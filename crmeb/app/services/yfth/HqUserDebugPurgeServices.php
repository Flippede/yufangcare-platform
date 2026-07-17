<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;
use think\facade\Config;
use think\facade\Db;

/**
 * Headquarters-only destructive reset for disposable acceptance users.
 * Unknown or irreversible references fail closed instead of being guessed away.
 */
class HqUserDebugPurgeServices
{
    private const CONFIRMATION_PHRASE = '确认删除';
    private const AUDIT_REASON = '总部调试用户删除确认';

    private const REFERENCE_COLUMNS = [
        'uid', 'user_id', 'customer_uid', 'referrer_uid', 'referred_uid',
        'owner_uid', 'issuer_uid', 'accepted_uid',
    ];

    private const DELETABLE_REFERENCES = [
        'user' => ['uid'],
        'wechat_user' => ['uid'],
        'user_address' => ['uid'],
        'user_label_relation' => ['uid'],
        'user_level' => ['uid'],
        'user_sign' => ['uid'],
        'user_invoice' => ['uid'],
        'user_invoice_header' => ['uid'],
        'user_visit' => ['uid'],
        'user_search' => ['uid'],
        'store_cart' => ['uid'],
        'store_product_relation' => ['uid'],
        'store_coupon_user' => ['uid'],
        'store_coupon_issue_user' => ['uid'],
        'yfth_hq_customer_attribution_current' => ['uid'],
        'yfth_hq_customer_attribution_event' => ['uid'],
        'yfth_customer_relation' => ['uid'],
        'yfth_customer_follow_record' => ['uid'],
        'yfth_store_acquisition_acceptance' => ['customer_uid'],
        'yfth_hq_active_referral_current' => ['referred_uid'],
        'yfth_hq_active_referral_event' => ['referred_uid'],
    ];

    private $adminScope;
    private $audit;

    public function __construct(AdminStoreContextServices $adminScope, AuditEventServices $audit)
    {
        $this->adminScope = $adminScope;
        $this->audit = $audit;
    }

    public function preflight(int $uid, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        return $this->preflightData($uid);
    }

    private function preflightData(int $uid): array
    {
        $user = $this->user($uid);
        $references = $this->discoverReferences($uid);
        $blockers = [];
        $deletable = [];
        foreach ($references as $reference) {
            $allowed = isset(self::DELETABLE_REFERENCES[$reference['table']])
                && in_array($reference['column'], self::DELETABLE_REFERENCES[$reference['table']], true);
            if ($allowed) {
                $deletable[] = $reference;
            } else {
                $blockers[] = $reference;
            }
        }

        return [
            'enabled' => $this->enabled(),
            'uid' => $uid,
            'account' => (string)($user['account'] ?? ''),
            'nickname' => (string)($user['nickname'] ?? ''),
            'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            'can_purge' => $this->enabled() && !$blockers,
            'confirmation_phrase' => self::CONFIRMATION_PHRASE,
            'deletable_references' => $deletable,
            'blocking_references' => $blockers,
            'safety_note' => $blockers
                ? '存在订单、会员、经营身份、奖励或未知业务引用，已安全拒绝删除'
                : '仅允许删除无不可逆业务事实的调试用户',
        ];
    }

    public function purge(int $uid, array $data, int $adminId, array $adminInfo): array
    {
        $this->assertHeadquarters($adminInfo);
        $this->assertEnabled();
        $confirmation = trim((string)($data['confirmation'] ?? ''));

        $before = $this->preflight($uid, $adminInfo);
        if (!$before['can_purge']) {
            throw new AdminException('debug_user_purge_blocked_by_business_facts');
        }
        if (!hash_equals(self::CONFIRMATION_PHRASE, $confirmation)) {
            throw new AdminException('debug_user_purge_phrase_invalid');
        }

        $deleted = Db::transaction(function () use ($uid, $before) {
            $locked = (array)Db::name('user')->where('uid', $uid)->lock(true)->find();
            if (!$locked || (int)($locked['is_del'] ?? 0) !== 0 || (string)$locked['account'] !== (string)$before['account']) {
                throw new AdminException('debug_user_purge_target_changed');
            }
            $fresh = $this->preflightData($uid);
            if (!$fresh['can_purge']) {
                throw new AdminException('debug_user_purge_target_changed');
            }

            $result = [];
            $references = $fresh['deletable_references'];
            usort($references, function ($left, $right) {
                return ($left['table'] === 'user' ? 1 : 0) <=> ($right['table'] === 'user' ? 1 : 0);
            });
            foreach ($references as $reference) {
                $key = $reference['table'] . '.' . $reference['column'];
                $result[$key] = (int)Db::table($reference['physical_table'])
                    ->where($reference['column'], $uid)->delete();
            }
            Db::name('user')->where('spread_uid', $uid)->update(['spread_uid' => 0, 'spread_time' => 0]);
            if ($this->discoverReferences($uid)) {
                throw new AdminException('debug_user_purge_residual_reference_detected');
            }
            return $result;
        });

        $this->audit->recordSafely(
            'yfth_user_debug_purge',
            'user',
            (string)$uid,
            'purge',
            ['uid' => $uid, 'account' => (string)$before['account']],
            ['deleted' => true, 'deleted_reference_count' => array_sum($deleted)],
            $adminId,
            'headquarters_admin',
            0,
            self::AUDIT_REASON,
            'debug-user-purge-' . $uid . '-' . date('YmdHis')
        );

        return ['deleted' => true, 'uid' => $uid, 'deleted_references' => $deleted];
    }

    private function discoverReferences(int $uid): array
    {
        $default = (string)Config::get('database.default');
        $connection = 'database.connections.' . $default . '.';
        $database = (string)Config::get($connection . 'database');
        $prefix = (string)Config::get($connection . 'prefix', '');
        $quotedColumns = implode(',', array_fill(0, count(self::REFERENCE_COLUMNS), '?'));
        $rows = Db::query(
            'SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=? AND COLUMN_NAME IN (' . $quotedColumns . ') ORDER BY TABLE_NAME,COLUMN_NAME',
            array_merge([$database], self::REFERENCE_COLUMNS)
        );
        $references = [];
        foreach ($rows as $row) {
            $physical = (string)$row['TABLE_NAME'];
            $column = (string)$row['COLUMN_NAME'];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $physical) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                throw new AdminException('debug_user_purge_schema_identifier_invalid');
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
            ];
        }
        return $references;
    }

    private function user(int $uid): array
    {
        $user = $uid > 0 ? (array)Db::name('user')->where('uid', $uid)->find() : [];
        if (!$user || (int)($user['is_del'] ?? 0) !== 0) {
            throw new AdminException('debug_user_purge_user_not_found');
        }
        return $user;
    }

    private function enabled(): bool
    {
        return filter_var(Config::get('yfth.user_debug_purge_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new AdminException('debug_user_purge_disabled');
        }
    }

    private function assertHeadquarters(array $adminInfo): void
    {
        $this->adminScope->assertHeadquarterScope($adminInfo);
    }

    private function maskPhone(string $phone): string
    {
        return preg_match('/^(\d{3})\d+(\d{4})$/', $phone, $matches) ? $matches[1] . '****' . $matches[2] : '';
    }
}
