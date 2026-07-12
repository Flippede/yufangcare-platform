<?php

namespace app\services\yfth;

use app\dao\yfth\YfthHqActiveReferralEventDao;
use app\dao\yfth\YfthHqCustomerAttributionEventDao;
use crmeb\exceptions\ApiException;

class HqAuthorityConsistencyValidator
{
    private const ATTRIBUTION_STATUSES = ['active', 'paused', 'unassigned', 'closed'];
    private const ATTRIBUTION_PAUSE_REASONS = ['temporary_risk_pause', 'temporary_qualification_pause'];
    private const ATTRIBUTION_CLOSE_REASONS = ['headquarters_correction_closed', 'account_closed'];
    private const REFERRAL_STATUSES = ['active', 'paused', 'closed', 'invalid'];

    private $attributionEventDao;
    private $referralEventDao;

    public function __construct(
        YfthHqCustomerAttributionEventDao $attributionEventDao,
        YfthHqActiveReferralEventDao $referralEventDao
    ) {
        $this->attributionEventDao = $attributionEventDao;
        $this->referralEventDao = $referralEventDao;
    }

    public function assertAttribution(array $current, bool $lockEvents = false): void
    {
        $query = $this->attributionEventDao->search([])
            ->where('attribution_current_id', (int)($current['id'] ?? 0))
            ->order('authority_version asc,id asc');
        if ($lockEvents) {
            $query = $query->lock(true);
        }
        $this->assertAttributionSnapshot($current, $query->select()->toArray());
    }

