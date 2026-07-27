<?php

namespace app\services\yfth;

use app\Request;
use crmeb\exceptions\ApiException;
use think\facade\Db;
use think\facade\Env;

/**
 * Partner and B1 manual-bank withdrawal authority.
 *
 * C1 settlement is intentionally excluded: it remains an offline settlement
 * request handled by the responsible B1 through CommissionFinanceServices.
 */
class FundWithdrawalServices
{
    private const PARTNER_RANKS = [
        'county_partner',
        'prefecture_partner',
        'province_partner',
        'regional_director',
        'platform_director',
    ];

    private const ACTIVE_STATUSES = ['pending_review', 'approved'];

    public function partnerSummary(Request $request): array
    {
        $uid = (int)$request->uid();
        $profile = $this->activePartner($uid);
        return $this->ownerSummary('partner', $uid, (string)$profile['rank_code']);
    }

    public function partnerRequests(Request $request, array $where): array
    {
        $uid = (int)$request->uid();
        $this->activePartner($uid);
        return $this->ownerRequests('partner', $uid, $where);
    }

    public function createPartnerRequest(Request $request, array $data): array
    {
        $uid = (int)$request->uid();
        $profile = $this->activePartner($uid);
        return $this->createRequest('partner', $uid, $uid, (string)$profile['rank_code'], 0, $data);
    }

