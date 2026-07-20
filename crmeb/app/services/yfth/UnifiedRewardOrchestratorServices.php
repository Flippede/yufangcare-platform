<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

/**
 * Durable entry point for every new YFTH reward write.
 * Events are persisted in the caller transaction and may be processed repeatedly.
 */
class UnifiedRewardOrchestratorServices extends YfthFoundationBaseServices
{
    private const EVENT_TYPES = [
        'package_activated',
        'package_invalidated',
        'mall_order_paid',
        'mall_order_completed',
        'mall_order_refunded',
        'partner_store_opened',
        'partner_opening_cancelled',
    ];

    public function enqueue(string $eventType, string $sourceType, string $sourceId, array $payload = []): array
    {
        $eventType = trim($eventType);
        $sourceType = trim($sourceType);
        $sourceId = trim($sourceId);
        if (!in_array($eventType, self::EVENT_TYPES, true) || $sourceType === '' || $sourceId === '') {
            throw new ApiException('yfth_reward_event_invalid');
        }
        $key = hash('sha256', $eventType . '|' . $sourceType . '|' . $sourceId);
        $existing = Db::name('yfth_reward_event')->where('canonical_key', $key)->find();
        if ($existing) {
            return ['event' => $existing, 'created' => false];
        }
        $now = time();
        $row = [
            'event_no' => $this->makeNo('YFRE'),
            'event_type' => $eventType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'canonical_key' => $key,
            'payload_snapshot' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'retry_count' => 0,
            'next_retry_at' => 0,
            'processing_owner' => '',
            'processing_at' => 0,
            'result_type' => '',
            'result_id' => 0,
            'last_error' => '',
            'processed_at' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ];
        try {
            $row['id'] = (int)Db::name('yfth_reward_event')->insertGetId($row);
        } catch (\Throwable $e) {
            $existing = Db::name('yfth_reward_event')->where('canonical_key', $key)->find();
            if (!$existing) throw $e;
            return ['event' => $existing, 'created' => false];
        }
        return ['event' => $row, 'created' => true];
    }

    public function enqueueAndTry(string $eventType, string $sourceType, string $sourceId, array $payload = []): array
    {
        $queued = $this->enqueue($eventType, $sourceType, $sourceId, $payload);
        try {
            $processed = $this->process((int)$queued['event']['id']);
            return array_merge($queued, ['processed' => $processed]);
        } catch (\Throwable $e) {
            return array_merge($queued, ['process_error' => $e->getMessage()]);
        }
    }

    public function process(int $eventId, string $worker = 'inline'): array
    {
        $claimed = Db::transaction(function () use ($eventId, $worker) {
            $event = Db::name('yfth_reward_event')->where('id', $eventId)->lock(true)->find();
            if (!$event) throw new ApiException('yfth_reward_event_not_found');
            if ((string)$event['status'] === 'succeeded' || (string)$event['status'] === 'ignored') {
                return $event;
            }
            if ((string)$event['status'] === 'processing' && (int)$event['processing_at'] > time() - 300) {
                return $event;
            }
            Db::name('yfth_reward_event')->where('id', $eventId)->update([
                'status' => 'processing', 'processing_owner' => substr($worker, 0, 64),
                'processing_at' => time(), 'update_time' => time(),
            ]);
            $event['status'] = 'processing';
            $event['processing_owner'] = substr($worker, 0, 64);
            $event['processing_at'] = time();
            return $event;
        });
        if ((string)$claimed['status'] !== 'processing' || (string)($claimed['processing_owner'] ?? '') !== $worker) {
            return $claimed;
        }
        try {
            $result = $this->dispatch($claimed);
            $resultType = (string)($result['result_type'] ?? 'none');
            $resultId = (int)($result['result_id'] ?? 0);
            $status = !empty($result['ignored']) ? 'ignored' : 'succeeded';
            Db::name('yfth_reward_event')->where('id', $eventId)->update([
                'status' => $status, 'result_type' => $resultType, 'result_id' => $resultId,
                'last_error' => '', 'processed_at' => time(), 'processing_owner' => '',
                'processing_at' => 0, 'update_time' => time(),
            ]);
            return array_merge($claimed, $result, ['status' => $status]);
        } catch (\Throwable $e) {
            $retry = (int)$claimed['retry_count'] + 1;
            Db::name('yfth_reward_event')->where('id', $eventId)->update([
                'status' => 'failed', 'retry_count' => $retry,
                'next_retry_at' => time() + min(3600, 30 * (2 ** min(7, $retry - 1))),
                'last_error' => substr($e->getMessage(), 0, 500),
                'processing_owner' => '', 'processing_at' => 0, 'update_time' => time(),
            ]);
            throw $e;
        }
    }

