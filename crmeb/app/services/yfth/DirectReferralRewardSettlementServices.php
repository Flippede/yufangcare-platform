<?php

namespace app\services\yfth;

use app\dao\yfth\YfthDirectReferralRewardCandidateDao;
use app\dao\yfth\YfthDirectReferralRewardSettlementLedgerDao;
use crmeb\exceptions\ApiException;
use think\facade\Db;

class DirectReferralRewardSettlementServices extends YfthFoundationBaseServices
{
    private const DOMAIN = 'yfth_direct_referral_reward_settlement';
    private $candidateDao;
    private $ledgerDao;
    private $audit;

    public function __construct(
        YfthDirectReferralRewardCandidateDao $candidateDao,
        YfthDirectReferralRewardSettlementLedgerDao $ledgerDao,
        AuditEventServices $audit
    ) {
        $this->candidateDao = $candidateDao;
        $this->ledgerDao = $ledgerDao;
        $this->audit = $audit;
    }

    public function storeCandidates(int $storeId, array $where): array
    {
        return $this->candidatePage(array_merge($where, ['store_id' => $storeId]), 'store');
    }

    public function headquartersCandidates(array $where): array
    {
        return $this->candidatePage($where, 'headquarters');
    }

    public function confirmByStore(int $candidateId, array $context, array $data): array
    {
        return $this->runIdempotent('confirm', $candidateId, (int)$context['uid'], $data, function () use ($candidateId, $context, $data) {
            $candidate = $this->lockedCandidate($candidateId, (int)$context['store_id']);
            if ((string)$candidate['status'] === 'confirmed') {
                return $this->result($candidate, [], true);
            }
            if ((string)$candidate['status'] !== 'pending') {
                throw new ApiException('reward_candidate_confirm_forbidden');
            }
            $after = $this->transition($candidate, 'confirmed');
            $this->audit($candidate, $after, 'confirm', (int)$context['uid'], (string)$context['role_code'], (int)$context['store_id'], trim((string)($data['remark'] ?? '')), trim((string)($data['request_id'] ?? '')));
            return $this->result($after);
        });
    }

    public function settleByStore(int $candidateId, array $context, array $data): array
    {
        return $this->runIdempotent('settle', $candidateId, (int)$context['uid'], $data, function ($requestId) use ($candidateId, $context, $data) {
            $candidate = $this->lockedCandidate($candidateId, (int)$context['store_id']);
            $existing = $this->row($this->ledgerDao->search([])->where('candidate_id', $candidateId)->lock(true)->find());
            if ($existing) {
                if ((string)$candidate['status'] !== 'settled') {
                    throw new ApiException('reward_candidate_ledger_state_inconsistent');
                }
                return $this->result($candidate, $existing, true);
            }
            if ((string)$candidate['status'] !== 'confirmed') {
                throw new ApiException('reward_candidate_settle_forbidden');
            }
            $offlineRefNo = trim((string)($data['offline_ref_no'] ?? ''));
            $proofRef = trim((string)($data['proof_ref'] ?? ''));
            $remark = trim((string)($data['remark'] ?? ''));
            if ($remark === '' || ($offlineRefNo === '' && $proofRef === '')) {
                throw new ApiException('reward_candidate_settlement_evidence_required');
            }
            $now = time();
            $ledger = [
                'settlement_no' => $this->makeNo('YFDRS'),
                'candidate_id' => (int)$candidate['id'],
                'candidate_no' => (string)$candidate['candidate_no'],
                'candidate_type' => (string)$candidate['candidate_type'],
                'store_id' => (int)$candidate['store_id'],
                'referrer_uid' => (int)$candidate['referrer_uid'],
                'referred_uid' => (int)$candidate['referred_uid'],
                'reward_amount_cent' => (int)$candidate['reward_amount_cent'],
                'offline_ref_no' => $offlineRefNo,
                'proof_ref' => $proofRef,
                'remark' => $remark,
                'operator_uid' => (int)$context['uid'],
                'operator_role_code' => (string)$context['role_code'],
                'settled_at' => $now,
                'request_id' => $requestId,
                'add_time' => $now,
                'update_time' => $now,
            ];
            try {
                $saved = $this->ledgerDao->save($ledger);
                $ledger['id'] = (int)$saved->id;
            } catch (\Throwable $e) {
                if (!$this->isUniqueConflict($e)) {
                    throw $e;
                }
                $existing = $this->row($this->ledgerDao->search([])->where('candidate_id', $candidateId)->lock(true)->find());
                if ($existing) {
                    return $this->result($candidate, $existing, true);
                }
                throw $e;
            }
            $after = $this->transition($candidate, 'settled');
            $this->audit($candidate, $after, 'settle', (int)$context['uid'], (string)$context['role_code'], (int)$context['store_id'], $remark, trim((string)($data['request_id'] ?? '')));
            return $this->result($after, $ledger);
        });
    }

