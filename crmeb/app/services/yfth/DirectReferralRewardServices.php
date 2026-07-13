<?php

namespace app\services\yfth;

use app\dao\yfth\YfthDirectReferralRewardCandidateDao;
use app\dao\yfth\YfthDirectReferralRuleVersionDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class DirectReferralRewardServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_package_membership_referral';
    private $ruleDao;
    private $membership;
    private $audit;

    public function __construct(
        YfthDirectReferralRewardCandidateDao $dao,
        YfthDirectReferralRuleVersionDao $ruleDao,
        PackageMembershipServices $membership,
        AuditEventServices $audit
    ) {
        $this->dao = $dao;
        $this->ruleDao = $ruleDao;
        $this->membership = $membership;
        $this->audit = $audit;
    }

    public function ruleList(array $where): array
    {
        return $this->pageList(
            $this->cleanWhere(['status' => $where['status'] ?? '']),
            '*',
            'version_no desc,id desc',
            function ($row) {
                return $this->adminRuleDto($row);
            }
        );
    }

    public function saveRule(array $data, int $operatorUid): array
    {
        $id = (int)($data['id'] ?? 0);
        $before = $id ? $this->row($this->ruleDao->get($id)) : [];
        if ($before && (string)$before['status'] !== 'draft') {
            throw new ApiException('published_direct_referral_rule_immutable');
        }
        $ratios = [
            (int)($data['package_ratio_first_bps'] ?? 1500),
            (int)($data['package_ratio_second_bps'] ?? 2500),
            (int)($data['package_ratio_third_bps'] ?? 6000),
        ];
        if ($ratios !== [1500, 2500, 6000]) {
            throw new ApiException('package_three_cycle_ratio_must_be_15_25_60');
        }
        $mallEnabled = (int)!empty($data['mall_consumption_enabled']);
        $mallRatio = (int)($data['mall_consumption_ratio_bps'] ?? 0);
        if ($mallRatio < 0 || $mallRatio > 10000 || ($mallEnabled && $mallRatio <= 0)) {
            throw new ApiException('mall_consumption_ratio_invalid');
        }
        $row = [
            'rule_no' => $before['rule_no'] ?? $this->makeNo('YFDRR'),
            'version_no' => (int)($data['version_no'] ?? 0) ?: (int)($before['version_no'] ?? 0) ?: $this->nextVersion(),
            'status' => 'draft',
            'package_ratio_first_bps' => $ratios[0],
            'package_ratio_second_bps' => $ratios[1],
            'package_ratio_third_bps' => $ratios[2],
            'mall_consumption_enabled' => $mallEnabled,
            'mall_consumption_ratio_bps' => $mallRatio,
            'effective_at' => $this->parseTime($data['effective_at'] ?? 0),
            'expires_at' => $this->parseTime($data['expires_at'] ?? 0),
            'active_key' => null,
            'created_uid' => (int)($before['created_uid'] ?? 0) ?: $operatorUid,
            'published_uid' => 0,
            'published_at' => 0,
            'add_time' => (int)($before['add_time'] ?? 0) ?: time(),
            'update_time' => time(),
        ];
        if ($row['expires_at'] > 0 && $row['effective_at'] > 0 && $row['expires_at'] <= $row['effective_at']) {
            throw new ApiException('direct_referral_rule_window_invalid');
        }
        if ($id) {
            $this->ruleDao->update($id, $row);
        } else {
            $saved = $this->ruleDao->save($row);
            $id = (int)$saved->id;
        }
        $after = $this->row($this->ruleDao->get($id));
        $this->audit->recordSafely(self::DOMAIN, 'direct_referral_rule_version', (string)$id, $before ? 'update' : 'create', $before, $after, $operatorUid, 'admin', 0);
        return $this->adminRuleDto($after);
    }

    public function publishRule(int $id, int $operatorUid): array
    {
        return Db::transaction(function () use ($id, $operatorUid) {
            $row = $this->row($this->ruleDao->search([])->where('id', $id)->lock(true)->find());
            if (!$row) {
                throw new ApiException('direct_referral_rule_not_found');
            }
            if ((string)$row['status'] === 'published') {
                return $this->adminRuleDto($row);
            }
            if ((string)$row['status'] !== 'draft') {
                throw new ApiException('direct_referral_rule_publish_forbidden');
            }
            $active = $this->row($this->ruleDao->search([])->where('active_key', 'published')->lock(true)->find());
            if ($active && (int)$active['id'] !== $id) {
                $this->ruleDao->update((int)$active['id'], [
                    'status' => 'superseded',
                    'active_key' => null,
                    'update_time' => time(),
                ]);
            }
            $update = [
                'status' => 'published',
                'active_key' => 'published',
                'published_uid' => $operatorUid,
                'published_at' => time(),
                'update_time' => time(),
            ];
            $this->ruleDao->update($id, $update);
            $after = array_merge($row, $update);
            $this->audit->recordSafely(self::DOMAIN, 'direct_referral_rule_version', (string)$id, 'publish', $row, $after, $operatorUid, 'admin', 0);
            return $this->adminRuleDto($after);
        });
    }

    public function createPackageCandidateInTransaction(array $relation, int $instanceId, int $amountCent): array
    {
        $referrerUid = (int)$relation['referrer_uid'];
        $storeId = (int)$relation['store_id'];
        $this->membership->assertEffectiveActive($referrerUid, $storeId, true);
        $rule = $this->activeRule(true);
        $sourceKey = hash('sha256', 'package_activation|package_instance|' . $instanceId);
        $existing = $this->row($this->dao->search([])->where('source_unique_key', $sourceKey)->lock(true)->find());
        if ($existing) {
            return ['candidate' => $existing, 'created' => false];
        }
        $latest = $this->row($this->dao->search([])
            ->where('referrer_uid', $referrerUid)
            ->whereNotNull('reward_sequence_no')
            ->order('reward_sequence_no desc')
            ->lock(true)
            ->find());
        $sequence = (int)($latest['reward_sequence_no'] ?? 0) + 1;
        $cycle = (($sequence - 1) % 3) + 1;
        $ratioFields = ['', 'package_ratio_first_bps', 'package_ratio_second_bps', 'package_ratio_third_bps'];
        $ratio = (int)$rule[$ratioFields[$cycle]];
        return $this->createCandidate([
            'candidate_type' => 'package_activation',
            'referrer_uid' => $referrerUid,
            'referred_uid' => (int)$relation['referred_uid'],
            'store_id' => $storeId,
            'relation_id' => (int)$relation['id'],
            'source_business_type' => 'package_instance',
            'source_business_id' => (string)$instanceId,
            'source_unique_key' => $sourceKey,
            'reward_sequence_no' => $sequence,
            'actual_paid_amount_cent' => $amountCent,
            'ratio_bps' => $ratio,
            'reward_amount_cent' => intdiv($amountCent * $ratio, 10000),
            'rule_version_id' => (int)$rule['id'],
        ]);
    }

    public function recordMallOrderPaid(int $orderId): array
    {
        return Db::transaction(function () use ($orderId) {
            $order = $this->row(Db::name('store_order')->where('id', $orderId)->lock(true)->find());
            if (!$order
                || (int)$order['paid'] !== 1
                || (int)($order['pid'] ?? 0) !== 0
                || (int)($order['refund_status'] ?? 0) !== 0
                || (int)($order['is_del'] ?? 0) !== 0
                || (int)($order['is_system_del'] ?? 0) !== 0
                || (int)($order['is_cancel'] ?? 0) !== 0
                || (int)($order['status'] ?? 0) < 0) {
                throw new ApiException('trusted_paid_unrefunded_main_order_required');
            }
            if (Db::name('yfth_package_purchase')->where('order_id', $orderId)->count() > 0) {
                throw new ApiException('package_order_not_mall_consumption');
            }
            $sourceKey = $this->mallOrderSourceKey($orderId);
            $existing = $this->row($this->dao->search([])->where('source_unique_key', $sourceKey)->lock(true)->find());
            if ($existing) {
                return ['candidate' => $existing, 'created' => false];
            }
            $rule = $this->activeRule(false, false);
            if (!$rule || (int)$rule['mall_consumption_enabled'] !== 1 || (int)$rule['mall_consumption_ratio_bps'] <= 0) {
                return ['created' => false, 'reason' => 'mall_consumption_rule_unavailable'];
            }
            $referredUid = (int)$order['uid'];
            $relationSnapshot = $this->row(Db::name('yfth_hq_active_referral_current')
                ->where('active_referred_uid', $referredUid)
                ->where('status', 'active')
                ->find());
            if (!$relationSnapshot) {
                return ['created' => false, 'reason' => 'active_referral_not_found'];
            }
            $lockContext = app()->make(HqActiveReferralServices::class)->membershipLockContext($referredUid);
            if ((int)$lockContext['relation_id'] <= 0) {
                return ['created' => false, 'reason' => 'active_referral_not_found'];
            }
            $relation = $this->row(Db::name('yfth_hq_active_referral_current')
                ->where('id', (int)$lockContext['relation_id'])
                ->where('active_referred_uid', $referredUid)
                ->where('status', 'active')
                ->lock(true)
                ->find());
            if (!$relation || (int)$relation['referrer_uid'] !== (int)$lockContext['referrer_uid']) {
                return ['created' => false, 'reason' => 'active_referral_not_found'];
            }
            app()->make(HqAuthorityConsistencyValidator::class)->assertReferral($relation, true);
            $lockedCurrents = (array)$lockContext['locked_currents'];
            $referrerUid = (int)$relation['referrer_uid'];
            $storeId = (int)$relation['store_id'];
            if (!$this->isActiveAttribution($lockedCurrents[$referrerUid] ?? [], $storeId)
                || !$this->isActiveAttribution($lockedCurrents[$referredUid] ?? [], $storeId)) {
                throw new ApiException('mall_consumption_attribution_store_mismatch');
            }
            if ($this->membership->effectiveMembership($referredUid)['is_member']) {
                return ['created' => false, 'reason' => 'referred_user_already_member'];
            }
            $rule = $this->activeRule(true, false);
            if (!$rule || (int)$rule['mall_consumption_enabled'] !== 1 || (int)$rule['mall_consumption_ratio_bps'] <= 0) {
                return ['created' => false, 'reason' => 'mall_consumption_rule_unavailable'];
            }
            $amountCent = $this->moneyToCents($order['pay_price'] ?? '0.00');
            if ($amountCent <= 0) {
                return ['created' => false, 'reason' => 'mall_consumption_positive_payment_required'];
            }
            return $this->createCandidate([
                'candidate_type' => 'mall_consumption',
                'referrer_uid' => $referrerUid,
                'referred_uid' => $referredUid,
                'store_id' => $storeId,
                'relation_id' => (int)$relation['id'],
                'source_business_type' => 'store_order',
                'source_business_id' => (string)$orderId,
                'source_unique_key' => $sourceKey,
                'reward_sequence_no' => null,
                'actual_paid_amount_cent' => $amountCent,
                'ratio_bps' => (int)$rule['mall_consumption_ratio_bps'],
                'reward_amount_cent' => intdiv($amountCent * (int)$rule['mall_consumption_ratio_bps'], 10000),
                'rule_version_id' => (int)$rule['id'],
            ]);
        });
    }

    public function cancelMallOrderCandidateAfterFullRefund(string $orderSn): array
    {
        $orderSn = trim($orderSn);
        if ($orderSn === '') {
            return ['changed' => false, 'reason' => 'mall_consumption_refund_order_missing'];
        }
        return Db::transaction(function () use ($orderSn) {
            $order = $this->row(Db::name('store_order')->where('order_id', $orderSn)->lock(true)->find());
            if (!$order || (int)($order['pid'] ?? 0) !== 0) {
                return ['changed' => false, 'reason' => 'mall_consumption_refund_main_order_not_found'];
            }
            $candidate = $this->row($this->dao->search([])
                ->where('source_unique_key', $this->mallOrderSourceKey((int)$order['id']))
                ->where('candidate_type', 'mall_consumption')
                ->lock(true)
                ->find());
            if (!$candidate) {
                return ['changed' => false, 'reason' => 'mall_consumption_candidate_not_found'];
            }
            if ((string)$candidate['status'] === 'cancelled') {
                return ['candidate' => $candidate, 'changed' => false, 'idempotent_replay' => true];
            }
            if ((string)$candidate['status'] !== 'pending') {
                return ['candidate' => $candidate, 'changed' => false, 'reason' => 'mall_consumption_candidate_not_pending'];
            }
            $paidCent = $this->moneyToCents($order['pay_price'] ?? '0.00');
            $refundedCent = $this->moneyToCents($order['refund_price'] ?? '0.00');
            if ((int)($order['paid'] ?? 0) !== 1
                || (int)($order['refund_status'] ?? 0) !== 2
                || $paidCent <= 0
                || $refundedCent < $paidCent) {
                return ['candidate' => $candidate, 'changed' => false, 'reason' => 'mall_consumption_full_refund_not_confirmed'];
            }
            $update = ['status' => 'cancelled', 'update_time' => time()];
            $this->dao->update((int)$candidate['id'], $update);
            $after = array_merge($candidate, $update);
            $this->audit->recordSafely(
                self::DOMAIN,
                'direct_referral_reward_candidate',
                (string)$candidate['id'],
                'cancel_after_full_refund',
                $this->adminCandidateDto($candidate),
                $this->adminCandidateDto($after),
                0,
                'system',
                (int)$candidate['store_id'],
                'mall_order_full_refund'
            );
            return ['candidate' => $after, 'changed' => true];
        });
    }

    public function candidateList(array $where): array
    {
        return $this->pageList($this->cleanWhere([
            'referrer_uid' => (int)($where['referrer_uid'] ?? 0) ?: '',
            'referred_uid' => (int)($where['referred_uid'] ?? 0) ?: '',
            'store_id' => (int)($where['store_id'] ?? 0) ?: '',
            'candidate_type' => $where['candidate_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]), '*', 'id desc', function ($row) {
            return $this->adminCandidateDto($row);
        });
    }

    public function storeCandidates(int $storeId, array $where): array
    {
        return $this->pageList($this->cleanWhere([
            'store_id' => $storeId,
            'referrer_uid' => (int)($where['referrer_uid'] ?? 0) ?: '',
            'referred_uid' => (int)($where['referred_uid'] ?? 0) ?: '',
            'candidate_type' => $where['candidate_type'] ?? '',
            'status' => $where['status'] ?? '',
        ]), '*', 'id desc', function ($row) {
            return $this->storeCandidateDto($row);
        });
    }

    public function userCandidates(int $uid): array
    {
        $this->membership->assertEffectiveActive($uid);
        return $this->pageList(['referrer_uid' => $uid], '*', 'id desc', function ($row) {
            return $this->userCandidateDto($row);
        });
    }

    private function createCandidate(array $data): array
    {
        $existing = $this->row($this->dao->getOne(['source_unique_key' => $data['source_unique_key']]));
        if ($existing) {
            return ['candidate' => $existing, 'created' => false];
        }
        $now = time();
        $row = array_merge($data, [
            'candidate_no' => $this->makeNo('YFDRC'),
            'status' => 'pending',
            'responsibility_type' => 'store_mall_revenue',
            'add_time' => $now,
            'update_time' => $now,
        ]);
        try {
            $saved = $this->dao->save($row);
            $row['id'] = (int)$saved->id;
        } catch (\Throwable $e) {
            if (!$this->isUniqueConflict($e)) {
                throw $e;
            }
            $existing = $this->row($this->dao->getOne(['source_unique_key' => $data['source_unique_key']]));
            if ($existing) {
                return ['candidate' => $existing, 'created' => false];
            }
            throw new ApiException('direct_referral_candidate_unique_conflict');
        }
        $this->audit->recordSafely(self::DOMAIN, 'direct_referral_reward_candidate', (string)$row['id'], 'create', [], $this->adminCandidateDto($row), 0, 'system', (int)$row['store_id']);
        return ['candidate' => $row, 'created' => true];
    }

    private function activeRule(bool $lock, bool $required = true): array
    {
        $query = $this->ruleDao->search([])->where('active_key', 'published')->where('status', 'published');
        $now = time();
        $query = $query->where(function ($query) use ($now) {
            $query->where('effective_at', 0)->whereOr('effective_at', '<=', $now);
        })->where(function ($query) use ($now) {
            $query->where('expires_at', 0)->whereOr('expires_at', '>', $now);
        });
        if ($lock) {
            $query = $query->lock(true);
        }
        $rule = $this->row($query->find());
        if (!$rule && $required) {
            throw new ApiException('direct_referral_rule_unavailable');
        }
        return $rule;
    }

    private function userCandidateDto(array $row): array
    {
        return [
            'candidate_no' => (string)$row['candidate_no'],
            'candidate_type' => (string)$row['candidate_type'],
            'store_id' => (int)$row['store_id'],
            'actual_paid_amount_cent' => (int)$row['actual_paid_amount_cent'],
            'ratio_bps' => (int)$row['ratio_bps'],
            'reward_amount_cent' => (int)$row['reward_amount_cent'],
            'status' => (string)$row['status'],
            'responsibility_type' => (string)$row['responsibility_type'],
            'add_time' => (int)$row['add_time'],
        ];
    }

    private function storeCandidateDto(array $row): array
    {
        return array_merge($this->userCandidateDto($row), [
            'referrer_uid' => (int)$row['referrer_uid'],
            'referred_uid' => (int)$row['referred_uid'],
        ]);
    }

    private function adminCandidateDto(array $row): array
    {
        return array_merge($this->storeCandidateDto($row), [
            'reward_sequence_no' => $row['reward_sequence_no'] === null ? null : (int)$row['reward_sequence_no'],
            'rule_version_id' => (int)$row['rule_version_id'],
        ]);
    }

    private function adminRuleDto(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'rule_no' => (string)$row['rule_no'],
            'version_no' => (int)$row['version_no'],
            'status' => (string)$row['status'],
            'package_ratio_first_bps' => (int)$row['package_ratio_first_bps'],
            'package_ratio_second_bps' => (int)$row['package_ratio_second_bps'],
            'package_ratio_third_bps' => (int)$row['package_ratio_third_bps'],
            'mall_consumption_enabled' => (bool)$row['mall_consumption_enabled'],
            'mall_consumption_ratio_bps' => (int)$row['mall_consumption_ratio_bps'],
            'effective_at' => (int)$row['effective_at'],
            'expires_at' => (int)$row['expires_at'],
            'created_uid' => (int)$row['created_uid'],
            'published_uid' => (int)$row['published_uid'],
            'published_at' => (int)$row['published_at'],
            'add_time' => (int)$row['add_time'],
            'update_time' => (int)$row['update_time'],
        ];
    }

    private function nextVersion(): int
    {
        return (int)$this->ruleDao->search([])->max('version_no') + 1;
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }

    private function moneyToCents($value): int
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new ApiException('money_snapshot_invalid');
        }
        return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
    }

    private function mallOrderSourceKey(int $orderId): string
    {
        return hash('sha256', 'mall_consumption|store_order|' . $orderId);
    }

    private function isActiveAttribution(array $row, int $storeId): bool
    {
        return $storeId > 0
            && (string)($row['status'] ?? '') === 'active'
            && (int)($row['store_id'] ?? 0) === $storeId;
    }

    private function isUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false
            || strpos($message, '1062') !== false
            || (string)$e->getCode() === '23000';
    }

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