    public function retryDue(int $limit = 50, string $worker = 'reward-retry'): array
    {
        $limit = max(1, min(200, $limit));
        $ids = Db::name('yfth_reward_event')->whereIn('status', ['pending', 'failed'])
            ->where(function ($query) { $query->where('next_retry_at', 0)->whereOr('next_retry_at', '<=', time()); })
            ->order('id asc')->limit($limit)->column('id');
        $summary = ['selected' => count($ids), 'succeeded' => 0, 'failed' => 0, 'errors' => []];
        foreach ($ids as $id) {
            try {
                $this->process((int)$id, $worker . ':' . getmypid());
                $summary['succeeded']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = ['event_id' => (int)$id, 'error' => substr($e->getMessage(), 0, 255)];
            }
        }
        return $summary;
    }

    public function list(array $where): array
    {
        [$page, $limit] = $this->getPageValue();
        $query = Db::name('yfth_reward_event');
        foreach (['status', 'event_type', 'source_type'] as $field) {
            if (!empty($where[$field])) $query->where($field, trim((string)$where[$field]));
        }
        $count = (int)(clone $query)->count();
        return ['list' => $query->page($page, $limit)->order('id desc')->select()->toArray(), 'count' => $count];
    }

    public function confirmOpeningQuota(int $awardId, int $operatorUid): array
    {
        return Db::transaction(function () use ($awardId, $operatorUid) {
            $award = Db::name('yfth_partner_opening_quota_award')->where('id', $awardId)->lock(true)->find();
            if (!$award) throw new ApiException('opening_quota_award_not_found');
            if ((string)$award['status'] === 'granted') return $award;
            if ((string)$award['status'] !== 'pending') throw new ApiException('opening_quota_award_not_confirmable');
            $amount = (int)$award['quota_amount_cent'];
            if ($amount <= 0) throw new ApiException('opening_quota_award_amount_invalid');
            [$accountId, $ledgerId] = $this->postQuota(
                (int)$award['store_id'], $amount, 'partner_opening_reward', $awardId, 'opening_quota:' . $awardId
            );
            Db::name('yfth_partner_opening_quota_award')->where('id', $awardId)->update([
                'quota_account_id' => $accountId, 'quota_ledger_id' => $ledgerId,
                'status' => 'granted', 'update_time' => time(),
            ]);
            $award['quota_account_id'] = $accountId;
            $award['quota_ledger_id'] = $ledgerId;
            $award['status'] = 'granted';
            $award['confirmed_by'] = $operatorUid;
            return $award;
        });
    }

    public function consistencyIssues(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $issues = [];
        $events = Db::name('yfth_reward_event')->where('status', 'succeeded')
            ->where('result_id', '>', 0)->order('id desc')->limit($limit * 2)->select()->toArray();
        $tables = ['direct_reward_candidate' => 'yfth_direct_referral_reward_candidate', 'opening_quota_award' => 'yfth_partner_opening_quota_award'];
        foreach ($events as $event) {
            $table = $tables[(string)$event['result_type']] ?? '';
            if ($table && !Db::name($table)->where('id', (int)$event['result_id'])->find()) {
                $issues[] = ['issue_type' => 'event_result_missing', 'event_id' => (int)$event['id'], 'source_id' => (string)$event['source_id'], 'detail' => (string)$event['result_type']];
            }
            if (count($issues) >= $limit) break;
        }
        if (count($issues) < $limit) {
            $awards = Db::name('yfth_partner_opening_quota_award')->order('id desc')->limit($limit)->select()->toArray();
            foreach ($awards as $award) {
                $key = hash('sha256', 'partner_store_opened|franchise_application|' . (int)$award['application_id']);
                if (!Db::name('yfth_reward_event')->where('canonical_key', $key)->find()) {
                    $issues[] = ['issue_type' => 'opening_event_missing', 'event_id' => 0, 'source_id' => (string)$award['application_id'], 'detail' => 'opening_quota_award:' . (int)$award['id']];
                }
                if (count($issues) >= $limit) break;
            }
        }
        return ['list' => $issues, 'count' => count($issues), 'checked_at' => time()];
    }

