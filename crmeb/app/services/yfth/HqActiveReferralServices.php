<?php

namespace app\services\yfth;

use app\dao\yfth\YfthHqActiveReferralCurrentDao;
use app\dao\yfth\YfthHqActiveReferralEventDao;
use crmeb\exceptions\ApiException;

class HqActiveReferralServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_hq_active_referral';
    private $eventDao;
    private $attribution;
    private $canonicalizer;
    private $runner;
    private $audit;
    private $qualification;
    private $consistency;

    public function __construct(
        YfthHqActiveReferralCurrentDao $dao,
        YfthHqActiveReferralEventDao $eventDao,
        HqCustomerAttributionServices $attribution,
        HqAuthoritySourceCanonicalizer $canonicalizer,
        HqAuthorityOperationRunner $runner,
        AuditEventServices $audit,
        HqAuthorityConsistencyValidator $consistency,
        $qualification = null
    ) {
        $this->dao = $dao;
        $this->eventDao = $eventDao;
        $this->attribution = $attribution;
        $this->canonicalizer = $canonicalizer;
        $this->runner = $runner;
        $this->audit = $audit;
        $this->consistency = $consistency;
        $this->qualification = $qualification ?: new FailClosedReferralQualificationPolicy();
    }

    public function create(int $referrerUid, int $referredUid, int $storeId, HqAuthorityMutation $mutation): array
    {
        if ($referrerUid <= 0 || $referredUid <= 0 || $referrerUid === $referredUid) {
            throw new ApiException('referral_self_or_invalid_relation');
        }
        $relationSourceKey = $this->canonicalizer->referralRelation($mutation->source());
        $eventSourceKey = $this->canonicalizer->referralEvent('relation_created', $mutation->source());
        $result = $this->runner->run(
            'referral_create',
            $mutation,
            ['referrer_uid' => $referrerUid, 'referred_uid' => $referredUid, 'store_id' => $storeId],
            'referred_uid:' . $referredUid,
            function () use ($referrerUid, $referredUid, $storeId, $relationSourceKey, $eventSourceKey, $mutation) {
                $attributions = $this->attribution->lockCurrents([$referrerUid, $referredUid]);
                $this->assertActiveAttribution($attributions[$referrerUid], $storeId);
                $this->assertActiveAttribution($attributions[$referredUid], $storeId);

                $active = $this->row($this->dao->search([])
                    ->where('active_referred_uid', $referredUid)->lock(true)->find());
                if ($active) {
                    if ((int)$active['referrer_uid'] === $referrerUid && (int)$active['store_id'] === $storeId) {
                        $this->qualification->assertQualified($referrerUid, $storeId);
                        return $this->result($active, $active, false);
                    }
                    throw new ApiException('referral_referred_already_occupied');
                }

                $historical = $this->row($this->dao->search([])
                    ->where('referred_uid', $referredUid)->order('id desc')->lock(true)->find());
                if ($historical) {
                    throw new ApiException('referral_historical_relation_rebind_forbidden');
                }
                $reverse = $this->row($this->dao->search([])
                    ->where('referrer_uid', $referredUid)
                    ->where('active_referred_uid', $referrerUid)
                    ->lock(true)->find());
                if ($reverse) {
                    throw new ApiException('referral_direct_reverse_relation_forbidden');
                }

                $this->qualification->assertQualified($referrerUid, $storeId);
                $existingSource = $this->row($this->dao->getOne(['source_unique_key' => $relationSourceKey]));
                if ($existingSource) {
                    if ((int)$existingSource['referrer_uid'] === $referrerUid
                        && (int)$existingSource['referred_uid'] === $referredUid
                        && (int)$existingSource['store_id'] === $storeId) {
                        return $this->result($existingSource, $existingSource, false);
                    }
                    throw new ApiException('referral_source_conflict');
                }

                $now = time();
                try {
                    $created = $this->dao->save([
                        'relation_no' => $this->makeNo('HRR'),
                        'referrer_uid' => $referrerUid,
                        'referred_uid' => $referredUid,
                        'store_id' => $storeId,
                        'attribution_current_id' => (int)$attributions[$referredUid]['id'],
                        'status' => 'active',
                        'active_referred_uid' => $referredUid,
                        'source_type' => $mutation->source()->type(),
                        'source_id' => $mutation->source()->id(),
                        'source_unique_key' => $relationSourceKey,
                        'started_at' => $now,
                        'paused_at' => 0,
                        'closed_at' => 0,
                        'close_reason' => '',
                        'relation_version' => 1,
                        'request_id' => $mutation->requestId(),
                        'add_time' => $now,
                        'update_time' => $now,
                    ])->toArray();
                } catch (\Throwable $e) {
                    if ($this->isUniqueConflict($e)) {
                        throw new ApiException('referral_unique_conflict');
                    }
                    throw $e;
                }
                $this->appendEvent([], $created, 'relation_created', $eventSourceKey, $mutation);
                return $this->result([], $created, true);
            }
        );
        $this->auditResult('create', $result, $mutation);
        return $result;
    }

    public function pause(int $relationId, int $expectedVersion, HqAuthorityMutation $mutation): array
    {
        return $this->transition($relationId, $expectedVersion, ['active'], 'paused', 'relation_paused', '', false, $mutation);
    }

    public function resume(int $relationId, int $expectedVersion, HqAuthorityMutation $mutation): array
    {
        return $this->transition($relationId, $expectedVersion, ['paused'], 'active', 'relation_resumed', '', true, $mutation);
    }

    public function close(int $relationId, int $expectedVersion, string $closeReason, HqAuthorityMutation $mutation): array
    {
        $closeReason = trim($closeReason);
        if ($closeReason === '') {
            throw new ApiException('referral_close_reason_required');
        }
        return $this->transition($relationId, $expectedVersion, ['active', 'paused'], 'closed', 'relation_closed', $closeReason, false, $mutation);
    }

    public function invalidate(int $relationId, int $expectedVersion, string $closeReason, HqAuthorityMutation $mutation): array
    {
        $closeReason = trim($closeReason);
        if ($closeReason === '') {
            throw new ApiException('referral_invalid_reason_required');
        }
        return $this->transition($relationId, $expectedVersion, ['active', 'paused'], 'invalid', 'relation_invalid', $closeReason, false, $mutation);
    }

    public function closeForMembershipInTransaction(int $referredUid, int $storeId, HqAuthorityMutation $mutation): array
    {
        $lockContext = $this->membershipLockContext($referredUid);
        $lockedCurrents = $this->attribution->lockCurrents($lockContext['uids']);
        return $this->closeForMembershipWithLockedCurrentsInTransaction(
            $referredUid,
            $storeId,
            $mutation,
            $lockContext,
            $lockedCurrents
        );
    }

    public function membershipLockContext(int $referredUid): array
    {
        $snapshot = $this->row($this->dao->search([])
            ->where('active_referred_uid', $referredUid)
            ->order('id desc')->find());
        if (!$snapshot) {
            return [
                'relation_id' => 0,
                'referrer_uid' => 0,
                'referred_uid' => $referredUid,
                'uids' => [$referredUid],
            ];
        }
        $uids = [(int)$snapshot['referrer_uid'], $referredUid];
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids, SORT_NUMERIC);
        return [
            'relation_id' => (int)$snapshot['id'],
            'referrer_uid' => (int)$snapshot['referrer_uid'],
            'referred_uid' => $referredUid,
            'uids' => $uids,
        ];
    }

    public function closeForMembershipWithLockedCurrentsInTransaction(
        int $referredUid,
        int $storeId,
        HqAuthorityMutation $mutation,
        array $lockContext,
        array $lockedCurrents
    ): array {
        if ((int)($lockContext['referred_uid'] ?? 0) !== $referredUid) {
            throw new ApiException('referral_membership_lock_context_invalid');
        }
        $expectedRelationId = (int)($lockContext['relation_id'] ?? 0);
        $snapshot = $this->row($this->dao->search([])
            ->where('active_referred_uid', $referredUid)
            ->order('id desc')->lock(true)->find());
        if ($expectedRelationId === 0) {
            if ($snapshot) {
                throw new ApiException('referral_membership_lock_set_changed');
            }
            return ['relation' => [], 'before' => [], 'after' => [], 'changed' => false];
        }
        if (!$snapshot
            || (int)$snapshot['id'] !== $expectedRelationId
            || (int)$snapshot['referrer_uid'] !== (int)($lockContext['referrer_uid'] ?? 0)) {
            throw new ApiException('referral_membership_lock_set_changed');
        }
        $referrerUid = (int)$snapshot['referrer_uid'];
        if (!isset($lockedCurrents[$referrerUid], $lockedCurrents[$referredUid])) {
            throw new ApiException('referral_membership_lock_set_incomplete');
        }

        $row = $this->row($this->dao->search([])->where('id', $expectedRelationId)->lock(true)->find());
        if (!$row) {
            throw new ApiException('referral_relation_not_found');
        }
        $this->assertConsistent($row);
        if (!in_array((string)$row['status'], ['active', 'paused'], true)
            || (int)$row['referred_uid'] !== $referredUid
            || (int)$row['store_id'] !== $storeId) {
            throw new ApiException('referral_membership_close_conflict');
        }
        $this->assertActiveAttribution((array)$lockedCurrents[(int)$row['referrer_uid']], $storeId);
        $this->assertActiveAttribution((array)$lockedCurrents[$referredUid], $storeId);

        $sourceKey = $this->canonicalizer->referralEvent('relation_closed', $mutation->source());
        $now = time();
        $update = [
            'status' => 'closed',
            'active_referred_uid' => null,
            'closed_at' => $now,
            'close_reason' => 'membership_activated',
            'relation_version' => (int)$row['relation_version'] + 1,
            'request_id' => $mutation->requestId(),
            'update_time' => $now,
        ];
        $this->dao->update((int)$row['id'], $update);
        $after = array_merge($row, $update);
        $this->appendEvent($row, $after, 'relation_closed', $sourceKey, $mutation);
        return $this->result($row, $after, true);
    }

    private function transition(
        int $relationId,
        int $expectedVersion,
        array $fromStatuses,
        string $toStatus,
        string $eventType,
        string $closeReason,
        bool $requireQualification,
        HqAuthorityMutation $mutation
    ): array {
        $sourceKey = $this->canonicalizer->referralEvent($eventType, $mutation->source());
        $result = $this->runner->run(
            $eventType,
            $mutation,
            ['relation_id' => $relationId, 'expected_version' => $expectedVersion, 'to_status' => $toStatus, 'close_reason' => $closeReason],
            'relation:' . $relationId,
            function () use ($relationId, $expectedVersion, $fromStatuses, $toStatus, $eventType, $closeReason, $requireQualification, $sourceKey, $mutation) {
                $snapshot = $this->row($this->dao->get($relationId));
                if (!$snapshot) {
                    throw new ApiException('referral_relation_not_found');
                }
                $attributions = $this->attribution->lockCurrents([(int)$snapshot['referrer_uid'], (int)$snapshot['referred_uid']]);
                $row = $this->row($this->dao->search([])->where('id', $relationId)->lock(true)->find());
                if (!$row) {
                    throw new ApiException('referral_relation_not_found');
                }
                $this->assertConsistent($row);
                if ((int)$row['relation_version'] !== $expectedVersion) {
                    throw new ApiException('referral_version_conflict');
                }
                if (!in_array((string)$row['status'], $fromStatuses, true)) {
                    throw new ApiException('referral_status_transition_forbidden');
                }
                $this->assertActiveAttribution($attributions[(int)$row['referrer_uid']], (int)$row['store_id']);
                $this->assertActiveAttribution($attributions[(int)$row['referred_uid']], (int)$row['store_id']);
                if ($requireQualification) {
                    $this->qualification->assertQualified((int)$row['referrer_uid'], (int)$row['store_id']);
                }

                $newVersion = $expectedVersion + 1;
                $now = time();
                $update = [
                    'status' => $toStatus,
                    'active_referred_uid' => in_array($toStatus, ['active', 'paused'], true) ? (int)$row['referred_uid'] : null,
                    'paused_at' => $toStatus === 'paused' ? $now : 0,
                    'closed_at' => in_array($toStatus, ['closed', 'invalid'], true) ? $now : 0,
                    'close_reason' => in_array($toStatus, ['closed', 'invalid'], true) ? substr($closeReason, 0, 64) : '',
                    'relation_version' => $newVersion,
                    'request_id' => $mutation->requestId(),
                    'update_time' => $now,
                ];
                $this->dao->update($relationId, $update);
                $after = array_merge($row, $update);
                $this->appendEvent($row, $after, $eventType, $sourceKey, $mutation);
                return $this->result($row, $after, true);
            }
        );
        $this->auditResult($eventType, $result, $mutation);
        return $result;
    }

    private function appendEvent(array $before, array $after, string $eventType, string $sourceKey, HqAuthorityMutation $mutation): void
    {
        try {
            $this->eventDao->save([
                'event_no' => $this->makeNo('HRE'),
                'referral_current_id' => (int)$after['id'],
                'relation_no' => (string)$after['relation_no'],
                'relation_version' => (int)$after['relation_version'],
                'referrer_uid' => (int)$after['referrer_uid'],
                'referred_uid' => (int)$after['referred_uid'],
                'store_id' => (int)$after['store_id'],
                'event_type' => $eventType,
                'before_status' => (string)($before['status'] ?? ''),
                'after_status' => (string)$after['status'],
                'source_type' => $mutation->source()->type(),
                'source_id' => $mutation->source()->id(),
                'source_unique_key' => $sourceKey,
                'operator_uid' => $mutation->operatorUid(),
                'operator_role_code' => $mutation->operatorRoleCode(),
                'reason' => $mutation->reason(),
                'request_id' => $mutation->requestId(),
                'add_time' => time(),
            ]);
        } catch (\Throwable $e) {
            if ($this->isUniqueConflict($e)) {
                throw new ApiException('referral_event_unique_conflict');
            }
            throw $e;
        }
    }

    private function assertConsistent(array $row): void
    {
        $this->consistency->assertReferral($row, true);
    }

    private function assertActiveAttribution(array $row, int $storeId): void
    {
        if ((string)$row['status'] !== 'active' || (int)$row['store_id'] !== $storeId || $storeId <= 0) {
            throw new ApiException('referral_attribution_store_mismatch');
        }
    }

    private function result(array $before, array $after, bool $changed): array
    {
        return [
            'relation' => $this->formatRelation($after),
            'before' => $before ? $this->formatRelation($before) : [],
            'after' => $this->formatRelation($after),
            'changed' => $changed,
        ];
    }

    private function formatRelation(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'relation_no' => (string)$row['relation_no'],
            'referrer_uid' => (int)$row['referrer_uid'], 'referred_uid' => (int)$row['referred_uid'],
            'store_id' => (int)$row['store_id'], 'attribution_current_id' => (int)$row['attribution_current_id'],
            'status' => (string)$row['status'],
            'active_referred_uid' => $row['active_referred_uid'] === null ? null : (int)$row['active_referred_uid'],
            'source_type' => (string)$row['source_type'], 'source_id' => (string)$row['source_id'],
            'started_at' => (int)$row['started_at'], 'paused_at' => (int)$row['paused_at'],
            'closed_at' => (int)$row['closed_at'], 'close_reason' => (string)$row['close_reason'],
            'relation_version' => (int)$row['relation_version'], 'request_id' => (string)$row['request_id'],
        ];
    }

    private function auditResult(string $action, array $result, HqAuthorityMutation $mutation): void
    {
        if (!empty($result['idempotent_replay']) || empty($result['changed'])) {
            return;
        }
        $after = (array)($result['after'] ?? []);
        $this->audit->recordSafely(
            self::DOMAIN, 'active_referral_current', (string)($after['id'] ?? 0), $action,
            (array)($result['before'] ?? []), $after, $mutation->operatorUid(),
            $mutation->operatorRoleCode(), (int)($after['store_id'] ?? 0), $mutation->reason(), $mutation->requestId()
        );
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