    public function isAttributionConsistent(array $current): bool
    {
        try {
            $this->assertAttribution($current);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function assertAttributionSnapshot(array $current, array $events): void
    {
        $id = (int)($current['id'] ?? 0);
        $uid = (int)($current['uid'] ?? 0);
        $storeId = (int)($current['store_id'] ?? -1);
        $status = (string)($current['status'] ?? '');
        $reason = (string)($current['status_reason_code'] ?? '');
        $version = (int)($current['authority_version'] ?? -1);
        if ($id <= 0 || $uid <= 0 || !in_array($status, self::ATTRIBUTION_STATUSES, true) || $version < 0) {
            throw new ApiException('attribution_current_inconsistent');
        }
        if (in_array($status, ['active', 'paused'], true) && $storeId <= 0) {
            throw new ApiException('attribution_current_store_inconsistent');
        }
        if (in_array($status, ['unassigned', 'closed'], true) && $storeId !== 0) {
            throw new ApiException('attribution_current_store_inconsistent');
        }
        if (!$this->attributionReasonShapeIsValid($status, $reason, $version, (string)($current['close_reason'] ?? ''))) {
            throw new ApiException('attribution_current_reason_inconsistent');
        }
        if ($version === 0) {
            if (!$this->isPristineAttribution($current) || count($events) !== 0) {
                throw new ApiException('attribution_placeholder_inconsistent');
            }
            return;
        }
        if (count($events) !== $version) {
            throw new ApiException('attribution_event_count_inconsistent');
        }
        $previous = null;
        foreach (array_values($events) as $offset => $event) {
            $expectedVersion = $offset + 1;
            if ((int)($event['authority_version'] ?? 0) !== $expectedVersion
                || (int)($event['attribution_current_id'] ?? 0) !== $id
                || (int)($event['uid'] ?? 0) !== $uid) {
                throw new ApiException('attribution_event_sequence_inconsistent');
            }
            if ($previous !== null && (
                (int)$event['before_store_id'] !== (int)$previous['after_store_id']
                || (string)$event['before_status'] !== (string)$previous['after_status']
                || (string)$event['before_status_reason_code'] !== (string)$previous['after_status_reason_code']
            )) {
                throw new ApiException('attribution_event_chain_inconsistent');
            }
            $previous = $event;
        }
        $first = $events[0];
        if ((int)$first['before_store_id'] !== 0 || (string)$first['before_status'] !== 'unassigned'
            || (string)$first['before_status_reason_code'] !== 'initial_placeholder'
            || (string)$first['event_type'] !== 'attribution_created') {
            throw new ApiException('attribution_first_event_inconsistent');
        }
        $latest = $events[$version - 1];
        if ((int)$latest['after_store_id'] !== $storeId
            || (string)$latest['after_status'] !== $status
            || (string)$latest['after_status_reason_code'] !== $reason
            || (string)$latest['source_type'] !== (string)($current['source_type'] ?? '')
            || (string)$latest['source_id'] !== (string)($current['source_id'] ?? '')) {
            throw new ApiException('attribution_latest_event_inconsistent');
        }
    }

    public function assertReferral(array $current, bool $lockEvents = false): void
    {
        $query = $this->referralEventDao->search([])
            ->where('referral_current_id', (int)($current['id'] ?? 0))
            ->order('relation_version asc,id asc');
        if ($lockEvents) {
            $query = $query->lock(true);
        }
        $this->assertReferralSnapshot($current, $query->select()->toArray());
    }

    public function isReferralConsistent(array $current): bool
    {
        try {
            $this->assertReferral($current);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function assertReferralSnapshot(array $current, array $events): void
    {
        $id = (int)($current['id'] ?? 0);
        $relationNo = (string)($current['relation_no'] ?? '');
        $referrerUid = (int)($current['referrer_uid'] ?? 0);
        $referredUid = (int)($current['referred_uid'] ?? 0);
        $storeId = (int)($current['store_id'] ?? 0);
        $status = (string)($current['status'] ?? '');
        $version = (int)($current['relation_version'] ?? 0);
        $activeUid = ($current['active_referred_uid'] ?? null) === null ? null : (int)$current['active_referred_uid'];
        if ($id <= 0 || $relationNo === '' || $referrerUid <= 0 || $referredUid <= 0 || $storeId <= 0
            || $referrerUid === $referredUid || $version < 1 || !in_array($status, self::REFERRAL_STATUSES, true)) {
            throw new ApiException('referral_current_inconsistent');
        }
        if ((in_array($status, ['active', 'paused'], true) && $activeUid !== $referredUid)
            || (in_array($status, ['closed', 'invalid'], true) && $activeUid !== null)) {
            throw new ApiException('referral_active_uid_inconsistent');
        }
        $closeReason = (string)($current['close_reason'] ?? '');
        if ((in_array($status, ['active', 'paused'], true) && $closeReason !== '')
            || (in_array($status, ['closed', 'invalid'], true) && $closeReason === '')) {
            throw new ApiException('referral_close_reason_inconsistent');
        }
        if (count($events) !== $version) {
            throw new ApiException('referral_event_count_inconsistent');
        }
        $previous = null;
        foreach (array_values($events) as $offset => $event) {
            $expectedVersion = $offset + 1;
            if ((int)($event['relation_version'] ?? 0) !== $expectedVersion
                || (int)($event['referral_current_id'] ?? 0) !== $id
                || (string)($event['relation_no'] ?? '') !== $relationNo
                || (int)($event['referrer_uid'] ?? 0) !== $referrerUid
                || (int)($event['referred_uid'] ?? 0) !== $referredUid
                || (int)($event['store_id'] ?? 0) !== $storeId) {
                throw new ApiException('referral_event_sequence_inconsistent');
            }
            if ($previous !== null && (string)$event['before_status'] !== (string)$previous['after_status']) {
                throw new ApiException('referral_event_chain_inconsistent');
            }
            $previous = $event;
        }
        $first = $events[0];
        if ((string)$first['before_status'] !== '' || (string)$first['after_status'] !== 'active'
            || (string)$first['event_type'] !== 'relation_created') {
            throw new ApiException('referral_first_event_inconsistent');
        }
        $latest = $events[$version - 1];
        $expectedEventTypes = [
            'active' => ['relation_created', 'relation_resumed'],
            'paused' => ['relation_paused'],
            'closed' => ['relation_closed'],
            'invalid' => ['relation_invalid'],
        ];
        if ((string)$latest['after_status'] !== $status
            || !in_array((string)$latest['event_type'], $expectedEventTypes[$status], true)) {
            throw new ApiException('referral_latest_event_inconsistent');
        }
        if ($closeReason === 'membership_activated'
            && ($status !== 'closed' || (string)$latest['event_type'] !== 'relation_closed')) {
            throw new ApiException('referral_membership_close_inconsistent');
        }
    }

    private function attributionReasonShapeIsValid(string $status, string $reason, int $version, string $closeReason): bool
    {
        if ($version === 0) {
            return $status === 'unassigned' && $reason === 'initial_placeholder' && $closeReason === '';
        }
        if ($status === 'active') {
            return $reason === '' && $closeReason === '';
        }
        if ($status === 'paused') {
            return in_array($reason, self::ATTRIBUTION_PAUSE_REASONS, true) && $closeReason === '';
        }
        if ($status === 'unassigned') {
            return $reason === 'store_terminated_no_successor' && $closeReason === $reason;
        }
        return $status === 'closed' && in_array($reason, self::ATTRIBUTION_CLOSE_REASONS, true) && $closeReason === $reason;
    }

    private function isPristineAttribution(array $current): bool
    {
        return (string)$current['status'] === 'unassigned'
            && (int)$current['store_id'] === 0
            && (int)$current['authority_version'] === 0
            && (string)$current['status_reason_code'] === 'initial_placeholder';
    }
}