    public function storeSummary(Request $request): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $storeId = $this->assertStoreReader($context);
        return $this->ownerSummary('store', $storeId, '');
    }

    public function storeRequests(Request $request, array $where): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        return $this->ownerRequests('store', $this->assertStoreReader($context), $where);
    }

    public function createStoreRequest(Request $request, array $data): array
    {
        $context = app()->make(CurrentBusinessContextServices::class)->fromRequest($request);
        $storeId = $this->assertStoreApplicant($context);
        return $this->createRequest('store', $storeId, (int)$context['uid'], '', $storeId, $data);
    }

    public function beneficiaryProfile(Request $request): array
    {
        return $this->beneficiaryProfileDto((int)$request->uid());
    }

    public function saveBeneficiaryProfile(Request $request, array $data): array
    {
        return $this->saveBeneficiaryForUid((int)$request->uid(), $data);
    }

    public function financeList(array $where): array
    {
        [$page, $limit] = $this->paging($where);
        $query = Db::name('yfth_fund_withdrawal_request')->alias('w')
            ->leftJoin('user u', 'u.uid=w.applicant_uid')
            ->leftJoin('system_store s', 's.id=w.store_id');
        foreach (['owner_type', 'status', 'rank_code'] as $field) {
            if (!empty($where[$field])) {
                $query->where('w.' . $field, trim((string)$where[$field]));
            }
        }
        if (!empty($where['keyword'])) {
            $keyword = trim((string)$where['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('w.request_no', '%' . $keyword . '%')
                    ->whereOrLike('u.nickname', '%' . $keyword . '%')
                    ->whereOrLike('u.phone', '%' . $keyword . '%')
                    ->whereOrLike('s.name', '%' . $keyword . '%');
            });
        }
        $count = (int)(clone $query)->count();
        $rows = $query->field(
            'w.*,u.nickname,u.phone,s.name AS store_name'
        )->page($page, $limit)->order('w.id desc')->select()->toArray();
        foreach ($rows as &$row) {
            $row = $this->requestDto($row, false);
        }
        return ['list' => $rows, 'count' => $count];
    }

    public function financeDetail(int $id): array
    {
        $row = $this->requestRow($id);
        $row['receiver_name'] = $this->decrypt((string)$row['receiver_name_enc']);
        $row['receiver_account'] = $this->decrypt((string)$row['receiver_account_enc']);
        $row['allocations'] = Db::name('yfth_fund_withdrawal_allocation')
            ->where('request_id', $id)->order('id asc')->select()->toArray();
        return $this->requestDto($row, true);
    }

    public function review(int $id, string $action, string $reason, int $adminId): array
    {
        $action = trim($action);
        $reason = trim($reason);
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new ApiException('fund_withdrawal_review_action_invalid');
        }
        if (mb_strlen($reason) < 4) {
            throw new ApiException('fund_withdrawal_review_reason_too_short');
        }
        return Db::transaction(function () use ($id, $action, $reason, $adminId) {
            $row = $this->requestRow($id, true);
            if (in_array((string)$row['status'], ['paid', 'rejected'], true)) {
                if ((string)$row['status'] === ($action === 'reject' ? 'rejected' : 'paid')) {
                    return $this->requestDto($row, false);
                }
                throw new ApiException('fund_withdrawal_review_status_invalid');
            }
            if (!in_array((string)$row['status'], self::ACTIVE_STATUSES, true)) {
                throw new ApiException('fund_withdrawal_review_status_invalid');
            }
            $now = time();
            $status = $action === 'approve' ? 'approved' : 'rejected';
            if ($status === 'approved') {
                $this->assertRequestCovered($row);
            } else {
                Db::name('yfth_fund_withdrawal_allocation')->where('request_id', $id)
                    ->where('status', 'frozen')->update(['status' => 'released', 'update_time' => $now]);
            }
            Db::name('yfth_fund_withdrawal_request')->where('id', $id)->update([
                'status' => $status,
                'review_admin_id' => $adminId,
                'review_reason' => mb_substr($reason, 0, 255),
                'review_time' => $now,
                'update_time' => $now,
            ]);
            return $this->requestDto($this->requestRow($id), false);
        });
    }

    public function confirmPaid(int $id, string $reference, string $remark, int $adminId): array
    {
        $reference = trim($reference);
        $remark = trim($remark);
        if ($reference === '') {
            throw new ApiException('fund_withdrawal_pay_reference_required');
        }
        if (mb_strlen($remark) < 4) {
            throw new ApiException('fund_withdrawal_pay_remark_too_short');
        }
        return Db::transaction(function () use ($id, $reference, $remark, $adminId) {
            $row = $this->requestRow($id, true);
            if ((string)$row['status'] === 'paid') {
                return $this->requestDto($row, false);
            }
            if ((string)$row['status'] !== 'approved') {
                throw new ApiException('fund_withdrawal_pay_status_invalid');
            }
            $this->assertRequestCovered($row);
            $now = time();
            if ((string)$row['owner_type'] === 'store') {
                $this->payStoreRequest($row, $adminId, $now);
            }
            Db::name('yfth_fund_withdrawal_allocation')->where('request_id', $id)
                ->where('status', 'frozen')->update(['status' => 'paid', 'update_time' => $now]);
            if ((string)$row['owner_type'] === 'partner') {
                $this->settlePaidPartnerSources($id, (int)$row['owner_id'], $adminId, $now);
            }
            Db::name('yfth_fund_withdrawal_request')->where('id', $id)->update([
                'status' => 'paid',
                'pay_admin_id' => $adminId,
                'pay_reference' => mb_substr($reference, 0, 128),
                'pay_remark' => mb_substr($remark, 0, 255),
                'paid_time' => $now,
                'update_time' => $now,
            ]);
            return $this->requestDto($this->requestRow($id), false);
        });
    }

    public function reconcilePaidPartnerRequest(int $id, int $adminId = 0): array
    {
        return Db::transaction(function () use ($id, $adminId) {
            $row = $this->requestRow($id, true);
            if ((string)$row['owner_type'] !== 'partner' || (string)$row['status'] !== 'paid') {
                throw new ApiException('fund_withdrawal_paid_partner_request_required');
            }
            $this->settlePaidPartnerSources(
                $id,
                (int)$row['owner_id'],
                $adminId > 0 ? $adminId : (int)$row['pay_admin_id'],
                (int)$row['paid_time'] > 0 ? (int)$row['paid_time'] : time()
            );
            return $this->requestDto($this->requestRow($id), false);
        });
    }

    private function createRequest(
        string $ownerType,
        int $ownerId,
        int $applicantUid,
        string $rankCode,
        int $storeId,
        array $data
    ): array {
        $setting = $this->setting();
        if (empty($setting['enabled'])) {
            throw new ApiException('fund_withdrawal_disabled');
        }
        $amountCent = max(0, (int)($data['amount_cent'] ?? 0));
        $requestId = trim((string)($data['request_id'] ?? ''));
        if ($amountCent <= 0 || $requestId === '') {
            throw new ApiException('fund_withdrawal_request_invalid');
        }
        return Db::transaction(function () use (
            $ownerType, $ownerId, $applicantUid, $rankCode, $storeId, $data,
            $amountCent, $requestId
        ) {
            $existing = Db::name('yfth_fund_withdrawal_request')->where([
                'owner_type' => $ownerType, 'owner_id' => $ownerId, 'request_id' => $requestId,
            ])->lock(true)->find();
            if ($existing) {
                return $this->requestDto($existing, false);
            }
            $beneficiary = $this->beneficiaryForWithdrawal($applicantUid, true);
            $summary = $this->ownerSummary($ownerType, $ownerId, $rankCode, true);
            if ((int)$summary['available_cent'] < $amountCent) {
                throw new ApiException('fund_withdrawal_available_insufficient');
            }
            $now = time();
            $row = [
                'request_no' => 'YFWD' . date('YmdHis') . substr(hash('sha256', $ownerType . ':' . $ownerId . ':' . $requestId), 0, 12),
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'applicant_uid' => $applicantUid,
                'rank_code' => $rankCode,
                'store_id' => $storeId,
                'amount_cent' => $amountCent,
                'status' => 'pending_review',
                'payout_method' => 'bank',
                'receiver_name_enc' => $this->encrypt($beneficiary['receiver_name']),
                'receiver_name_masked' => $beneficiary['receiver_name_masked'],
                'receiver_account_enc' => $this->encrypt($beneficiary['receiver_account']),
                'receiver_account_masked' => $beneficiary['receiver_account_masked'],
                'bank_name' => $beneficiary['bank_name'],
                'request_id' => mb_substr($requestId, 0, 96),
                'applicant_remark' => mb_substr(trim((string)($data['remark'] ?? '')), 0, 255),
                'review_admin_id' => 0, 'review_reason' => '', 'review_time' => 0,
                'pay_admin_id' => 0, 'pay_reference' => '', 'pay_remark' => '', 'paid_time' => 0,
                'add_time' => $now, 'update_time' => $now,
            ];
            $row['id'] = (int)Db::name('yfth_fund_withdrawal_request')->insertGetId($row);
            if ($ownerType === 'partner') {
                $this->allocatePartnerSources($row, $amountCent, $now);
            } else {
                $account = Db::name('yfth_store_commission_account')->where('store_id', $ownerId)->lock(true)->find();
                if (!$account || (int)$account['unsettled_cent'] < $amountCent) {
                    throw new ApiException('fund_withdrawal_store_account_inconsistent');
                }
                Db::name('yfth_fund_withdrawal_allocation')->insert([
                    'request_id' => (int)$row['id'], 'owner_type' => 'store', 'owner_id' => $ownerId,
                    'source_type' => 'store_commission_account', 'source_id' => (int)$account['id'],
                    'amount_cent' => $amountCent, 'status' => 'frozen',
                    'add_time' => $now, 'update_time' => $now,
                ]);
            }
            return $this->requestDto($row, false);
        });
    }

    private function saveBeneficiaryForUid(int $uid, array $data): array
    {
        $receiverName = trim((string)($data['receiver_name'] ?? ''));
        $receiverAccount = preg_replace('/\s+/', '', trim((string)($data['receiver_account'] ?? '')));
        $bankName = trim((string)($data['bank_name'] ?? ''));
        if ($uid <= 0 || mb_strlen($receiverName) < 2 || mb_strlen($receiverName) > 64
            || !preg_match('/^[0-9]{8,32}$/', $receiverAccount)
            || $bankName === '' || mb_strlen($bankName) > 128) {
            throw new ApiException('fund_withdrawal_beneficiary_invalid');
        }
        Db::transaction(function () use ($uid, $receiverName, $receiverAccount, $bankName) {
            $now = time();
            $row = [
                'payout_method' => 'bank',
                'receiver_name_enc' => $this->encrypt($receiverName),
                'receiver_name_masked' => $this->maskName($receiverName),
                'receiver_account_enc' => $this->encrypt($receiverAccount),
                'receiver_account_masked' => $this->maskAccount($receiverAccount),
                'bank_name' => mb_substr($bankName, 0, 128),
                'status' => 'active',
                'update_time' => $now,
            ];
            $existing = Db::name('yfth_fund_beneficiary_profile')->where('uid', $uid)->lock(true)->find();
            if ($existing) {
                Db::name('yfth_fund_beneficiary_profile')->where('id', (int)$existing['id'])->update($row);
                return;
            }
            $row['uid'] = $uid;
            $row['add_time'] = $now;
            Db::name('yfth_fund_beneficiary_profile')->insert($row);
        });
        return $this->beneficiaryProfileDto($uid);
    }

    private function beneficiaryProfileDto(int $uid): array
    {
        $row = Db::name('yfth_fund_beneficiary_profile')->where([
            'uid' => $uid,
            'status' => 'active',
        ])->find();
        if (!$row) {
            return [
                'configured' => false,
                'payout_method' => 'bank',
                'receiver_name_masked' => '',
                'receiver_account_masked' => '',
                'bank_name' => '',
                'update_time' => 0,
            ];
        }
        return [
            'configured' => true,
            'payout_method' => 'bank',
            'receiver_name_masked' => (string)$row['receiver_name_masked'],
            'receiver_account_masked' => (string)$row['receiver_account_masked'],
            'bank_name' => (string)$row['bank_name'],
            'update_time' => (int)$row['update_time'],
        ];
    }

    private function beneficiaryForWithdrawal(int $uid, bool $lock): array
    {
        $query = Db::name('yfth_fund_beneficiary_profile')->where([
            'uid' => $uid,
            'status' => 'active',
        ]);
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if (!$row) {
            throw new ApiException('fund_withdrawal_beneficiary_required');
        }
        return [
            'receiver_name' => $this->decrypt((string)$row['receiver_name_enc']),
            'receiver_name_masked' => (string)$row['receiver_name_masked'],
            'receiver_account' => $this->decrypt((string)$row['receiver_account_enc']),
            'receiver_account_masked' => (string)$row['receiver_account_masked'],
            'bank_name' => (string)$row['bank_name'],
        ];
    }

    private function ownerSummary(string $ownerType, int $ownerId, string $rankCode = '', bool $lock = false): array
    {
        if ($ownerType === 'store') {
            $query = Db::name('yfth_store_commission_account')->where('store_id', $ownerId);
            $account = $lock ? $query->lock(true)->find() : $query->find();
            $unsettled = max(0, (int)($account['unsettled_cent'] ?? 0));
            $frozen = $this->requestAmount($ownerType, $ownerId, self::ACTIVE_STATUSES);
            $paid = $this->requestAmount($ownerType, $ownerId, ['paid']);
            return $this->moneySummary(max(0, $unsettled - $frozen), 0, $frozen, $paid);
        }
        $setting = $this->setting();
        $days = max(0, (int)$setting['partner_observation_days']);
        $cutoff = time() - $days * 86400;
        $allSources = $this->partnerSources($ownerId, PHP_INT_MAX, $lock);
        $eligibleSources = array_values(array_filter($allSources, function (array $row) use ($cutoff) {
            return (int)$row['effective_time'] <= $cutoff;
        }));
        $eligibleGross = array_sum(array_column($eligibleSources, 'amount_cent'));
        $eligible = array_sum(array_column($eligibleSources, 'remaining_cent'));
        $total = array_sum(array_column($allSources, 'remaining_cent'));
        $observing = max(0, $total - $eligible);
        $frozen = $this->requestAmount($ownerType, $ownerId, self::ACTIVE_STATUSES);
        $paid = $this->requestAmount($ownerType, $ownerId, ['paid']);
        return array_merge($this->moneySummary(max(0, $eligible), $observing, $frozen, $paid), [
            'rank_code' => $rankCode,
            'observation_days' => $days,
            'eligible_gross_cent' => $eligibleGross,
            'eligible_gross' => $this->money($eligibleGross),
        ]);
    }

    private function moneySummary(int $available, int $observing, int $frozen, int $paid): array
    {
        return [
            'available_cent' => $available, 'available' => $this->money($available),
            'observing_cent' => $observing, 'observing' => $this->money($observing),
            'frozen_cent' => $frozen, 'frozen' => $this->money($frozen),
            'paid_cent' => $paid, 'paid' => $this->money($paid),
        ];
    }

    private function partnerSources(int $uid, int $cutoff, bool $lock): array
    {
        $sources = [];
        $allocated = [];
        $allocationQuery = Db::name('yfth_fund_withdrawal_allocation')
            ->where(['owner_type' => 'partner', 'owner_id' => $uid])
            ->whereIn('status', ['frozen', 'paid'])
            ->field('source_type,source_id,amount_cent');
        if ($lock) {
            $allocationQuery->lock(true);
        }
        foreach ($allocationQuery->select()->toArray() as $row) {
            $key = (string)$row['source_type'] . ':' . (int)$row['source_id'];
            $allocated[$key] = (int)($allocated[$key] ?? 0) + (int)$row['amount_cent'];
        }
        $queries = [
            ['partner_reward', 'yfth_partner_reward_candidate', 'beneficiary_uid', 'confirmed', 'amount', true, 'operator_time'],
            ['procurement_profit', 'yfth_procurement_profit_ledger', 'beneficiary_uid', 'pending', 'amount_cent', false, 'create_time'],
            ['opening_reward', 'yfth_partner_opening_reward_ledger', 'partner_uid', 'pending', 'amount_cent', false, 'effective_time'],
            ['platform_dividend', 'yfth_platform_dividend_item', 'beneficiary_uid', 'pending', 'amount_cent', false, 'create_time'],
        ];
        foreach ($queries as $def) {
            [$type, $table, $ownerField, $status, $amountField, $decimal, $timeField] = $def;
            $query = Db::name($table)->where($ownerField, $uid)->where('status', $status);
            if ($cutoff !== PHP_INT_MAX) {
                $query->where($timeField, '<=', $cutoff);
            }
            if ($lock) {
                $query->lock(true);
            }
            foreach ($query->order($timeField . ' asc,id asc')->select()->toArray() as $row) {
                $amountCent = $decimal ? $this->amountToCent((string)$row[$amountField]) : (int)$row[$amountField];
                $allocatedCent = max(0, (int)($allocated[$type . ':' . (int)$row['id']] ?? 0));
                if ($amountCent <= 0 && $allocatedCent > 0) {
                    throw new ApiException('fund_withdrawal_negative_source_allocated');
                }
                if ($amountCent > 0 && $allocatedCent > $amountCent) {
                    throw new ApiException('fund_withdrawal_source_allocation_inconsistent');
                }
                $sources[] = [
                    'source_type' => $type,
                    'source_id' => (int)$row['id'],
                    'amount_cent' => $amountCent,
                    'allocated_cent' => $allocatedCent,
                    'remaining_cent' => $amountCent > 0 ? $amountCent - $allocatedCent : $amountCent,
                    'effective_time' => max(0, (int)($row[$timeField] ?: ($row['create_time'] ?? 0))),
                ];
            }
        }
        usort($sources, function (array $a, array $b) {
            return [$a['effective_time'], $a['source_id'], $a['source_type']]
                <=> [$b['effective_time'], $b['source_id'], $b['source_type']];
        });
        return $sources;
    }

    private function allocatePartnerSources(array $request, int $amountCent, int $now): void
    {
        $setting = $this->setting();
        $cutoff = time() - max(0, (int)$setting['partner_observation_days']) * 86400;
        $remaining = $amountCent;
        foreach ($this->partnerSources((int)$request['owner_id'], $cutoff, true) as $source) {
            if ($remaining <= 0 || (int)$source['amount_cent'] <= 0) {
                continue;
            }
            $used = (int)Db::name('yfth_fund_withdrawal_allocation')
                ->where(['owner_type' => 'partner', 'owner_id' => (int)$request['owner_id']])
                ->where(['source_type' => $source['source_type'], 'source_id' => (int)$source['source_id']])
                ->whereIn('status', ['frozen', 'paid'])->sum('amount_cent');
            $residual = max(0, (int)$source['amount_cent'] - $used);
            if ($residual <= 0) continue;
            $take = min($residual, $remaining);
            Db::name('yfth_fund_withdrawal_allocation')->insert([
                'request_id' => (int)$request['id'], 'owner_type' => 'partner',
                'owner_id' => (int)$request['owner_id'], 'source_type' => $source['source_type'],
                'source_id' => (int)$source['source_id'], 'amount_cent' => $take,
                'status' => 'frozen', 'add_time' => $now, 'update_time' => $now,
            ]);
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw new ApiException('fund_withdrawal_source_allocation_insufficient');
        }
    }

    private function assertRequestCovered(array $row): void
    {
        if ((string)$row['owner_type'] === 'partner') {
            $this->assertFrozenPartnerSources((int)$row['id'], (int)$row['owner_id']);
            return;
        }
        $summary = $this->ownerSummary(
            (string)$row['owner_type'],
            (int)$row['owner_id'],
            (string)$row['rank_code'],
            true
        );
        $activeTotal = $this->requestAmount(
            (string)$row['owner_type'],
            (int)$row['owner_id'],
            self::ACTIVE_STATUSES
        );
        if ((string)$row['owner_type'] === 'store') {
            $account = Db::name('yfth_store_commission_account')->where('store_id', (int)$row['owner_id'])->lock(true)->find();
            if (!$account || (int)$account['unsettled_cent'] < $activeTotal) {
                throw new ApiException('fund_withdrawal_funds_changed');
            }
            return;
        }
    }

    private function assertFrozenPartnerSources(int $requestId, int $ownerId): void
    {
        $definitions = [
            'partner_reward' => ['yfth_partner_reward_candidate', 'beneficiary_uid', 'confirmed', 'amount', true],
            'procurement_profit' => ['yfth_procurement_profit_ledger', 'beneficiary_uid', 'pending', 'amount_cent', false],
            'opening_reward' => ['yfth_partner_opening_reward_ledger', 'partner_uid', 'pending', 'amount_cent', false],
            'platform_dividend' => ['yfth_platform_dividend_item', 'beneficiary_uid', 'pending', 'amount_cent', false],
        ];
        $allocations = Db::name('yfth_fund_withdrawal_allocation')
            ->where('request_id', $requestId)
            ->where('status', 'frozen')
            ->lock(true)
            ->select()
            ->toArray();
        if (!$allocations) {
            throw new ApiException('fund_withdrawal_funds_changed');
        }
        foreach ($allocations as $allocation) {
            $sourceType = (string)$allocation['source_type'];
            if (!isset($definitions[$sourceType])) {
                throw new ApiException('fund_withdrawal_funds_changed');
            }
            [$table, $ownerField, $status, $amountField, $decimal] = $definitions[$sourceType];
            $source = Db::name($table)
                ->where('id', (int)$allocation['source_id'])
                ->where($ownerField, $ownerId)
                ->where('status', $status)
                ->lock(true)
                ->find();
            if (!$source) {
                throw new ApiException('fund_withdrawal_funds_changed');
            }
            $sourceCent = $decimal
                ? $this->amountToCent((string)$source[$amountField])
                : (int)$source[$amountField];
            $allocatedCent = (int)Db::name('yfth_fund_withdrawal_allocation')
                ->where(['owner_type' => 'partner', 'owner_id' => $ownerId])
                ->where(['source_type' => $sourceType, 'source_id' => (int)$allocation['source_id']])
                ->whereIn('status', ['frozen', 'paid'])
                ->sum('amount_cent');
            if ($sourceCent <= 0 || $allocatedCent > $sourceCent) {
                throw new ApiException('fund_withdrawal_funds_changed');
            }
        }
    }

    private function payStoreRequest(array $row, int $adminId, int $now): void
    {
        $storeId = (int)$row['owner_id'];
        $amount = (int)$row['amount_cent'];
        $account = Db::name('yfth_store_commission_account')->where('store_id', $storeId)->lock(true)->find();
        if (!$account || (int)$account['unsettled_cent'] < $amount) {
            throw new ApiException('fund_withdrawal_store_account_inconsistent');
        }
        $before = (int)$account['unsettled_cent'];
        $after = $before - $amount;
        Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
            'unsettled_cent' => $after,
            'settled_cent' => (int)$account['settled_cent'] + $amount,
            'version' => (int)$account['version'] + 1,
            'update_time' => $now,
        ]);
        $unique = hash('sha256', 'store-manual-withdrawal:' . (int)$row['id']);
        $exists = Db::name('yfth_commission_ledger')->where('source_unique_key', $unique)->find();
        if (!$exists) {
            Db::name('yfth_commission_ledger')->insert([
                'ledger_no' => 'YFCL' . date('YmdHis') . str_pad((string)$row['id'], 10, '0', STR_PAD_LEFT),
                'account_type' => 'store', 'account_id' => $storeId, 'bucket' => 'b1_commission',
                'direction' => 'debit', 'amount_cent' => $amount,
                'balance_before_cent' => $before, 'balance_after_cent' => $after,
                'available_after_cent' => $after, 'frozen_after_cent' => 0,
                'withdrawn_after_cent' => (int)$account['settled_cent'] + $amount,
                'source_type' => 'store_manual_withdrawal_paid', 'source_id' => (string)$row['id'],
                'source_order_id' => 0, 'source_order_item_id' => '', 'rule_version_id' => 0,
                'c1_ratio_bps' => 0, 'b1_ratio_bps' => 0, 'reverse_ledger_id' => 0,
                'source_unique_key' => $unique, 'reason' => '总部确认线下银行打款',
                'snapshot_json' => json_encode(['withdrawal_request_no' => $row['request_no']], JSON_UNESCAPED_UNICODE),
                'operator_uid' => $adminId, 'add_time' => $now,
            ]);
        }
    }

    private function settlePaidPartnerSources(int $requestId, int $ownerId, int $adminId, int $now): void
    {
        $definitions = [
            'partner_reward' => [
                'table' => 'yfth_partner_reward_candidate',
                'owner_field' => 'beneficiary_uid',
                'source_status' => 'confirmed',
                'amount_field' => 'amount',
                'decimal' => true,
                'update' => [
                    'status' => 'settled',
                    'operator_uid' => $adminId,
                    'operator_time' => $now,
                    'remark' => '总部确认线下打款',
                    'update_time' => $now,
                ],
            ],
            'procurement_profit' => [
                'table' => 'yfth_procurement_profit_ledger',
                'owner_field' => 'beneficiary_uid',
                'source_status' => 'pending',
                'amount_field' => 'amount_cent',
                'decimal' => false,
                'update' => ['status' => 'settled', 'settled_time' => $now, 'update_time' => $now],
            ],
            'opening_reward' => [
                'table' => 'yfth_partner_opening_reward_ledger',
                'owner_field' => 'partner_uid',
                'source_status' => 'pending',
                'amount_field' => 'amount_cent',
                'decimal' => false,
                'update' => ['status' => 'settled', 'update_time' => $now],
            ],
            'platform_dividend' => [
                'table' => 'yfth_platform_dividend_item',
                'owner_field' => 'beneficiary_uid',
                'source_status' => 'pending',
                'amount_field' => 'amount_cent',
                'decimal' => false,
                'update' => ['status' => 'settled', 'update_time' => $now],
            ],
        ];
        $allocations = Db::name('yfth_fund_withdrawal_allocation')
            ->where(['request_id' => $requestId, 'owner_type' => 'partner', 'owner_id' => $ownerId])
            ->where('status', 'paid')
            ->lock(true)
            ->select()
            ->toArray();
        if (!$allocations) {
            throw new ApiException('fund_withdrawal_paid_allocation_missing');
        }
        foreach ($allocations as $allocation) {
            $sourceType = (string)$allocation['source_type'];
            if (!isset($definitions[$sourceType])) {
                throw new ApiException('fund_withdrawal_source_type_invalid');
            }
            $definition = $definitions[$sourceType];
            $source = Db::name($definition['table'])
                ->where('id', (int)$allocation['source_id'])
                ->where($definition['owner_field'], $ownerId)
                ->lock(true)
                ->find();
            if (!$source) {
                throw new ApiException('fund_withdrawal_source_missing');
            }
            $sourceCent = $definition['decimal']
                ? $this->amountToCent((string)$source[$definition['amount_field']])
                : (int)$source[$definition['amount_field']];
            $paidCent = (int)Db::name('yfth_fund_withdrawal_allocation')
                ->where(['owner_type' => 'partner', 'owner_id' => $ownerId])
                ->where(['source_type' => $sourceType, 'source_id' => (int)$allocation['source_id']])
                ->where('status', 'paid')
                ->sum('amount_cent');
            if ($sourceCent <= 0 || $paidCent > $sourceCent) {
                throw new ApiException('fund_withdrawal_source_allocation_inconsistent');
            }
            if ((string)$source['status'] === 'settled') {
                continue;
            }
            if ((string)$source['status'] !== $definition['source_status']) {
                throw new ApiException('fund_withdrawal_source_status_inconsistent');
            }
            if ($paidCent === $sourceCent) {
                Db::name($definition['table'])->where('id', (int)$source['id'])->update($definition['update']);
            }
        }
    }

    private function activePartner(int $uid): array
    {
        $profile = $uid > 0 ? Db::name('yfth_partner_profile')->where([
            'uid' => $uid, 'status' => 'active', 'qualification_status' => 'effective',
        ])->find() : null;
        if (!$profile || !in_array((string)$profile['rank_code'], self::PARTNER_RANKS, true)) {
            throw new ApiException('partner_not_found');
        }
        return $profile;
    }

    private function assertStoreApplicant(array $context): int
    {
        if ((string)($context['role_code'] ?? '') !== 'store_manager' || (int)($context['store_id'] ?? 0) <= 0) {
            throw new ApiException('store_withdrawal_manager_required');
        }
        return (int)$context['store_id'];
    }

    private function assertStoreReader(array $context): int
    {
        if (!in_array((string)($context['role_code'] ?? ''), ['store_manager', 'store_staff'], true)
            || (int)($context['store_id'] ?? 0) <= 0) {
            throw new ApiException('store_withdrawal_scope_forbidden');
        }
        return (int)$context['store_id'];
    }

    private function ownerRequests(string $ownerType, int $ownerId, array $where): array
    {
        [$page, $limit] = $this->paging($where);
        $query = Db::name('yfth_fund_withdrawal_request')->where([
            'owner_type' => $ownerType, 'owner_id' => $ownerId,
        ]);
        if (!empty($where['status'])) $query->where('status', trim((string)$where['status']));
        $count = (int)(clone $query)->count();
        $rows = $query->page($page, $limit)->order('id desc')->select()->toArray();
        foreach ($rows as &$row) $row = $this->requestDto($row, false);
        return ['list' => $rows, 'count' => $count];
    }

    private function requestAmount(string $ownerType, int $ownerId, array $statuses): int
    {
        return (int)Db::name('yfth_fund_withdrawal_request')->where([
            'owner_type' => $ownerType, 'owner_id' => $ownerId,
        ])->whereIn('status', $statuses)->sum('amount_cent');
    }

    private function requestRow(int $id, bool $lock = false): array
    {
        $query = Db::name('yfth_fund_withdrawal_request')->where('id', $id);
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if (!$row) throw new ApiException('fund_withdrawal_request_not_found');
        return $row;
    }

    private function requestDto(array $row, bool $sensitive): array
    {
        foreach (['receiver_name_enc', 'receiver_account_enc'] as $field) {
            unset($row[$field]);
        }
        if (!$sensitive) {
            unset($row['receiver_name'], $row['receiver_account'], $row['allocations']);
        }
        $row['amount'] = $this->money((int)($row['amount_cent'] ?? 0));
        $row['applicant_phone_masked'] = $this->maskPhone((string)($row['phone'] ?? ''));
        unset($row['phone']);
        return $row;
    }

    private function setting(): array
    {
        $setting = Db::name('yfth_fund_withdrawal_setting')->order('id asc')->find();
        if (!$setting) throw new ApiException('fund_withdrawal_setting_missing');
        return $setting;
    }

    private function encrypt(string $value): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) throw new ApiException('fund_withdrawal_sensitive_encrypt_failed');
        return base64_encode($iv . $encrypted);
    }

    private function decrypt(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) <= 16) throw new ApiException('fund_withdrawal_sensitive_decrypt_failed');
        $plain = openssl_decrypt(
            substr($decoded, 16), 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, substr($decoded, 0, 16)
        );
        if ($plain === false) throw new ApiException('fund_withdrawal_sensitive_decrypt_failed');
        return $plain;
    }

    private function encryptionKey(): string
    {
        $key = trim((string)Env::get('yfth.settlement_key', ''));
        if ($key === '') $key = trim((string)getenv('YFTH_SETTLEMENT_KEY'));
        if ($key === '') $key = trim((string)Env::get('app.app_key', ''));
        if ($key === '' || $key === 'default') throw new ApiException('fund_withdrawal_encryption_key_missing');
        return hash('sha256', $key, true);
    }

    private function maskName(string $value): string
    {
        $length = mb_strlen($value);
        return $length <= 1 ? '*' : mb_substr($value, 0, 1) . str_repeat('*', max(1, $length - 1));
    }

    private function maskAccount(string $value): string
    {
        $length = strlen($value);
        return $length <= 8 ? str_repeat('*', max(4, $length)) : substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
    }

    private function maskPhone(string $value): string
    {
        return preg_match('/^(\d{3})\d{4}(\d{4})$/', $value, $m) ? $m[1] . '****' . $m[2] : '';
    }

    private function amountToCent(string $value): int
    {
        return (int)round((float)$value * 100);
    }

    private function money(int $cent): string
    {
        return number_format($cent / 100, 2, '.', '');
    }

    private function paging(array $where): array
    {
        return [max(1, (int)($where['page'] ?? 1)), min(100, max(1, (int)($where['limit'] ?? 20)))];
    }
}