    private function dispatch(array $event): array
    {
        $payload = json_decode((string)($event['payload_snapshot'] ?? ''), true) ?: [];
        switch ((string)$event['event_type']) {
            case 'package_activated':
                $result = app()->make(DirectReferralRewardServices::class)->createPackageCandidateFromEvent($payload);
                if (!empty($result['candidate'])) {
                    app()->make(AutomaticCommissionServices::class)->creditPackageCandidate((array)$result['candidate']);
                }
                return $this->candidateResult($result);
            case 'package_invalidated':
                $result = app()->make(DirectReferralRewardServices::class)->reversePackageCandidateFromEvent($payload);
                if (!empty($result['candidate'])) {
                    app()->make(AutomaticCommissionServices::class)->reversePackageCandidate((array)$result['candidate'], $payload);
                }
                return $this->candidateResult($result);
            case 'mall_order_paid':
                $result = app()->make(DirectReferralRewardServices::class)->recordMallOrderPaid((int)$event['source_id']);
                $snapshot = app()->make(AutomaticCommissionServices::class)->snapshotMallOrderPaid((int)$event['source_id']);
                if (!empty($snapshot['snapshot'])) {
                    return ['result_type' => 'mall_commission_snapshot', 'result_id' => (int)$snapshot['snapshot']['id']];
                }
                return $this->candidateResult($result);
            case 'mall_order_completed':
                $result = app()->make(AutomaticCommissionServices::class)->completeMallOrder((int)$event['source_id']);
                return ['result_type' => 'mall_commission_snapshot', 'result_id' => (int)($result['snapshot_id'] ?? 0)];
            case 'mall_order_refunded':
                $result = app()->make(DirectReferralRewardServices::class)->adjustMallOrderCandidateAfterRefund((int)$event['source_id'], $payload);
                $automatic = app()->make(AutomaticCommissionServices::class)->refundMallOrder((int)$event['source_id'], $payload);
                if (($automatic['reason'] ?? '') !== 'mall_order_snapshot_missing') {
                    return ['result_type' => 'mall_commission_snapshot', 'result_id' => (int)Db::name('yfth_mall_commission_order_snapshot')->where('order_id', (int)$event['source_id'])->value('id')];
                }
                return $this->candidateResult($result);
            case 'partner_store_opened':
                return $this->grantOpeningQuota($payload);
            case 'partner_opening_cancelled':
                return $this->reverseOpeningQuota($payload);
        }
        throw new ApiException('yfth_reward_event_type_unsupported');
    }

    private function grantOpeningQuota(array $payload): array
    {
        return Db::transaction(function () use ($payload) {
            $applicationId = (int)($payload['application_id'] ?? 0);
            $partnerUid = (int)($payload['direct_partner_uid'] ?? 0);
            if ($applicationId <= 0 || $partnerUid <= 0) return ['ignored' => true, 'reason' => 'headquarters_direct_opening'];
            $existing = Db::name('yfth_partner_opening_quota_award')->where('application_id', $applicationId)->lock(true)->find();
            if ($existing) return ['result_type' => 'opening_quota_award', 'result_id' => (int)$existing['id']];
            $profile = Db::name('yfth_partner_profile')->where(['uid' => $partnerUid, 'status' => 'active', 'qualification_status' => 'effective'])->lock(true)->find();
            if (!$profile) throw new ApiException('direct_partner_not_effective');
            $sequence = (int)Db::name('yfth_partner_opening_quota_award')->where('partner_uid', $partnerUid)->lock(true)->max('sequence_no') + 1;
            $ratios = [1 => 2000, 2 => 3000, 3 => 5000];
            $ratio = $ratios[$sequence] ?? 0;
            $feeCent = max(0, (int)($payload['fee_amount_cent'] ?? 0));
            $quotaCent = $ratio > 0 ? intdiv($feeCent * $ratio, 10000) : 0;
            $storeId = (int)$profile['primary_store_id'];
            $status = $ratio > 0 ? 'pending' : 'ineligible';
            $now = time();
            $award = [
                'award_no' => $this->makeNo('YFOQA'), 'application_id' => $applicationId,
                'performance_id' => (int)($payload['performance_id'] ?? 0), 'partner_uid' => $partnerUid,
                'store_id' => $storeId, 'sequence_no' => $sequence, 'fee_amount_cent' => $feeCent,
                'ratio_bps' => $ratio, 'quota_amount_cent' => $quotaCent, 'quota_account_id' => 0,
                'quota_ledger_id' => 0, 'status' => $status,
                'snapshot_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => $now, 'update_time' => $now,
            ];
            $awardId = (int)Db::name('yfth_partner_opening_quota_award')->insertGetId($award);
            return ['result_type' => 'opening_quota_award', 'result_id' => $awardId];
        });
    }