    public function cancelByHeadquarters(int $candidateId, int $adminId, array $data): array
    {
        return $this->headquartersTransition('cancel', $candidateId, $adminId, $data, ['pending', 'confirmed'], 'cancelled');
    }

    public function correctByHeadquarters(int $candidateId, int $adminId, array $data): array
    {
        return $this->headquartersTransition('correct', $candidateId, $adminId, $data, ['confirmed'], 'pending');
    }

    private function headquartersTransition(string $action, int $candidateId, int $adminId, array $data, array $from, string $to): array
    {
        return $this->runIdempotent($action, $candidateId, $adminId, $data, function () use ($action, $candidateId, $adminId, $data, $from, $to) {
            $reason = trim((string)($data['reason'] ?? ''));
            if ($reason === '') {
                throw new ApiException('reward_candidate_exception_reason_required');
            }
            $candidate = $this->lockedCandidate($candidateId);
            if ((string)$candidate['status'] === $to) {
                return $this->result($candidate, [], true);
            }
            if (!in_array((string)$candidate['status'], $from, true)) {
                throw new ApiException('reward_candidate_headquarters_transition_forbidden');
            }
            if ((string)$candidate['status'] === 'settled') {
                throw new ApiException('reward_candidate_settled_immutable');
            }
            $after = $this->transition($candidate, $to);
            $this->audit($candidate, $after, $action, $adminId, 'admin', (int)$candidate['store_id'], $reason, trim((string)($data['request_id'] ?? '')));
            return $this->result($after);
        });
    }

