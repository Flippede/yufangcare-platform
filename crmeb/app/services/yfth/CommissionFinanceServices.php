<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;
use think\facade\Env;

/**
 * Restricted commission balances and offline withdrawal facts.
 *
 * These balances reuse CRMEB users and stores, but are intentionally not
 * mirrored to now_money/brokerage_price because those fields are spendable.
 */
class CommissionFinanceServices
{
    public function userSummary(int $uid): array
    {
        $account = $this->userAccount($uid);
        $observingCent = (int)Db::name('yfth_commission_accrual')->where('c1_uid', $uid)
            ->where('status', 'observing')->sum('c1_amount_cent');
        return [
            'account' => $this->moneyDto($account, ['available_cent', 'frozen_cent', 'withdrawn_cent']),
            'observing_cent' => $observingCent,
            'observing' => number_format($observingCent / 100, 2, '.', ''),
            'notice' => '御方通和推荐收益仅可提现至责任门店，不可用于商城支付。',
        ];
    }

    public function userLedger(int $uid, array $where = []): array
    {
        return $this->ledgerPage('user', $uid, $where);
    }

    public function userWithdrawals(int $uid, array $where = []): array
    {
        $query = Db::name('yfth_c1_withdrawal')->where('uid', $uid);
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        return $this->page($query, $where, function (array $row) {
            return $this->moneyDto($row, ['amount_cent']);
        });
    }

    public function requestUserWithdrawal(int $uid, int $amountCent, string $requestId): array
    {
        if ($uid <= 0 || $amountCent <= 0 || trim($requestId) === '') {
            throw new ApiException('commission_withdrawal_request_invalid');
        }
        return Db::transaction(function () use ($uid, $amountCent, $requestId) {
            $existing = $this->row(Db::name('yfth_c1_withdrawal')->where([
                'uid' => $uid, 'request_id' => $requestId,
            ])->lock(true)->find());
            if ($existing) return $this->moneyDto($existing, ['amount_cent']);

            $attribution = $this->row(Db::name('yfth_hq_customer_attribution_current')->where([
                'uid' => $uid, 'status' => 'active',
            ])->lock(true)->find());
            $storeId = (int)($attribution['store_id'] ?? 0);
            if ($storeId <= 0) throw new ApiException('commission_responsible_store_missing');

            $account = $this->lockUserAccount($uid);
            if ((int)$account['available_cent'] <= 0 || (int)$account['available_cent'] < $amountCent) {
                throw new ApiException('commission_balance_insufficient');
            }
            $now = time();
            $row = [
                'withdrawal_no' => $this->makeNo('YFCW'), 'uid' => $uid, 'store_id' => $storeId,
                'amount_cent' => $amountCent, 'status' => 'pending', 'offline_ref_no' => '',
                'proof_ref' => '', 'remark' => '', 'request_id' => $requestId,
                'operator_uid' => 0, 'completed_at' => 0, 'add_time' => $now, 'update_time' => $now,
            ];
            $row['id'] = (int)Db::name('yfth_c1_withdrawal')->insertGetId($row);
            $this->moveUserToFrozen($account, $amountCent, $row);
            $this->audit('c1_withdrawal', (string)$row['id'], 'request', [], $row, $uid, 'customer', $storeId, '', $requestId);
            return $this->moneyDto($row, ['amount_cent']);
        });
    }

    public function storeSummary(array $context): array
    {
        $storeId = $this->assertStoreContext($context, false);
        $account = $this->storeAccount($storeId);
        $account['hq_withdrawable_cent'] = max(0, (int)$account['own_available_cent'] + (int)$account['proxy_available_cent']);
        $settlement = $this->maskedSettlementAccount($storeId);
        return [
            'store_id' => $storeId,
            'account' => $this->moneyDto($account, [
                'own_available_cent', 'proxy_available_cent', 'hq_withdrawable_cent', 'hq_frozen_cent',
                'hq_withdrawn_cent', 'c1_pending_cent', 'c1_paid_cent', 'reversed_cent',
            ]),
            'settlement_account' => $settlement,
            'notice' => '系统只记录线下付款和总部提现事实，不代表平台自动打款。',
        ];
    }

    public function storeLedger(array $context, array $where = []): array
    {
        return $this->ledgerPage('store', $this->assertStoreContext($context, false), $where);
    }

