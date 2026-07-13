<?php

namespace app\services\yfth;

use app\dao\system\store\SystemStoreDao;
use app\dao\user\UserDao;
use app\dao\yfth\YfthHqCustomerAttributionCurrentDao;
use app\dao\yfth\YfthHqCustomerAttributionEventDao;
use crmeb\exceptions\ApiException;

class HqCustomerAttributionServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_hq_customer_attribution';
    private const STATUSES = ['active', 'paused', 'unassigned', 'closed'];
    private const PAUSE_REASONS = ['temporary_risk_pause', 'temporary_qualification_pause'];
    private const CLOSE_REASONS = ['headquarters_correction_closed', 'account_closed'];

    private $eventDao;
    private $userDao;
    private $storeDao;
    private $canonicalizer;
    private $runner;
    private $audit;
    private $consistency;

    public function __construct(
        YfthHqCustomerAttributionCurrentDao $dao,
        YfthHqCustomerAttributionEventDao $eventDao,
        UserDao $userDao,
        SystemStoreDao $storeDao,
        HqAuthoritySourceCanonicalizer $canonicalizer,
        HqAuthorityOperationRunner $runner,
        AuditEventServices $audit,
        HqAuthorityConsistencyValidator $consistency
    ) {
        $this->dao = $dao;
        $this->eventDao = $eventDao;
        $this->userDao = $userDao;
        $this->storeDao = $storeDao;
        $this->canonicalizer = $canonicalizer;
        $this->runner = $runner;
        $this->audit = $audit;
        $this->consistency = $consistency;
    }

    public function ensurePlaceholder(int $uid): array
    {
        $this->assertUserExists($uid);
        $existing = $this->row($this->dao->getOne(['uid' => $uid]));
        if ($existing) {
            return $existing;
        }
        $now = time();
        try {
            $created = $this->dao->save([
                'uid' => $uid,
                'store_id' => 0,
                'status' => 'unassigned',
                'status_reason_code' => 'initial_placeholder',
                'authority_version' => 0,
                'source_type' => '',
                'source_id' => '',
                'bound_at' => 0,
                'paused_at' => 0,
                'closed_at' => 0,
                'close_reason' => '',
                'add_time' => $now,
                'update_time' => $now,
            ]);
            return $created->toArray();
        } catch (\Throwable $e) {
            if (!$this->isUniqueConflict($e)) {
                throw $e;
            }
        }
        $existing = $this->row($this->dao->getOne(['uid' => $uid]));
        if (!$existing) {
            throw new ApiException('attribution_placeholder_conflict_missing');
        }
        return $existing;
    }

    public function lockCurrents(array $uids): array
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids, SORT_NUMERIC);
        $rows = [];
        foreach ($uids as $uid) {
            $this->ensurePlaceholder($uid);
        }
        foreach ($uids as $uid) {
            $row = $this->row($this->dao->search([])->where('uid', $uid)->lock(true)->find());
            if (!$row) {
                throw new ApiException('attribution_current_not_found');
            }
            $this->assertConsistent($row);
            $rows[$uid] = $row;
        }
        return $rows;
    }

    public function assignFirst(int $uid, int $storeId, HqAuthorityMutation $mutation): array
    {
        $this->assertStoreActive($storeId);
        $sourceKey = $this->canonicalizer->attributionEvent('attribution_created', $mutation->source());
        $result = $this->runner->run(
            'attribution_assign_first',
            $mutation,
            ['uid' => $uid, 'store_id' => $storeId],
            'uid:' . $uid,
            function () use ($uid, $storeId, $sourceKey, $mutation) {
                return $this->assignFirstInTransaction($uid, $storeId, $mutation, $sourceKey);
            }
        );
        $this->auditResult('assign_first', $result, $mutation);
        return $result;
    }

    public function assignFirstInTransaction(int $uid, int $storeId, HqAuthorityMutation $mutation, string $sourceKey = ''): array
    {
        return $this->assignFirstWithLockedCurrentsInTransaction(
            $uid,
            $storeId,
            $mutation,
            $this->lockCurrents([$uid]),
            $sourceKey
        );
    }

    public function assignFirstWithLockedCurrentsInTransaction(
        int $uid,
        int $storeId,
        HqAuthorityMutation $mutation,
        array $lockedCurrents,
        string $sourceKey = ''
    ): array {
        $this->assertStoreActive($storeId);
        $sourceKey = $sourceKey ?: $this->canonicalizer->attributionEvent('attribution_created', $mutation->source());
        if (!isset($lockedCurrents[$uid])) {
            throw new ApiException('attribution_lock_set_incomplete');
        }
        $row = (array)$lockedCurrents[$uid];
        $this->assertConsistent($row);
        if ((string)$row['status'] === 'active') {
            if ((int)$row['store_id'] === $storeId) {
                return $this->result($row, $row, false);
            }
            throw new ApiException('attribution_store_conflict');
        }
        if (!$this->isPristineShape($row)) {
            throw new ApiException('attribution_not_pristine');
        }

        $now = time();
        $update = [
            'store_id' => $storeId,
            'status' => 'active',
            'status_reason_code' => '',
            'authority_version' => 1,
            'source_type' => $mutation->source()->type(),
            'source_id' => $mutation->source()->id(),
            'bound_at' => $now,
            'paused_at' => 0,
            'closed_at' => 0,
            'close_reason' => '',
            'update_time' => $now,
        ];
        $this->dao->update((int)$row['id'], $update);
        $after = array_merge($row, $update);
        $this->appendEvent($row, $after, 'attribution_created', $sourceKey, $mutation);
        return $this->result($row, $after, true);
    }

    public function pause(int $uid, int $expectedVersion, string $reasonCode, HqAuthorityMutation $mutation): array
    {
        if (!in_array($reasonCode, self::PAUSE_REASONS, true)) {
            throw new ApiException('attribution_pause_reason_invalid');
        }
        return $this->transition($uid, $expectedVersion, ['active'], 'paused', $reasonCode, 'attribution_paused', $mutation);
    }

    public function resume(int $uid, int $expectedVersion, HqAuthorityMutation $mutation): array
    {
        return $this->transition($uid, $expectedVersion, ['paused'], 'active', '', 'attribution_resumed', $mutation);
    }

    public function markHistoricalUnassigned(int $uid, int $expectedVersion, HqAuthorityMutation $mutation): array
    {
        return $this->transition($uid, $expectedVersion, ['active', 'paused'], 'unassigned', 'store_terminated_no_successor', 'attribution_unassigned', $mutation);
    }

    public function close(int $uid, int $expectedVersion, string $reasonCode, HqAuthorityMutation $mutation): array
    {
        if (!in_array($reasonCode, self::CLOSE_REASONS, true)) {
            throw new ApiException('attribution_close_reason_invalid');
        }
        return $this->transition($uid, $expectedVersion, ['active', 'paused'], 'closed', $reasonCode, 'attribution_closed', $mutation);
    }

    private function transition(
        int $uid,
        int $expectedVersion,
        array $fromStatuses,
        string $toStatus,
        string $reasonCode,
        string $eventType,
        HqAuthorityMutation $mutation
    ): array {
        if (!in_array($toStatus, self::STATUSES, true)) {
            throw new ApiException('attribution_status_invalid');
        }
        $sourceKey = $this->canonicalizer->attributionEvent($eventType, $mutation->source());
        $result = $this->runner->run(
            $eventType,
            $mutation,
            ['uid' => $uid, 'expected_version' => $expectedVersion, 'to_status' => $toStatus, 'reason_code' => $reasonCode],
            'uid:' . $uid,
            function () use ($uid, $expectedVersion, $fromStatuses, $toStatus, $reasonCode, $eventType, $sourceKey, $mutation) {
                $row = $this->lockCurrents([$uid])[$uid];
                if ((int)$row['authority_version'] !== $expectedVersion) {
                    throw new ApiException('attribution_version_conflict');
                }
                if (!in_array((string)$row['status'], $fromStatuses, true)) {
                    throw new ApiException('attribution_status_transition_forbidden');
                }
                $newVersion = $expectedVersion + 1;
                $now = time();
                $storeId = in_array($toStatus, ['unassigned', 'closed'], true) ? 0 : (int)$row['store_id'];
                $update = [
                    'store_id' => $storeId,
                    'status' => $toStatus,
                    'status_reason_code' => $reasonCode,
                    'authority_version' => $newVersion,
                    'source_type' => $mutation->source()->type(),
                    'source_id' => $mutation->source()->id(),
                    'paused_at' => $toStatus === 'paused' ? $now : 0,
                    'closed_at' => in_array($toStatus, ['unassigned', 'closed'], true) ? $now : 0,
                    'close_reason' => in_array($toStatus, ['unassigned', 'closed'], true) ? $reasonCode : '',
                    'update_time' => $now,
                ];
                $this->dao->update((int)$row['id'], $update);
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
                'event_no' => $this->makeNo('HAE'),
                'attribution_current_id' => (int)$after['id'],
                'uid' => (int)$after['uid'],
                'authority_version' => (int)$after['authority_version'],
                'event_type' => $eventType,
                'before_store_id' => (int)$before['store_id'],
                'after_store_id' => (int)$after['store_id'],
                'before_status' => (string)$before['status'],
                'after_status' => (string)$after['status'],
                'before_status_reason_code' => (string)$before['status_reason_code'],
                'after_status_reason_code' => (string)$after['status_reason_code'],
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
                throw new ApiException('attribution_event_unique_conflict');
            }
            throw $e;
        }
    }

    private function assertConsistent(array $row): void
    {
        $this->consistency->assertAttribution($row, true);
    }

    private function isPristineShape(array $row): bool
    {
        return (string)$row['status'] === 'unassigned'
            && (int)$row['store_id'] === 0
            && (int)$row['authority_version'] === 0
            && (string)$row['status_reason_code'] === 'initial_placeholder';
    }

    private function assertUserExists(int $uid): void
    {
        if ($uid <= 0 || !$this->userDao->getOne(['uid' => $uid, 'is_del' => 0])) {
            throw new ApiException('authority_user_not_found');
        }
    }

    private function assertStoreActive(int $storeId): void
    {
        if ($storeId <= 0 || !$this->storeDao->getOne(['id' => $storeId, 'is_del' => 0, 'is_show' => 1])) {
            throw new ApiException('authority_store_not_active');
        }
    }

    private function result(array $before, array $after, bool $changed): array
    {
        return [
            'current' => $this->formatCurrent($after),
            'before' => $this->formatCurrent($before),
            'after' => $this->formatCurrent($after),
            'changed' => $changed,
        ];
    }

    private function formatCurrent(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'uid' => (int)$row['uid'], 'store_id' => (int)$row['store_id'],
            'status' => (string)$row['status'], 'status_reason_code' => (string)$row['status_reason_code'],
            'authority_version' => (int)$row['authority_version'], 'source_type' => (string)$row['source_type'],
            'source_id' => (string)$row['source_id'], 'bound_at' => (int)$row['bound_at'],
            'paused_at' => (int)$row['paused_at'], 'closed_at' => (int)$row['closed_at'],
            'close_reason' => (string)$row['close_reason'],
        ];
    }

    private function auditResult(string $action, array $result, HqAuthorityMutation $mutation): void
    {
        if (!empty($result['idempotent_replay']) || empty($result['changed'])) {
            return;
        }
        $after = (array)($result['after'] ?? []);
        $this->audit->recordSafely(
            self::DOMAIN, 'customer_attribution_current', (string)($after['id'] ?? 0), $action,
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
