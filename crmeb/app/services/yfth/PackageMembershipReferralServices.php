<?php

namespace app\services\yfth;

use app\Request;
use app\dao\yfth\YfthDirectReferralInviteDao;
use app\dao\yfth\YfthHqActiveReferralCurrentDao;
use app\dao\yfth\YfthHqCustomerAttributionCurrentDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class PackageMembershipReferralServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_package_membership_referral';
    private $membership;
    private $attribution;
    private $referral;
    private $attributionDao;
    private $referralDao;
    private $runner;
    private $consistency;
    private $audit;

    public function __construct(
        YfthDirectReferralInviteDao $dao,
        PackageMembershipServices $membership,
        HqCustomerAttributionServices $attribution,
        HqActiveReferralServices $referral,
        YfthHqCustomerAttributionCurrentDao $attributionDao,
        YfthHqActiveReferralCurrentDao $referralDao,
        HqAuthorityOperationRunner $runner,
        HqAuthorityConsistencyValidator $consistency,
        AuditEventServices $audit
    ) {
        $this->dao = $dao;
        $this->membership = $membership;
        $this->attribution = $attribution;
        $this->referral = $referral;
        $this->attributionDao = $attributionDao;
        $this->referralDao = $referralDao;
        $this->runner = $runner;
        $this->consistency = $consistency;
        $this->audit = $audit;
    }

    public function me(int $uid): array
    {
        $membership = $this->membership->effectiveMembership($uid);
        $attribution = $this->row($this->attributionDao->getOne(['uid' => $uid]));
        if ($attribution) {
            $this->consistency->assertAttribution($attribution);
        }
        $referral = $this->row($this->referralDao->search([])->where('referred_uid', $uid)->order('id desc')->find());
        if ($referral) {
            $this->consistency->assertReferral($referral);
        }
        $invite = $this->row($this->dao->getOne(['active_key' => (string)$uid]));
        return [
            'membership' => $membership,
            'attribution' => $attribution ? [
                'store_id' => (int)$attribution['store_id'],
                'status' => (string)$attribution['status'],
            ] : null,
            'direct_referral' => $referral ? [
                'store_id' => (int)$referral['store_id'],
                'status' => (string)$referral['status'],
                'close_reason' => (string)$referral['close_reason'],
            ] : null,
            'active_invite' => $invite && (string)$invite['status'] === 'active' && (int)$invite['expires_at'] > time() ? [
                'invite_no' => (string)$invite['invite_no'],
                'store_id' => (int)$invite['store_id'],
                'expires_at' => (int)$invite['expires_at'],
            ] : null,
        ];
    }

    public function issueInvite(int $uid, array $data): array
    {
        $requestId = $this->requestId($data);
        return Db::transaction(function () use ($uid, $requestId) {
            $lockedCurrents = $this->attribution->lockCurrents([$uid]);
            $member = $this->membership->assertEffectiveActive($uid, 0, true);
            $storeId = (int)$member['store_id'];
            app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
            $attribution = (array)$lockedCurrents[$uid];
            if ((string)$attribution['status'] === 'unassigned'
                && (int)$attribution['authority_version'] === 0
                && (string)$member['authority_type'] === 'historical_package_activation') {
                $source = HqAuthoritySource::fromTrusted(
                    'historical_package_activation',
                    (int)$member['source_package_instance_id']
                );
                $mutation = new HqAuthorityMutation(
                    $source,
                    $uid,
                    'customer',
                    'historical_member_invite_eligibility',
                    $requestId,
                    'historical_member_invite:' . (int)$member['source_package_instance_id']
                );
                $assigned = $this->attribution->assignFirstWithLockedCurrentsInTransaction(
                    $uid,
                    $storeId,
                    $mutation,
                    $lockedCurrents
                );
                $attribution = (array)$assigned['after'];
            }
            $this->consistency->assertAttribution($attribution, true);
            if ((string)$attribution['status'] !== 'active' || (int)$attribution['store_id'] !== $storeId) {
                throw new ApiException('referrer_attribution_store_mismatch');
            }

            $existing = $this->row($this->dao->search([])->where('active_key', (string)$uid)->lock(true)->find());
            if ($existing) {
                $this->dao->update((int)$existing['id'], [
                    'status' => (int)$existing['expires_at'] <= time() ? 'expired' : 'invalidated',
                    'active_key' => null,
                    'invalidated_at' => time(),
                    'update_time' => time(),
                ]);
            }

            $token = bin2hex(random_bytes(32));
            $now = time();
            $row = [
                'invite_no' => $this->makeNo('YFDRI'),
                'owner_uid' => $uid,
                'store_id' => $storeId,
                'token_hash' => hash('sha256', $token),
                'status' => 'active',
                'accepted_uid' => 0,
                'relation_id' => 0,
                'issued_at' => $now,
                'expires_at' => $now + 604800,
                'used_at' => 0,
                'invalidated_at' => 0,
                'active_key' => (string)$uid,
                'request_id' => $requestId,
                'add_time' => $now,
                'update_time' => $now,
            ];
            try {
                $saved = $this->dao->save($row);
                $row['id'] = (int)$saved->id;
            } catch (\Throwable $e) {
                if ($this->isUniqueConflict($e)) {
                    throw new ApiException('direct_referral_invite_unique_conflict');
                }
                throw $e;
            }
            $this->audit->recordSafely(self::DOMAIN, 'direct_referral_invite', (string)$row['id'], 'issue', [], $this->inviteAuditDto($row), $uid, 'customer', $storeId, '', $requestId);
            return [
                'invite_no' => $row['invite_no'],
                'invite_token' => $token,
                'store_id' => $storeId,
                'expires_at' => $row['expires_at'],
            ];
        });
    }

    public function acceptInvite(int $uid, string $token, array $data): array
    {
        $token = trim($token);
        if ($uid <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new ApiException('direct_referral_invite_invalid');
        }
        $tokenHash = hash('sha256', $token);
        $snapshot = $this->row($this->dao->getOne(['token_hash' => $tokenHash]));
        if (!$snapshot) {
            throw new ApiException('direct_referral_invite_invalid');
        }
        $requestId = $this->requestId($data);
        $idempotencyKey = trim((string)($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new ApiException('authority_idempotency_key_required');
        }
        $source = HqAuthoritySource::fromTrusted('package_membership_referral_invite', (int)$snapshot['id'], $data);
        $mutation = new HqAuthorityMutation($source, $uid, 'customer', 'direct_referral_invite_accepted', $requestId, $idempotencyKey);
        $result = $this->runner->run(
            'package_membership_referral_accept',
            $mutation,
            ['uid' => $uid, 'invite_id' => (int)$snapshot['id'], 'token_hash' => $tokenHash],
            'referred_uid:' . $uid,
            function () use ($uid, $tokenHash, $snapshot, $mutation) {
                $ownerUid = (int)$snapshot['owner_uid'];
                $storeId = (int)$snapshot['store_id'];
                if ($ownerUid <= 0 || $ownerUid === $uid || $storeId <= 0) {
                    throw new ApiException('direct_referral_invite_invalid');
                }
                // Match package activation: referred attribution first, then
                // referrer attribution. The invite row is checked only after
                // the shared referred-user serialization gate is held.
                $lockedCurrents = $this->attribution->lockCurrents([$uid]);
                $lockedCurrents += $this->attribution->lockCurrents([$ownerUid]);
                $invite = $this->row($this->dao->search([])->where('id', (int)$snapshot['id'])->where('token_hash', $tokenHash)->lock(true)->find());
                if (!$invite || (string)$invite['status'] !== 'active' || (int)$invite['expires_at'] <= time()) {
                    throw new ApiException('direct_referral_invite_unavailable');
                }
                if ((int)$invite['owner_uid'] !== $ownerUid || (int)$invite['store_id'] !== $storeId) {
                    throw new ApiException('direct_referral_invite_invalid');
                }
                app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
                $this->membership->assertEffectiveActive($ownerUid, $storeId, true);
                if ($this->membership->effectiveMembership($uid)['is_member']) {
                    throw new ApiException('direct_referral_referred_user_must_be_non_member');
                }
                $attribution = $this->attribution->assignFirstWithLockedCurrentsInTransaction($uid, $storeId, $mutation, $lockedCurrents);
                $lockedCurrents[$uid] = (array)$attribution['after'];
                $relation = $this->referral->createWithLockedCurrentsInTransaction($ownerUid, $uid, $storeId, $mutation, $lockedCurrents);
                $relationId = (int)($relation['relation']['id'] ?? 0);
                $this->dao->update((int)$invite['id'], [
                    'status' => 'used',
                    'accepted_uid' => $uid,
                    'relation_id' => $relationId,
                    'used_at' => time(),
                    'active_key' => null,
                    'request_id' => $mutation->requestId(),
                    'update_time' => time(),
                ]);
                return [
                    'changed' => true,
                    'invite_id' => (int)$invite['id'],
                    'store_id' => $storeId,
                    'attribution' => $attribution['after'],
                    'relation' => $relation['relation'],
                ];
            }
        );
        if (empty($result['idempotent_replay']) && !empty($result['changed'])) {
            $this->audit->recordSafely(self::DOMAIN, 'direct_referral_invite', (string)$result['invite_id'], 'accept', [], [
                'accepted_uid' => $uid,
                'store_id' => (int)$result['store_id'],
                'relation_id' => (int)($result['relation']['id'] ?? 0),
            ], $uid, 'customer', (int)$result['store_id'], 'direct_referral_invite_accepted', $requestId);
        }
        return $this->userAcceptResultDto($result);
    }

    public function resolveAuthoritativeStoreForPurchase(int $uid, int $requestedStoreId): int
    {
        $attribution = $this->row($this->attributionDao->getOne(['uid' => $uid]));
        if (!$attribution) {
            return $requestedStoreId;
        }
        $this->consistency->assertAttribution($attribution);
        $status = (string)$attribution['status'];
        if ($status === 'unassigned' && (int)$attribution['authority_version'] === 0) {
            return $requestedStoreId;
        }
        if ($status !== 'active') {
            throw new ApiException('package_purchase_attribution_unavailable');
        }
        $storeId = (int)$attribution['store_id'];
        if ($storeId <= 0 || ($requestedStoreId > 0 && $requestedStoreId !== $storeId)) {
            throw new ApiException('package_purchase_cross_store_forbidden');
        }
        $relation = $this->row($this->referralDao->search([])->where('referred_uid', $uid)->order('id desc')->find());
        if ($relation) {
            $this->consistency->assertReferral($relation);
            if ((string)$relation['status'] === 'paused') {
                throw new ApiException('package_purchase_referral_paused');
            }
            if ((int)$relation['store_id'] !== $storeId) {
                throw new ApiException('package_purchase_referral_store_inconsistent');
            }
        }
        return $storeId;
    }

    public function assertMembershipGrantRule(int $uid, array $rule): void
    {
        if (!app()->make(PackageMembershipGrantPolicy::class)
                ->forRule($rule)['grants_permanent_membership']) {
            throw new ApiException('package_must_grant_permanent_membership');
        }
        $relation = $this->row($this->referralDao->getOne(['active_referred_uid' => $uid]));
        if (!$relation) {
            return;
        }
        $this->consistency->assertReferral($relation);
        if ((string)$relation['status'] !== 'active') {
            throw new ApiException('package_purchase_referral_unavailable');
        }
    }

    public function storeContext(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        if (!in_array((string)$context['role_code'], ['franchisee', 'store_manager'], true) || (int)$context['store_id'] <= 0) {
            throw new ApiException('package_membership_store_read_forbidden');
        }
        return $context;
    }

    private function requestId(array $data): string
    {
        $requestId = trim((string)($data['request_id'] ?? ''));
        return $requestId !== '' ? substr($requestId, 0, 64) : $this->makeNo('YFREQ');
    }

    private function inviteAuditDto(array $row): array
    {
        return [
            'invite_no' => (string)$row['invite_no'],
            'owner_uid' => (int)$row['owner_uid'],
            'store_id' => (int)$row['store_id'],
            'status' => (string)$row['status'],
            'expires_at' => (int)$row['expires_at'],
        ];
    }

    private function userAcceptResultDto(array $result): array
    {
        $attribution = (array)($result['attribution'] ?? []);
        $relation = (array)($result['relation'] ?? []);
        return [
            'changed' => (bool)($result['changed'] ?? false),
            'idempotent_replay' => (bool)($result['idempotent_replay'] ?? false),
            'store_id' => (int)($result['store_id'] ?? $relation['store_id'] ?? 0),
            'attribution' => $attribution ? [
                'store_id' => (int)($attribution['store_id'] ?? 0),
                'status' => (string)($attribution['status'] ?? ''),
            ] : null,
            'direct_referral' => $relation ? [
                'store_id' => (int)($relation['store_id'] ?? 0),
                'status' => (string)($relation['status'] ?? ''),
            ] : null,
        ];
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }

    private function isUniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000';
    }

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