    private function candidatePage(array $where, string $scope): array
    {
        $query = $this->candidateDao->search([]);
        foreach (['store_id', 'referrer_uid', 'referred_uid'] as $field) {
            $value = (int)($where[$field] ?? 0);
            if ($value > 0) {
                $query->where($field, $value);
            }
        }
        foreach (['candidate_type', 'status'] as $field) {
            $value = trim((string)($where[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }
        [$page, $limit, $defaultLimit] = $this->getPageValue();
        $limit = $limit ?: $defaultLimit;
        $count = (int)$query->count();
        $list = $query->order('id desc')->page($page, $limit)->select()->toArray();
        $ledgerByCandidate = $this->ledgersByCandidate(array_column($list, 'id'));
        return compact('count') + ['list' => array_map(function ($row) use ($scope, $ledgerByCandidate) {
            return $this->candidateDto($row, $scope, $ledgerByCandidate[(int)$row['id']] ?? []);
        }, $list)];
    }

    private function lockedCandidate(int $candidateId, int $storeId = 0): array
    {
        $query = $this->candidateDao->search([])->where('id', $candidateId);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $candidate = $this->row($query->lock(true)->find());
        if (!$candidate) {
            throw new ApiException('reward_candidate_not_found');
        }
        return $candidate;
    }

    private function transition(array $candidate, string $status): array
    {
        $update = ['status' => $status, 'update_time' => time()];
        $this->candidateDao->update((int)$candidate['id'], $update);
        return array_merge($candidate, $update);
    }

    private function audit(array $before, array $after, string $action, int $operatorUid, string $roleCode, int $storeId, string $reason, string $requestId): void
    {
        $this->audit->record(self::DOMAIN, 'direct_referral_reward_candidate', (string)$before['id'], $action, $this->candidateAuditDto($before), $this->candidateAuditDto($after), $operatorUid, $roleCode, $storeId, $reason, $requestId);
    }

    private function runIdempotent(string $action, int $candidateId, int $operatorUid, array $data, callable $callback): array
    {
        $key = trim((string)($data['request_id'] ?? ''));
        if ($key === '') {
            throw new ApiException('reward_candidate_request_id_required');
        }
        /** @var IdempotencyRecordServices $idempotency */
        $idempotency = app()->make(IdempotencyRecordServices::class);
        $begin = $idempotency->begin(self::DOMAIN, $action, $operatorUid . ':' . $key, [
            'candidate_id' => $candidateId,
            'operator_uid' => $operatorUid,
        ], 'candidate:' . $candidateId, 600);
        if (!$begin['acquired']) {
            if (($begin['status'] ?? '') === 'succeeded' && is_array($begin['result_summary'] ?? null)) {
                return array_merge($begin['result_summary'], ['idempotent_replay' => true]);
            }
            if (!empty($begin['can_retry'])) {
                $begin = $idempotency->tryReacquire($begin['record'], 600);
            }
            if (!$begin['acquired']) {
                throw new ApiException('reward_candidate_request_processing');
            }
        }
        $recordId = (int)$begin['record']['id'];
        $requestId = substr(hash('sha256', self::DOMAIN . ':' . $action . ':' . $operatorUid . ':' . $key), 0, 64);
        try {
            $result = Db::transaction(function () use ($callback, $requestId, $idempotency, $recordId) {
                $result = $callback($requestId);
                $idempotency->complete($recordId, $result);
                return $result;
            });
            return array_merge(['idempotent_replay' => false], $result);
        } catch (\Throwable $e) {
            $idempotency->fail($recordId, $e->getMessage());
            throw $e;
        }
    }

    private function result(array $candidate, array $ledger = [], bool $replayed = false): array
    {
        return [
            'candidate' => $this->candidateDto($candidate, 'store', $ledger),
            'idempotent_replay' => $replayed,
        ];
    }

    private function candidateDto(array $row, string $scope, array $ledger = []): array
    {
        $data = [
            'id' => (int)$row['id'],
            'candidate_no' => (string)$row['candidate_no'],
            'candidate_type' => (string)$row['candidate_type'],
            'store_id' => (int)$row['store_id'],
            'actual_paid_amount_cent' => (int)$row['actual_paid_amount_cent'],
            'ratio_bps' => (int)$row['ratio_bps'],
            'reward_amount_cent' => (int)$row['reward_amount_cent'],
            'status' => (string)$row['status'],
            'responsibility_type' => (string)$row['responsibility_type'],
            'add_time' => (int)$row['add_time'],
            'settlement' => $this->ledgerDto($ledger),
        ];
        if ($scope !== 'user') {
            $data['referrer_uid'] = (int)$row['referrer_uid'];
            $data['referred_uid'] = (int)$row['referred_uid'];
        }
        if ($scope === 'headquarters') {
            $data['reward_sequence_no'] = $row['reward_sequence_no'] === null ? null : (int)$row['reward_sequence_no'];
            $data['rule_version_id'] = (int)$row['rule_version_id'];
            $data['source_business_type'] = (string)$row['source_business_type'];
            $data['source_business_id'] = (string)$row['source_business_id'];
        }
        return $data;
    }

    private function candidateAuditDto(array $row): array
    {
        return [
            'candidate_no' => (string)$row['candidate_no'],
            'candidate_type' => (string)$row['candidate_type'],
            'store_id' => (int)$row['store_id'],
            'reward_amount_cent' => (int)$row['reward_amount_cent'],
            'status' => (string)$row['status'],
        ];
    }

    private function ledgersByCandidate(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = $this->ledgerDao->search([])->whereIn('candidate_id', $ids)->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['candidate_id']] = $row;
        }
        return $result;
    }

    private function ledgerDto(array $row): array
    {
        if (!$row) {
            return [];
        }
        return [
            'settlement_no' => (string)$row['settlement_no'],
            'offline_ref_no' => (string)$row['offline_ref_no'],
            'proof_ref' => (string)$row['proof_ref'],
            'remark' => (string)$row['remark'],
            'operator_uid' => (int)$row['operator_uid'],
            'operator_role_code' => (string)$row['operator_role_code'],
            'settled_at' => (int)$row['settled_at'],
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