    public function storeUserWithdrawals(array $context, array $where = []): array
    {
        $storeId = $this->assertStoreContext($context, false);
        $query = Db::name('yfth_c1_withdrawal')->where('store_id', $storeId);
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        return $this->page($query, $where, function (array $row) {
            $user = $this->row(Db::name('user')->where('uid', (int)$row['uid'])->field('uid,nickname,avatar,phone')->find());
            $row['user'] = [
                'uid' => (int)($user['uid'] ?? 0), 'nickname' => (string)($user['nickname'] ?? ''),
                'avatar' => (string)($user['avatar'] ?? ''), 'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            ];
            return $this->moneyDto($row, ['amount_cent']);
        });
    }

    public function completeUserWithdrawal(array $context, int $id, array $data): array
    {
        $storeId = $this->assertStoreContext($context, false);
        $requestId = trim((string)($data['request_id'] ?? ''));
        if ($requestId === '') throw new ApiException('idempotency_key_required');
        return Db::transaction(function () use ($context, $storeId, $id, $data, $requestId) {
            $row = $this->row(Db::name('yfth_c1_withdrawal')->where(['id' => $id, 'store_id' => $storeId])->lock(true)->find());
            if (!$row) throw new ApiException('c1_withdrawal_not_found');
            if ((string)$row['status'] === 'paid') return $this->moneyDto($row, ['amount_cent']);
            if ((string)$row['status'] !== 'pending') throw new ApiException('c1_withdrawal_status_invalid');

            $user = $this->lockUserAccount((int)$row['uid']);
            $amount = (int)$row['amount_cent'];
            if ((int)$user['frozen_cent'] < $amount) throw new ApiException('c1_withdrawal_frozen_inconsistent');
            $now = time();
            $update = [
                'status' => 'paid', 'offline_ref_no' => substr(trim((string)($data['offline_ref_no'] ?? '')), 0, 128),
                'proof_ref' => substr(trim((string)($data['proof_ref'] ?? '')), 0, 255),
                'remark' => substr(trim((string)($data['remark'] ?? '')), 0, 255),
                'operator_uid' => (int)$context['uid'], 'completed_at' => $now, 'update_time' => $now,
            ];
            Db::name('yfth_c1_withdrawal')->where('id', $id)->update($update);
            Db::name('yfth_user_commission_account')->where('id', (int)$user['id'])->update([
                'frozen_cent' => (int)$user['frozen_cent'] - $amount,
                'withdrawn_cent' => (int)$user['withdrawn_cent'] + $amount,
                'version' => (int)$user['version'] + 1, 'update_time' => $now,
            ]);
            $this->insertTransferLedger('user', (int)$row['uid'], 'c1_commission', $amount,
                (int)$user['available_cent'], (int)$user['frozen_cent'] - $amount,
                (int)$user['withdrawn_cent'] + $amount, 'c1_withdrawal_paid', (string)$id,
                'c1-paid:' . $id, (int)$context['uid'], $update);
            $this->increaseStoreCounter($storeId, 'c1_paid_cent', $amount);
            $this->syncStoreC1Pending($storeId);
            $after = array_merge($row, $update);
            $this->audit('c1_withdrawal', (string)$id, 'offline_paid', $row, $after,
                (int)$context['uid'], (string)$context['role_code'], $storeId, (string)$update['remark'], $requestId);
            return $this->moneyDto($after, ['amount_cent']);
        });
    }

    public function saveSettlementAccount(array $context, array $data): array
    {
        $storeId = $this->assertStoreContext($context, true);
        $type = (string)($data['account_type'] ?? 'personal');
        $name = trim((string)($data['account_name'] ?? ''));
        $number = preg_replace('/\s+/', '', trim((string)($data['account_no'] ?? '')));
        $bank = trim((string)($data['bank_name'] ?? ''));
        $branch = trim((string)($data['bank_branch'] ?? ''));
        $reservedPhone = preg_replace('/\s+/', '', trim((string)($data['reserved_phone'] ?? '')));
        $contactName = trim((string)($data['contact_name'] ?? ''));
        $contactPhone = preg_replace('/\s+/', '', trim((string)($data['contact_phone'] ?? '')));
        if (!in_array($type, ['personal', 'company'], true) || $name === '' || strlen($number) < 6
            || $bank === '' || $branch === '' || $contactName === '' || strlen($contactPhone) < 7) {
            throw new ApiException('settlement_account_invalid');
        }
        return Db::transaction(function () use ($context, $storeId, $type, $name, $number, $bank, $branch, $reservedPhone, $contactName, $contactPhone) {
            $existing = $this->row(Db::name('yfth_store_settlement_account')->where(['store_id' => $storeId, 'is_default' => 1])->lock(true)->find());
            $now = time();
            $values = [
                'account_type' => $type, 'account_name_enc' => $this->encrypt($name),
                'account_no_enc' => $this->encrypt($number), 'bank_name_enc' => $this->encrypt($bank),
                'bank_branch_enc' => $this->encrypt($branch),
                'reserved_phone_enc' => $this->encrypt($reservedPhone),
                'contact_name_enc' => $this->encrypt($contactName),
                'contact_phone_enc' => $this->encrypt($contactPhone),
                'account_no_masked' => $this->maskAccount($number), 'is_default' => 1,
                'status' => 'active', 'operator_uid' => (int)$context['uid'], 'update_time' => $now,
            ];
            if ($existing) {
                Db::name('yfth_store_settlement_account')->where('id', (int)$existing['id'])->update($values);
                $id = (int)$existing['id'];
            } else {
                $values['store_id'] = $storeId; $values['add_time'] = $now;
                $id = (int)Db::name('yfth_store_settlement_account')->insertGetId($values);
            }
            $after = ['id' => $id, 'store_id' => $storeId, 'account_type' => $type,
                'account_name_masked' => $this->maskName($name), 'account_no_masked' => $this->maskAccount($number),
                'bank_name' => $bank, 'bank_branch' => $branch,
                'reserved_phone_masked' => $this->maskPhone($reservedPhone),
                'contact_name_masked' => $this->maskName($contactName),
                'contact_phone_masked' => $this->maskPhone($contactPhone), 'status' => 'active'];
            $this->audit('store_settlement_account', (string)$id, 'save', [], $after,
                (int)$context['uid'], (string)$context['role_code'], $storeId);
            return $after;
        });
    }

    public function storeWithdrawals(array $context, array $where = []): array
    {
        $storeId = $this->assertStoreContext($context, false);
        $query = Db::name('yfth_store_withdrawal')->where('store_id', $storeId);
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        return $this->page($query, $where, function (array $row) {
            unset($row['settlement_snapshot_json']);
            return $this->moneyDto($row, ['amount_cent', 'own_amount_cent', 'proxy_amount_cent']);
        });
    }

    public function requestStoreWithdrawal(array $context, int $amountCent, string $requestId): array
    {
        $storeId = $this->assertStoreContext($context, true);
        if ($amountCent <= 0 || trim($requestId) === '') throw new ApiException('store_withdrawal_request_invalid');
        return $this->runWithDeadlockRetry(function () use ($context, $storeId, $amountCent, $requestId) {
            return Db::transaction(function () use ($context, $storeId, $amountCent, $requestId) {
            $existing = $this->row(Db::name('yfth_store_withdrawal')->where([
                'store_id' => $storeId, 'request_id' => $requestId,
            ])->lock(true)->find());
            if ($existing) return $this->storeWithdrawalDto($existing);

            $account = $this->lockStoreAccount($storeId);
            $withdrawable = max(0, (int)$account['own_available_cent'] + (int)$account['proxy_available_cent']);
            if ($amountCent > $withdrawable) throw new ApiException('store_withdrawal_balance_insufficient');
            $settlement = $this->settlementAccountForWithdrawal($storeId);
            $credits = Db::name('yfth_commission_ledger')->where([
                'account_type' => 'store', 'account_id' => $storeId, 'direction' => 'credit',
            ])->whereIn('bucket', ['store_own', 'store_proxy'])
                ->where('remaining_withdrawable_cent', '>', 0)->order('id asc')->lock(true)->select()->toArray();
            $remaining = $amountCent; $allocations = []; $own = 0; $proxy = 0;
            foreach ($credits as $credit) {
                if ($remaining <= 0) break;
                $used = min($remaining, max(0, (int)$credit['remaining_withdrawable_cent']));
                if ($used <= 0) continue;
                $allocations[] = ['ledger_id' => (int)$credit['id'], 'bucket' => (string)$credit['bucket'], 'amount_cent' => $used];
                if ((string)$credit['bucket'] === 'store_own') $own += $used; else $proxy += $used;
                $remaining -= $used;
            }
            if ($remaining > 0) throw new ApiException('store_withdrawal_fifo_inconsistent');
            $now = time();
            $row = [
                'withdrawal_no' => $this->makeNo('YFSW'), 'store_id' => $storeId,
                'amount_cent' => $amountCent, 'own_amount_cent' => $own, 'proxy_amount_cent' => $proxy,
                'status' => 'reviewing', 'settlement_account_id' => (int)$settlement['id'],
                'settlement_snapshot_json' => $this->json($settlement['snapshot']), 'request_id' => $requestId,
                'operator_uid' => (int)$context['uid'], 'admin_uid' => 0, 'remark' => '',
                'completed_at' => 0, 'add_time' => $now, 'update_time' => $now,
            ];
            $row['id'] = (int)Db::name('yfth_store_withdrawal')->insertGetId($row);
            foreach ($allocations as $allocation) {
                $allocation['withdrawal_id'] = (int)$row['id']; $allocation['add_time'] = $now;
                Db::name('yfth_withdrawal_allocation')->insert($allocation);
                Db::name('yfth_commission_ledger')->where('id', (int)$allocation['ledger_id'])->dec(
                    'remaining_withdrawable_cent', (int)$allocation['amount_cent'])->update();
            }
            Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
                'own_available_cent' => (int)$account['own_available_cent'] - $own,
                'proxy_available_cent' => (int)$account['proxy_available_cent'] - $proxy,
                'hq_frozen_cent' => (int)$account['hq_frozen_cent'] + $amountCent,
                'version' => (int)$account['version'] + 1, 'update_time' => $now,
            ]);
            $this->insertLedger('store', $storeId, 'hq_withdrawal', -$amountCent,
                $withdrawable - $amountCent, 'store_withdrawal_request', (string)$row['id'],
                'store-withdrawal:' . $row['id'], (int)$context['uid'], [
                    'own_cent' => $own,
                    'proxy_cent' => $proxy,
                    '_projection' => [
                        'available_cent' => $withdrawable - $amountCent,
                        'frozen_cent' => (int)$account['hq_frozen_cent'] + $amountCent,
                        'withdrawn_cent' => (int)$account['hq_withdrawn_cent'],
                    ],
                ]);
            $this->audit('store_withdrawal', (string)$row['id'], 'request', [], $row,
                (int)$context['uid'], (string)$context['role_code'], $storeId, '', $requestId);
                return $this->storeWithdrawalDto($row);
            });
        });
    }

    public function headquartersWithdrawals(array $where = []): array
    {
        $query = Db::name('yfth_store_withdrawal');
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        if (!empty($where['store_id'])) $query->where('store_id', (int)$where['store_id']);
        return $this->page($query, $where, function (array $row) {
            return $this->headquartersWithdrawalDto($row);
        });
    }

    public function headquartersLedger(array $where = []): array
    {
        $query = Db::name('yfth_commission_ledger');
        foreach (['account_type', 'bucket', 'source_type'] as $field) {
            if (($where[$field] ?? '') !== '') $query->where($field, (string)$where[$field]);
        }
        if ((int)($where['account_id'] ?? 0) > 0) $query->where('account_id', (int)$where['account_id']);
        return $this->page($query, $where, function (array $row) {
            unset($row['snapshot_json'], $row['source_unique_key']);
            return $this->moneyDto($row, [
                'amount_cent', 'balance_before_cent', 'balance_after_cent', 'available_after_cent',
                'frozen_after_cent', 'withdrawn_after_cent', 'remaining_withdrawable_cent',
            ]);
        });
    }

    public function completeStoreWithdrawal(int $id, int $adminUid, string $remark): array
    {
        return Db::transaction(function () use ($id, $adminUid, $remark) {
            $row = $this->row(Db::name('yfth_store_withdrawal')->where('id', $id)->lock(true)->find());
            if (!$row) throw new ApiException('store_withdrawal_not_found');
            if ((string)$row['status'] === 'success') return $this->storeWithdrawalDto($row);
            if ((string)$row['status'] !== 'reviewing') throw new ApiException('store_withdrawal_status_invalid');
            $account = $this->lockStoreAccount((int)$row['store_id']);
            $amount = (int)$row['amount_cent'];
            if ((int)$account['hq_frozen_cent'] < $amount) throw new ApiException('store_withdrawal_frozen_inconsistent');
            $now = time();
            $update = ['status' => 'success', 'admin_uid' => $adminUid,
                'remark' => substr(trim($remark), 0, 255), 'completed_at' => $now, 'update_time' => $now];
            Db::name('yfth_store_withdrawal')->where('id', $id)->update($update);
            Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
                'hq_frozen_cent' => (int)$account['hq_frozen_cent'] - $amount,
                'hq_withdrawn_cent' => (int)$account['hq_withdrawn_cent'] + $amount,
                'version' => (int)$account['version'] + 1, 'update_time' => $now,
            ]);
            $this->insertTransferLedger('store', (int)$row['store_id'], 'hq_withdrawal', $amount,
                max(0, (int)$account['own_available_cent'] + (int)$account['proxy_available_cent']),
                (int)$account['hq_frozen_cent'] - $amount, (int)$account['hq_withdrawn_cent'] + $amount,
                'store_withdrawal_success', (string)$id, 'store-withdrawal-success:' . $id, $adminUid, $update);
            $after = array_merge($row, $update);
            $this->audit('store_withdrawal', (string)$id, 'success', $row, $after,
                $adminUid, 'headquarter_operator', (int)$row['store_id'], $remark);
            return $this->headquartersWithdrawalDto($after);
        });
    }

    public function adjustUser(int $uid, int $deltaCent, int $adminUid, string $reason, string $requestId): array
    {
        if ($uid <= 0 || $deltaCent === 0 || mb_strlen(trim($reason)) < 4 || $requestId === '') {
            throw new ApiException('commission_adjustment_invalid');
        }
        return Db::transaction(function () use ($uid, $deltaCent, $adminUid, $reason, $requestId) {
            $account = $this->lockUserAccount($uid);
            $key = 'hq-user-adjust:' . $uid . ':' . $requestId;
            if (Db::name('yfth_commission_ledger')->where('source_unique_key', hash('sha256', $key))->find()) {
                return $this->userSummary($uid);
            }
            $after = (int)$account['available_cent'] + $deltaCent;
            Db::name('yfth_user_commission_account')->where('id', (int)$account['id'])->update([
                'available_cent' => $after, 'version' => (int)$account['version'] + 1, 'update_time' => time(),
            ]);
            $this->insertLedger('user', $uid, 'c1_commission', $deltaCent, $after,
                'hq_manual_adjustment', $requestId, $key, $adminUid, [
                    'reason' => $reason,
                    '_projection' => [
                        'available_cent' => $after,
                        'frozen_cent' => (int)$account['frozen_cent'],
                        'withdrawn_cent' => (int)$account['withdrawn_cent'],
                    ],
                ]);
            $this->audit('commission_account', 'user:' . $uid, 'adjust', $account, ['available_cent' => $after],
                $adminUid, 'headquarter_operator', 0, $reason, $requestId);
            $storeId = (int)Db::name('yfth_hq_customer_attribution_current')->where([
                'uid' => $uid, 'status' => 'active',
            ])->value('store_id');
            if ($storeId > 0) $this->syncStoreC1Pending($storeId);
            return $this->userSummary($uid);
        });
    }

    public function adjustStore(int $storeId, string $bucket, int $deltaCent, int $adminUid, string $reason, string $requestId): array
    {
        if ($storeId <= 0 || !in_array($bucket, ['store_own', 'store_proxy'], true)
            || $deltaCent === 0 || mb_strlen(trim($reason)) < 4 || $requestId === '') {
            throw new ApiException('commission_adjustment_invalid');
        }
        return Db::transaction(function () use ($storeId, $bucket, $deltaCent, $adminUid, $reason, $requestId) {
            $account = $this->lockStoreAccount($storeId);
            $field = $bucket === 'store_own' ? 'own_available_cent' : 'proxy_available_cent';
            $key = 'hq-store-adjust:' . $storeId . ':' . $bucket . ':' . $requestId;
            if (Db::name('yfth_commission_ledger')->where('source_unique_key', hash('sha256', $key))->find()) {
                return $this->moneyDto($this->storeAccount($storeId), ['own_available_cent', 'proxy_available_cent']);
            }
            $after = (int)$account[$field] + $deltaCent;
            Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
                $field => $after,
                'version' => (int)$account['version'] + 1, 'update_time' => time(),
            ]);
            if ($deltaCent < 0) $this->consumeLots($storeId, $bucket, abs($deltaCent));
            $ledgerId = $this->insertLedger('store', $storeId, $bucket, $deltaCent, $after,
                'hq_manual_adjustment', $requestId, $key, $adminUid, [
                    'reason' => $reason,
                    '_projection' => [
                        'available_cent' => $after,
                        'frozen_cent' => (int)$account['hq_frozen_cent'],
                        'withdrawn_cent' => (int)$account['hq_withdrawn_cent'],
                    ],
                ]);
            if ($deltaCent > 0) Db::name('yfth_commission_ledger')->where('id', $ledgerId)->update(['remaining_withdrawable_cent' => $deltaCent]);
            $this->audit('commission_account', 'store:' . $storeId, 'adjust', $account, [$field => $after],
                $adminUid, 'headquarter_operator', $storeId, $reason, $requestId);
            return $this->moneyDto($this->storeAccount($storeId), ['own_available_cent', 'proxy_available_cent']);
        });
    }

    private function moveUserToFrozen(array $account, int $amount, array $withdrawal): void
    {
        Db::name('yfth_user_commission_account')->where('id', (int)$account['id'])->update([
            'available_cent' => (int)$account['available_cent'] - $amount,
            'frozen_cent' => (int)$account['frozen_cent'] + $amount,
            'version' => (int)$account['version'] + 1, 'update_time' => time(),
        ]);
        $this->insertLedger('user', (int)$account['uid'], 'c1_commission', -$amount,
            (int)$account['available_cent'] - $amount, 'c1_withdrawal_request', (string)$withdrawal['id'],
            'c1-request:' . $withdrawal['id'], (int)$account['uid'], array_merge($withdrawal, [
                '_projection' => [
                    'available_cent' => (int)$account['available_cent'] - $amount,
                    'frozen_cent' => (int)$account['frozen_cent'] + $amount,
                    'withdrawn_cent' => (int)$account['withdrawn_cent'],
                ],
            ]));
    }

    private function increaseStoreCounter(int $storeId, string $field, int $delta): void
    {
        $account = $this->lockStoreAccount($storeId);
        Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
            $field => (int)$account[$field] + $delta,
            'version' => (int)$account['version'] + 1, 'update_time' => time(),
        ]);
    }

    private function syncStoreC1Pending(int $storeId): void
    {
        $summary = $this->row(Db::name('yfth_user_commission_account')->alias('a')
            ->join('yfth_hq_customer_attribution_current c', 'c.uid = a.uid')
            ->where(['c.store_id' => $storeId, 'c.status' => 'active'])
            ->fieldRaw('COALESCE(SUM(GREATEST(a.available_cent + a.frozen_cent, 0)), 0) AS pending_cent')
            ->find());
        $pending = (int)($summary['pending_cent'] ?? 0);
        $account = $this->lockStoreAccount($storeId);
        Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
            'c1_pending_cent' => $pending, 'version' => (int)$account['version'] + 1, 'update_time' => time(),
        ]);
    }

    private function insertLedger(string $type, int $accountId, string $bucket, int $delta, int $after,
                                  string $sourceType, string $sourceId, string $unique, int $operatorUid, array $snapshot): int
    {
        $key = hash('sha256', $unique);
        $existing = (int)Db::name('yfth_commission_ledger')->where('source_unique_key', $key)->value('id');
        if ($existing > 0) return $existing;
        $projection = (array)($snapshot['_projection'] ?? []);
        $meta = (array)($snapshot['_ledger'] ?? []);
        return (int)Db::name('yfth_commission_ledger')->insertGetId([
            'ledger_no' => $this->makeNo('YFCL'), 'account_type' => $type, 'account_id' => $accountId,
            'bucket' => $bucket, 'direction' => $delta >= 0 ? 'credit' : 'debit',
            'amount_cent' => abs($delta), 'balance_before_cent' => $after - $delta,
            'balance_after_cent' => $after,
            'available_after_cent' => (int)($projection['available_cent'] ?? $after),
            'frozen_after_cent' => (int)($projection['frozen_cent'] ?? 0),
            'withdrawn_after_cent' => (int)($projection['withdrawn_cent'] ?? 0),
            'remaining_withdrawable_cent' => 0,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'source_unique_key' => $key,
            'source_order_id' => (int)($meta['source_order_id'] ?? 0),
            'source_order_item_id' => (string)($meta['source_order_item_id'] ?? ''),
            'rule_version_id' => (int)($meta['rule_version_id'] ?? 0),
            'c1_ratio_bps' => (int)($meta['c1_ratio_bps'] ?? 0),
            'b1_ratio_bps' => (int)($meta['b1_ratio_bps'] ?? 0),
            'reverse_ledger_id' => (int)($meta['reverse_ledger_id'] ?? 0),
            'reason' => substr((string)($snapshot['reason'] ?? $sourceType), 0, 255),
            'snapshot_json' => $this->json($snapshot), 'operator_uid' => $operatorUid, 'add_time' => time(),
        ]);
    }

    private function insertTransferLedger(string $type, int $accountId, string $bucket, int $amount,
                                          int $availableAfter, int $frozenAfter, int $withdrawnAfter,
                                          string $sourceType, string $sourceId, string $unique,
                                          int $operatorUid, array $snapshot): int
    {
        $key = hash('sha256', $unique);
        $existing = (int)Db::name('yfth_commission_ledger')->where('source_unique_key', $key)->value('id');
        if ($existing > 0) return $existing;
        return (int)Db::name('yfth_commission_ledger')->insertGetId([
            'ledger_no' => $this->makeNo('YFCL'), 'account_type' => $type, 'account_id' => $accountId,
            'bucket' => $bucket, 'direction' => 'transfer', 'amount_cent' => $amount,
            'balance_before_cent' => $availableAfter, 'balance_after_cent' => $availableAfter,
            'available_after_cent' => $availableAfter, 'frozen_after_cent' => $frozenAfter,
            'withdrawn_after_cent' => $withdrawnAfter, 'remaining_withdrawable_cent' => 0,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'source_order_id' => 0,
            'source_order_item_id' => '', 'rule_version_id' => 0, 'c1_ratio_bps' => 0,
            'b1_ratio_bps' => 0, 'reverse_ledger_id' => 0, 'source_unique_key' => $key,
            'reason' => $sourceType, 'snapshot_json' => $this->json($snapshot),
            'operator_uid' => $operatorUid, 'add_time' => time(),
        ]);
    }

    private function consumeLots(int $storeId, string $bucket, int $amount): void
    {
        $rows = Db::name('yfth_commission_ledger')->where([
            'account_type' => 'store', 'account_id' => $storeId, 'bucket' => $bucket, 'direction' => 'credit',
        ])->where('remaining_withdrawable_cent', '>', 0)->order('id asc')->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            if ($amount <= 0) break;
            $used = min($amount, (int)$row['remaining_withdrawable_cent']);
            Db::name('yfth_commission_ledger')->where('id', (int)$row['id'])->update([
                'remaining_withdrawable_cent' => (int)$row['remaining_withdrawable_cent'] - $used,
            ]);
            $amount -= $used;
        }
    }

    private function settlementAccountForWithdrawal(int $storeId): array
    {
        $row = $this->row(Db::name('yfth_store_settlement_account')->where([
            'store_id' => $storeId, 'is_default' => 1, 'status' => 'active',
        ])->lock(true)->find());
        if (!$row) throw new ApiException('store_settlement_account_required');
        return ['id' => (int)$row['id'], 'snapshot' => [
            'account_type' => (string)$row['account_type'],
            'account_name_enc' => (string)$row['account_name_enc'],
            'account_no_enc' => (string)$row['account_no_enc'],
            'bank_name_enc' => (string)$row['bank_name_enc'],
            'bank_branch_enc' => (string)$row['bank_branch_enc'],
            'reserved_phone_enc' => (string)$row['reserved_phone_enc'],
            'contact_name_enc' => (string)$row['contact_name_enc'],
            'contact_phone_enc' => (string)$row['contact_phone_enc'],
            'account_no_masked' => (string)$row['account_no_masked'],
        ]];
    }

    private function maskedSettlementAccount(int $storeId): array
    {
        $row = $this->row(Db::name('yfth_store_settlement_account')->where([
            'store_id' => $storeId, 'is_default' => 1, 'status' => 'active',
        ])->find());
        if (!$row) return [];
        return ['id' => (int)$row['id'], 'account_type' => (string)$row['account_type'],
            'account_name_masked' => $this->maskName($this->decrypt((string)$row['account_name_enc'])),
            'account_no_masked' => (string)$row['account_no_masked'],
            'bank_name' => $this->decrypt((string)$row['bank_name_enc']),
            'bank_branch' => $this->decrypt((string)$row['bank_branch_enc']),
            'reserved_phone_masked' => $this->maskPhone($this->decrypt((string)$row['reserved_phone_enc'])),
            'contact_name_masked' => $this->maskName($this->decrypt((string)$row['contact_name_enc'])),
            'contact_phone_masked' => $this->maskPhone($this->decrypt((string)$row['contact_phone_enc'])),
            'status' => (string)$row['status']];
    }

    private function assertStoreContext(array $context, bool $write): int
    {
        $storeId = (int)($context['store_id'] ?? 0);
        $role = (string)($context['role_code'] ?? '');
        $allowed = array_merge(YfthConstants::storeRoles(), YfthConstants::partnerRoles());
        if ($storeId <= 0 || !in_array($role, $allowed, true)) throw new ApiException('store_context_required');
        if ($write && $role === 'store_staff') throw new ApiException('store_staff_cannot_settle_commission');
        return $storeId;
    }

    private function ledgerPage(string $type, int $id, array $where): array
    {
        $query = Db::name('yfth_commission_ledger')->where(['account_type' => $type, 'account_id' => $id]);
        if (!empty($where['bucket'])) $query->where('bucket', (string)$where['bucket']);
        if (!empty($where['source_type'])) $query->where('source_type', (string)$where['source_type']);
        return $this->page($query, $where, function (array $row) {
            unset($row['snapshot_json'], $row['source_unique_key']);
            return $this->moneyDto($row, ['amount_cent', 'balance_after_cent', 'remaining_withdrawable_cent']);
        });
    }

    private function page($query, array $where, callable $map): array
    {
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 20)));
        $count = (int)(clone $query)->count();
        $rows = $query->order('id desc')->page($page, $limit)->select()->toArray();
        return ['list' => array_map($map, $rows), 'count' => $count];
    }

    private function storeWithdrawalDto(array $row): array
    {
        unset($row['settlement_snapshot_json']);
        $row['store_name'] = (string)Db::name('system_store')->where('id', (int)$row['store_id'])->value('name');
        return $this->moneyDto($row, ['amount_cent', 'own_amount_cent', 'proxy_amount_cent']);
    }

    private function headquartersWithdrawalDto(array $row): array
    {
        $snapshot = json_decode((string)($row['settlement_snapshot_json'] ?? ''), true) ?: [];
        $row['settlement_account'] = [
            'account_type' => (string)($snapshot['account_type'] ?? ''),
            'account_name' => $this->decryptOptional((string)($snapshot['account_name_enc'] ?? '')),
            'account_no' => $this->decryptOptional((string)($snapshot['account_no_enc'] ?? '')),
            'account_no_masked' => (string)($snapshot['account_no_masked'] ?? ''),
            'bank_name' => $this->decryptOptional((string)($snapshot['bank_name_enc'] ?? '')),
            'bank_branch' => $this->decryptOptional((string)($snapshot['bank_branch_enc'] ?? '')),
            'reserved_phone' => $this->decryptOptional((string)($snapshot['reserved_phone_enc'] ?? '')),
            'contact_name' => $this->decryptOptional((string)($snapshot['contact_name_enc'] ?? '')),
            'contact_phone' => $this->decryptOptional((string)($snapshot['contact_phone_enc'] ?? '')),
        ];
        unset($row['settlement_snapshot_json']);
        $row['store_name'] = (string)Db::name('system_store')->where('id', (int)$row['store_id'])->value('name');
        return $this->moneyDto($row, ['amount_cent', 'own_amount_cent', 'proxy_amount_cent']);
    }

    private function moneyDto(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) $row[preg_replace('/_cent$/', '', $field)] = number_format((int)$row[$field] / 100, 2, '.', '');
        }
        return $row;
    }

    private function userAccount(int $uid): array
    {
        return Db::transaction(function () use ($uid) { return $this->lockUserAccount($uid); });
    }

    private function storeAccount(int $storeId): array
    {
        return Db::transaction(function () use ($storeId) { return $this->lockStoreAccount($storeId); });
    }

    private function lockUserAccount(int $uid): array
    {
        $row = $this->row(Db::name('yfth_user_commission_account')->where('uid', $uid)->lock(true)->find());
        if ($row) return $row;
        $now = time();
        try {
            Db::name('yfth_user_commission_account')->insert(['uid' => $uid, 'available_cent' => 0,
                'frozen_cent' => 0, 'withdrawn_cent' => 0, 'version' => 0, 'add_time' => $now, 'update_time' => $now]);
        } catch (\Throwable $e) {
            if (!$this->uniqueConflict($e)) throw $e;
        }
        return $this->row(Db::name('yfth_user_commission_account')->where('uid', $uid)->lock(true)->find());
    }

    private function lockStoreAccount(int $storeId): array
    {
        $row = $this->row(Db::name('yfth_store_commission_account')->where('store_id', $storeId)->lock(true)->find());
        if ($row) return $row;
        $now = time();
        try {
            Db::name('yfth_store_commission_account')->insert(['store_id' => $storeId,
                'own_available_cent' => 0, 'proxy_available_cent' => 0, 'hq_frozen_cent' => 0,
                'hq_withdrawn_cent' => 0, 'c1_pending_cent' => 0, 'c1_paid_cent' => 0,
                'reversed_cent' => 0, 'version' => 0, 'add_time' => $now, 'update_time' => $now]);
        } catch (\Throwable $e) {
            if (!$this->uniqueConflict($e)) throw $e;
        }
        return $this->row(Db::name('yfth_store_commission_account')->where('store_id', $storeId)->lock(true)->find());
    }

    private function encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new ApiException('settlement_account_encrypt_failed');
        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) throw new ApiException('settlement_account_decrypt_failed');
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        if ($plain === false) throw new ApiException('settlement_account_decrypt_failed');
        return $plain;
    }

    private function encryptionKey(): string
    {
        $secret = trim((string)Env::get('yfth.settlement_key', ''));
        if ($secret === '') $secret = trim((string)Env::get('app.app_key', ''));
        if ($secret === '' || strtolower($secret) === 'default') {
            throw new ApiException('settlement_encryption_key_missing');
        }
        return hash('sha256', 'yfth-settlement|' . $secret, true);
    }

    private function decryptOptional(string $encoded): string
    {
        return $encoded === '' ? '' : $this->decrypt($encoded);
    }

    private function audit(string $objectType, string $objectId, string $action, array $before, array $after,
                           int $operatorUid, string $role, int $storeId, string $reason = '', string $requestId = ''): void
    {
        app()->make(AuditEventServices::class)->record('automatic_commission', $objectType, $objectId, $action,
            $before, $after, $operatorUid, $role, $storeId, $reason, $requestId);
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) >= 7 ? substr($phone, 0, 3) . '****' . substr($phone, -4) : '';
    }

    private function maskAccount(string $number): string
    {
        return strlen($number) > 4 ? str_repeat('*', min(12, strlen($number) - 4)) . substr($number, -4) : '****';
    }

    private function maskName(string $name): string
    {
        if ($name === '') return '';
        return mb_substr($name, 0, 1) . '**';
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function uniqueConflict(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false || (string)$e->getCode() === '23000';
    }

    private function runWithDeadlockRetry(callable $callback, int $maxAttempts = 3): array
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                if (!$this->deadlockOrLockTimeout($e)) throw $e;
                if ($attempt >= $maxAttempts) throw new ApiException('commission_concurrency_retry_required');
                usleep(50000 * $attempt);
            }
        }
        throw new ApiException('commission_concurrency_retry_required');
    }

    private function deadlockOrLockTimeout(\Throwable $e): bool
    {
        for ($current = $e; $current; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (strpos($message, 'deadlock') !== false || strpos($message, 'lock wait timeout') !== false
                || strpos($message, '1213') !== false || strpos($message, '1205') !== false
                || (string)$current->getCode() === '40001') {
                return true;
            }
        }
        return false;
    }

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