    private function reverseOpeningQuota(array $payload): array
    {
        return Db::transaction(function () use ($payload) {
            $applicationId = (int)($payload['application_id'] ?? 0);
            $award = Db::name('yfth_partner_opening_quota_award')->where('application_id', $applicationId)->lock(true)->find();
            if (!$award || in_array((string)$award['status'], ['reversed', 'ineligible'], true)) return ['ignored' => true];
            $amount = (int)$award['quota_amount_cent'];
            if ($amount > 0) {
                $this->postQuota((int)$award['store_id'], -$amount, 'partner_opening_reversal', (int)$award['id'], 'opening_quota_reverse:' . (int)$award['id']);
            }
            Db::name('yfth_partner_opening_quota_award')->where('id', (int)$award['id'])->update(['status' => 'reversed', 'update_time' => time()]);
            return ['result_type' => 'opening_quota_award', 'result_id' => (int)$award['id']];
        });
    }

    private function postQuota(int $storeId, int $delta, string $sourceType, int $sourceId, string $key): array
    {
        if ($storeId <= 0 || $delta === 0) throw new ApiException('product_quota_post_invalid');
        $posted = Db::name('yfth_product_quota_ledger')->where('idempotency_key', $key)->find();
        if ($posted) return [(int)$posted['account_id'], (int)$posted['id']];
        $account = Db::name('yfth_product_quota_account')->where('active_key', 'store:' . $storeId . ':return_goods')->lock(true)->find();
        $now = time();
        if (!$account) {
            $accountId = (int)Db::name('yfth_product_quota_account')->insertGetId([
                'account_no' => $this->makeNo('PQA'), 'store_id' => $storeId, 'quota_type' => 'return_goods',
                'status' => 'active', 'total_granted_cent' => 0, 'total_adjusted_cent' => 0,
                'total_reversed_cent' => 0, 'reserved_cent' => 0, 'consumed_cent' => 0,
                'available_cent' => 0, 'frozen_cent' => 0, 'version' => 1,
                'active_key' => 'store:' . $storeId . ':return_goods', 'remark' => '',
                'create_time' => $now, 'update_time' => $now,
            ]);
            $account = Db::name('yfth_product_quota_account')->where('id', $accountId)->lock(true)->find();
        }
        if ((string)$account['status'] !== 'active') throw new ApiException('product_quota_account_not_active');
        $before = (int)$account['available_cent'];
        $after = $before + $delta;
        if ($after < 0) throw new ApiException('product_quota_reversal_insufficient');
        $update = ['available_cent' => $after, 'version' => (int)$account['version'] + 1, 'update_time' => $now];
        if ($delta > 0) $update['total_granted_cent'] = (int)$account['total_granted_cent'] + $delta;
        else $update['total_reversed_cent'] = (int)$account['total_reversed_cent'] + abs($delta);
        Db::name('yfth_product_quota_account')->where('id', (int)$account['id'])->update($update);
        $ledgerId = (int)Db::name('yfth_product_quota_ledger')->insertGetId([
            'ledger_no' => $this->makeNo('PQL'), 'account_id' => (int)$account['id'], 'store_id' => $storeId,
            'quota_type' => 'return_goods', 'direction' => $delta > 0 ? 'in' : 'out',
            'action_type' => $sourceType, 'amount_cent' => abs($delta), 'balance_before_cent' => $before,
            'balance_after_cent' => $after, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'idempotency_key' => $key, 'status' => 'posted', 'operator_type' => 'system',
            'operator_uid' => 0, 'reason' => $sourceType, 'create_time' => $now,
        ]);
        return [(int)$account['id'], $ledgerId];
    }

    private function candidateResult(array $result): array
    {
        $candidate = (array)($result['candidate'] ?? []);
        if (!$candidate && !empty($result['reason'])) return ['ignored' => true, 'reason' => $result['reason']];
        return ['result_type' => 'direct_reward_candidate', 'result_id' => (int)($candidate['id'] ?? 0)];
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }
}
