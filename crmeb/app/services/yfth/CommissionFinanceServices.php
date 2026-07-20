<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;
use think\facade\Env;

/**
 * YFTH commission settlement projections and controlled settlement facts.
 *
 * C1 settlements are completed offline by the responsible B1. B1 commission
 * is never withdrawable: headquarters groups immutable store ledger entries
 * into settlement-cycle batches reserved for WeChat profit sharing.
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
            'observing' => $this->money($observingCent),
            'notice' => '御方通和推荐收益由责任门店线下结算，不进入商城余额或积分。',
        ];
    }

    public function userLedger(int $uid, array $where = []): array
    {
        return $this->ledgerPage('user', $uid, $where);
    }

    public function userSettlements(int $uid, array $where = []): array
    {
        $query = Db::name('yfth_c1_settlement_request')->where('uid', $uid);
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        return $this->page($query, $where, function (array $row) {
            return $this->moneyDto($row, ['amount_cent']);
        });
    }

    public function requestUserSettlement(int $uid, int $amountCent, string $requestId): array
    {
        if ($uid <= 0 || $amountCent <= 0 || trim($requestId) === '') {
            throw new ApiException('commission_settlement_request_invalid');
        }
        return Db::transaction(function () use ($uid, $amountCent, $requestId) {
            $existing = $this->row(Db::name('yfth_c1_settlement_request')->where([
                'uid' => $uid, 'request_id' => $requestId,
            ])->lock(true)->find());
            if ($existing) return $this->moneyDto($existing, ['amount_cent']);

            $attribution = $this->row(Db::name('yfth_hq_customer_attribution_current')->where([
                'uid' => $uid, 'status' => 'active',
            ])->lock(true)->find());
            $storeId = (int)($attribution['store_id'] ?? 0);
            if ($storeId <= 0) throw new ApiException('commission_responsible_store_missing');

            $account = $this->lockUserAccount($uid);
            if ((int)$account['available_cent'] < $amountCent) {
                throw new ApiException('commission_balance_insufficient');
            }
            $now = time();
            $row = [
                'settlement_no' => $this->makeNo('YFCS'), 'uid' => $uid, 'store_id' => $storeId,
                'amount_cent' => $amountCent, 'status' => 'pending', 'offline_ref_no' => '',
                'proof_ref' => '', 'remark' => '', 'request_id' => $requestId,
                'operator_uid' => 0, 'completed_at' => 0, 'add_time' => $now, 'update_time' => $now,
            ];
            $row['id'] = (int)Db::name('yfth_c1_settlement_request')->insertGetId($row);
            $this->moveUserToFrozen($account, $amountCent, $row);
            $this->audit('c1_settlement', (string)$row['id'], 'request', [], $row, $uid, 'customer', $storeId, '', $requestId);
            return $this->moneyDto($row, ['amount_cent']);
        });
    }

    public function storeSummary(array $context): array
    {
        $storeId = $this->assertStoreContext($context);
        $account = $this->storeAccount($storeId);
        return [
            'store_id' => $storeId,
            'account' => $this->moneyDto($account, [
                'unsettled_cent', 'settled_cent', 'c1_pending_cent', 'c1_paid_cent', 'reversed_cent',
            ]),
            'notice' => '门店佣金按总部结算周期处理；页面不提供余额或提现能力。',
        ];
    }

    public function storeLedger(array $context, array $where = []): array
    {
        return $this->ledgerPage('store', $this->assertStoreContext($context), $where);
    }

    public function storeSettlementBatches(array $context, array $where = []): array
    {
        $storeId = $this->assertStoreContext($context);
        $query = Db::name('yfth_store_settlement_batch')->where('store_id', $storeId);
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        return $this->page($query, $where, function (array $row) {
            return $this->settlementBatchDto($row, false);
        });
    }

    public function storeUserSettlements(array $context, array $where = []): array
    {
        $storeId = $this->assertStoreContext($context);
        $query = Db::name('yfth_c1_settlement_request')->where('store_id', $storeId);
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        return $this->page($query, $where, function (array $row) {
            $user = $this->row(Db::name('user')->where('uid', (int)$row['uid'])
                ->field('uid,nickname,avatar,phone')->find());
            $row['user'] = [
                'uid' => (int)($user['uid'] ?? 0),
                'nickname' => (string)($user['nickname'] ?? ''),
                'avatar' => (string)($user['avatar'] ?? ''),
                'phone_masked' => $this->maskPhone((string)($user['phone'] ?? '')),
            ];
            return $this->moneyDto($row, ['amount_cent']);
        });
    }

    public function completeUserSettlement(array $context, int $id, array $data): array
    {
        $storeId = $this->assertStoreContext($context);
        $requestId = trim((string)($data['request_id'] ?? ''));
        if ($requestId === '') throw new ApiException('idempotency_key_required');
        return Db::transaction(function () use ($context, $storeId, $id, $data, $requestId) {
            $row = $this->row(Db::name('yfth_c1_settlement_request')->where([
                'id' => $id, 'store_id' => $storeId,
            ])->lock(true)->find());
            if (!$row) throw new ApiException('c1_settlement_not_found');
            if ((string)$row['status'] === 'paid') return $this->moneyDto($row, ['amount_cent']);
            if ((string)$row['status'] !== 'pending') throw new ApiException('c1_settlement_status_invalid');

            $user = $this->lockUserAccount((int)$row['uid']);
            $amount = (int)$row['amount_cent'];
            if ((int)$user['frozen_cent'] < $amount) throw new ApiException('c1_settlement_frozen_inconsistent');
            $now = time();
            $update = [
                'status' => 'paid',
                'offline_ref_no' => substr(trim((string)($data['offline_ref_no'] ?? '')), 0, 128),
                'proof_ref' => substr(trim((string)($data['proof_ref'] ?? '')), 0, 255),
                'remark' => substr(trim((string)($data['remark'] ?? '')), 0, 255),
                'operator_uid' => (int)$context['uid'], 'completed_at' => $now, 'update_time' => $now,
            ];
            Db::name('yfth_c1_settlement_request')->where('id', $id)->update($update);
            Db::name('yfth_user_commission_account')->where('id', (int)$user['id'])->update([
                'frozen_cent' => (int)$user['frozen_cent'] - $amount,
                'withdrawn_cent' => (int)$user['withdrawn_cent'] + $amount,
                'version' => (int)$user['version'] + 1, 'update_time' => $now,
            ]);
            $this->insertTransferLedger('user', (int)$row['uid'], 'c1_commission', $amount,
                (int)$user['available_cent'], (int)$user['frozen_cent'] - $amount,
                (int)$user['withdrawn_cent'] + $amount, 'c1_settlement_paid', (string)$id,
                'c1-settlement-paid:' . $id, (int)$context['uid'], $update);
            $this->increaseStoreCounter($storeId, 'c1_pending_cent', -$amount);
            $this->increaseStoreCounter($storeId, 'c1_paid_cent', $amount);
            $after = array_merge($row, $update);
            $this->audit('c1_settlement', (string)$id, 'offline_paid', $row, $after,
                (int)$context['uid'], (string)$context['role_code'], $storeId, (string)$update['remark'], $requestId);
            return $this->moneyDto($after, ['amount_cent']);
        });
    }

    public function headquartersLedger(array $where = []): array
    {
        $query = Db::name('yfth_commission_ledger');
        foreach (['account_type', 'bucket', 'source_type'] as $field) {
            if (!empty($where[$field])) $query->where($field, (string)$where[$field]);
        }
        if (!empty($where['account_id'])) $query->where('account_id', (int)$where['account_id']);
        return $this->page($query, $where, function (array $row) {
            unset($row['snapshot_json'], $row['source_unique_key']);
            return $this->moneyDto($row, ['amount_cent', 'balance_before_cent', 'balance_after_cent']);
        });
    }

    public function settlementReceiver(int $storeId): array
    {
        if ($storeId <= 0) throw new ApiException('store_id_required');
        $row = $this->row(Db::name('yfth_store_settlement_receiver')->where('store_id', $storeId)->find());
        return $row ? $this->receiverDto($row, true) : [];
    }

    public function saveSettlementReceiver(int $storeId, array $data, int $adminUid): array
    {
        $type = strtoupper(trim((string)($data['receiver_type'] ?? 'MERCHANT_ID')));
        $account = trim((string)($data['receiver_account'] ?? ''));
        $name = trim((string)($data['receiver_name'] ?? ''));
        if ($storeId <= 0 || !in_array($type, ['MERCHANT_ID', 'PERSONAL_OPENID'], true)
            || $account === '' || $name === '') {
            throw new ApiException('settlement_receiver_invalid');
        }
        return Db::transaction(function () use ($storeId, $type, $account, $name, $adminUid) {
            $before = $this->row(Db::name('yfth_store_settlement_receiver')->where('store_id', $storeId)->lock(true)->find());
            $now = time();
            $values = [
                'receiver_type' => $type,
                'receiver_account_enc' => $this->encrypt($account),
                'receiver_account_masked' => $this->maskAccount($account),
                'receiver_name_enc' => $this->encrypt($name),
                'status' => 'active', 'operator_uid' => $adminUid, 'update_time' => $now,
            ];
            if ($before) {
                Db::name('yfth_store_settlement_receiver')->where('id', (int)$before['id'])->update($values);
                $id = (int)$before['id'];
            } else {
                $values['store_id'] = $storeId; $values['add_time'] = $now;
                $id = (int)Db::name('yfth_store_settlement_receiver')->insertGetId($values);
            }
            $after = $this->row(Db::name('yfth_store_settlement_receiver')->where('id', $id)->find());
            $this->audit('store_settlement_receiver', (string)$id, 'save', $this->receiverDto($before, false),
                $this->receiverDto($after, false), $adminUid, 'headquarters', $storeId);
            return $this->receiverDto($after, true);
        });
    }

    public function headquartersSettlementBatches(array $where = []): array
    {
        $query = Db::name('yfth_store_settlement_batch');
        if (!empty($where['status'])) $query->where('status', (string)$where['status']);
        if (!empty($where['store_id'])) $query->where('store_id', (int)$where['store_id']);
        return $this->page($query, $where, function (array $row) {
            return $this->settlementBatchDto($row, true);
        });
    }

    public function generateSettlementBatches(int $periodStart, int $periodEnd, int $adminUid): array
    {
        if ($periodEnd <= 0 || $periodStart < 0 || $periodStart > $periodEnd) {
            throw new ApiException('settlement_period_invalid');
        }
        $rows = Db::name('yfth_commission_ledger')->alias('l')
            ->leftJoin('yfth_store_settlement_batch_item i', 'i.ledger_id=l.id')
            ->where(['l.account_type' => 'store', 'l.bucket' => 'store_commission'])
            ->where('l.add_time', 'between', [$periodStart, $periodEnd])
            ->whereNull('i.id')->field('l.*')->order('l.id asc')->select()->toArray();
        $groups = [];
        foreach ($rows as $row) $groups[(int)$row['account_id']][] = $row;
        $created = [];
        foreach ($groups as $storeId => $ledgers) {
            $amount = 0;
            foreach ($ledgers as $ledger) {
                $amount += (string)$ledger['direction'] === 'debit' ? -(int)$ledger['amount_cent'] : (int)$ledger['amount_cent'];
            }
            if ($amount <= 0) continue;
            $receiver = $this->row(Db::name('yfth_store_settlement_receiver')->where([
                'store_id' => $storeId, 'status' => 'active',
            ])->find());
            if (!$receiver) continue;
            $requestId = hash('sha256', $storeId . '|' . $periodStart . '|' . $periodEnd);
            $created[] = Db::transaction(function () use ($storeId, $periodStart, $periodEnd, $adminUid, $receiver, $ledgers, $requestId) {
                $existing = $this->row(Db::name('yfth_store_settlement_batch')->where([
                    'store_id' => $storeId, 'request_id' => $requestId,
                ])->lock(true)->find());
                if ($existing) return $this->settlementBatchDto($existing, true);

                $ids = array_map(function ($row) { return (int)$row['id']; }, $ledgers);
                $locked = Db::name('yfth_commission_ledger')->whereIn('id', $ids)->lock(true)->order('id asc')->select()->toArray();
                $used = Db::name('yfth_store_settlement_batch_item')->whereIn('ledger_id', $ids)->column('ledger_id');
                $usedMap = array_fill_keys(array_map('intval', $used), true);
                $amount = 0; $items = [];
                foreach ($locked as $ledger) {
                    if (isset($usedMap[(int)$ledger['id']])) continue;
                    $signed = (string)$ledger['direction'] === 'debit' ? -(int)$ledger['amount_cent'] : (int)$ledger['amount_cent'];
                    $amount += $signed;
                    $items[] = ['ledger_id' => (int)$ledger['id'], 'signed_amount_cent' => $signed,
                        'source_type' => (string)$ledger['source_type'], 'source_id' => (string)$ledger['source_id'],
                        'source_order_id' => (int)$ledger['source_order_id']];
                }
                if ($amount <= 0 || !$items) throw new ApiException('settlement_batch_nothing_to_settle');
                $merchantNo = trim((string)Env::get('wechat.merchant_no', Env::get('pay.wechat_mchid', '')));
                $now = time();
                $batch = [
                    'batch_no' => $this->makeNo('YFSB'), 'store_id' => $storeId,
                    'period_start' => $periodStart, 'period_end' => $periodEnd, 'amount_cent' => $amount,
                    'status' => 'pending', 'receiver_id' => (int)$receiver['id'],
                    'receiver_type' => (string)$receiver['receiver_type'],
                    'receiver_account_enc' => (string)$receiver['receiver_account_enc'],
                    'receiver_account_masked' => (string)$receiver['receiver_account_masked'],
                    'receiver_name_enc' => (string)$receiver['receiver_name_enc'],
                    'merchant_no_enc' => $merchantNo === '' ? '' : $this->encrypt($merchantNo),
                    'merchant_no_masked' => $merchantNo === '' ? '' : $this->maskAccount($merchantNo),
                    'wechat_batch_no' => '', 'wechat_detail_no' => '', 'request_id' => $requestId,
                    'admin_uid' => $adminUid, 'exception_reason' => '', 'processing_at' => 0,
                    'settled_at' => 0, 'callback_at' => 0, 'add_time' => $now, 'update_time' => $now,
                ];
                $batch['id'] = (int)Db::name('yfth_store_settlement_batch')->insertGetId($batch);
                foreach ($items as $item) {
                    $item['batch_id'] = $batch['id']; $item['add_time'] = $now;
                    Db::name('yfth_store_settlement_batch_item')->insert($item);
                }
                $this->audit('store_settlement_batch', (string)$batch['id'], 'generate', [], $batch,
                    $adminUid, 'headquarters', $storeId);
                return $this->settlementBatchDto($batch, true);
            });
        }
        return ['list' => $created, 'count' => count($created)];
    }

    public function startSettlementBatch(int $id, int $adminUid): array
    {
        return Db::transaction(function () use ($id, $adminUid) {
            $row = $this->row(Db::name('yfth_store_settlement_batch')->where('id', $id)->lock(true)->find());
            if (!$row) throw new ApiException('settlement_batch_not_found');
            if ((string)$row['status'] === 'processing' || (string)$row['status'] === 'settled') {
                return $this->settlementBatchDto($row, true);
            }
            if (!in_array((string)$row['status'], ['pending', 'exception'], true)) {
                throw new ApiException('settlement_batch_status_invalid');
            }
            $now = time();
            $update = [
                'status' => 'processing', 'admin_uid' => $adminUid, 'exception_reason' => '',
                'processing_at' => $now, 'update_time' => $now,
            ];
            Db::name('yfth_store_settlement_batch')->where('id', $id)->update($update);
            $after = array_merge($row, $update);
            $this->audit('store_settlement_batch', (string)$id, 'start_profit_sharing', $row, $after,
                $adminUid, 'headquarters', (int)$row['store_id'], 'WeChat profit-sharing adapter reserved');
            return $this->settlementBatchDto($after, true);
        });
    }

    public function recordSettlementCallback(int $id, array $data, int $adminUid): array
    {
        $eventId = trim((string)($data['callback_event_id'] ?? ''));
        $status = strtolower(trim((string)($data['status'] ?? '')));
        if ($eventId === '' || !in_array($status, ['success', 'failed'], true)) {
            throw new ApiException('settlement_callback_invalid');
        }
        return Db::transaction(function () use ($id, $data, $adminUid, $eventId, $status) {
            $existing = $this->row(Db::name('yfth_store_settlement_callback')->where('callback_event_id', $eventId)->lock(true)->find());
            $batch = $this->row(Db::name('yfth_store_settlement_batch')->where('id', $id)->lock(true)->find());
            if (!$batch) throw new ApiException('settlement_batch_not_found');
            if ($existing) return $this->settlementBatchDto($batch, true);
            if ((string)$batch['status'] === 'settled') return $this->settlementBatchDto($batch, true);
            if ((string)$batch['status'] !== 'processing') throw new ApiException('settlement_batch_not_processing');

            $now = time();
            $safe = [
                'callback_event_id' => $eventId, 'status' => $status,
                'wechat_batch_no' => substr(trim((string)($data['wechat_batch_no'] ?? '')), 0, 96),
                'wechat_detail_no' => substr(trim((string)($data['wechat_detail_no'] ?? '')), 0, 96),
                'message' => substr(trim((string)($data['message'] ?? '')), 0, 255),
            ];
            Db::name('yfth_store_settlement_callback')->insert([
                'batch_id' => $id, 'callback_event_id' => $eventId, 'callback_status' => $status,
                'callback_json' => $this->json($safe), 'add_time' => $now,
            ]);
            $update = [
                'status' => $status === 'success' ? 'settled' : 'exception',
                'wechat_batch_no' => $safe['wechat_batch_no'], 'wechat_detail_no' => $safe['wechat_detail_no'],
                'exception_reason' => $status === 'failed' ? $safe['message'] : '',
                'callback_at' => $now, 'settled_at' => $status === 'success' ? $now : 0,
                'admin_uid' => $adminUid, 'update_time' => $now,
            ];
            Db::name('yfth_store_settlement_batch')->where('id', $id)->update($update);
            if ($status === 'success') {
                $account = $this->lockStoreAccount((int)$batch['store_id']);
                Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
                    'unsettled_cent' => (int)$account['unsettled_cent'] - (int)$batch['amount_cent'],
                    'settled_cent' => (int)$account['settled_cent'] + (int)$batch['amount_cent'],
                    'version' => (int)$account['version'] + 1, 'update_time' => $now,
                ]);
            }
            $after = array_merge($batch, $update);
            $this->audit('store_settlement_batch', (string)$id, 'profit_sharing_callback', $batch, $after,
                $adminUid, 'headquarters', (int)$batch['store_id'], $safe['message'], $eventId);
            return $this->settlementBatchDto($after, true);
        });
    }

    public function adjustUser(int $uid, int $deltaCent, int $adminUid, string $reason, string $requestId): array
    {
        if ($uid <= 0 || $deltaCent === 0 || mb_strlen(trim($reason)) < 4 || trim($requestId) === '') {
            throw new ApiException('commission_adjustment_invalid');
        }
        return Db::transaction(function () use ($uid, $deltaCent, $adminUid, $reason, $requestId) {
            $key = hash('sha256', 'adjust-user|' . $uid . '|' . $requestId);
            $existing = $this->row(Db::name('yfth_commission_ledger')->where('source_unique_key', $key)->find());
            if ($existing) return $this->moneyDto($existing, ['amount_cent', 'balance_after_cent']);
            $account = $this->lockUserAccount($uid); $before = (int)$account['available_cent']; $after = $before + $deltaCent;
            Db::name('yfth_user_commission_account')->where('id', (int)$account['id'])->update([
                'available_cent' => $after, 'version' => (int)$account['version'] + 1, 'update_time' => time(),
            ]);
            return $this->insertBalanceLedger('user', $uid, 'c1_commission', $deltaCent, $before, $after,
                'manual_adjustment', (string)$uid, $key, $adminUid, $reason);
        });
    }

    public function adjustStore(int $storeId, string $bucket, int $deltaCent, int $adminUid, string $reason, string $requestId): array
    {
        if ($storeId <= 0 || $bucket !== 'store_commission' || $deltaCent === 0
            || mb_strlen(trim($reason)) < 4 || trim($requestId) === '') {
            throw new ApiException('commission_adjustment_invalid');
        }
        return Db::transaction(function () use ($storeId, $deltaCent, $adminUid, $reason, $requestId) {
            $key = hash('sha256', 'adjust-store|' . $storeId . '|' . $requestId);
            $existing = $this->row(Db::name('yfth_commission_ledger')->where('source_unique_key', $key)->find());
            if ($existing) return $this->moneyDto($existing, ['amount_cent', 'balance_after_cent']);
            $account = $this->lockStoreAccount($storeId); $before = (int)$account['unsettled_cent']; $after = $before + $deltaCent;
            Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
                'unsettled_cent' => $after, 'version' => (int)$account['version'] + 1, 'update_time' => time(),
            ]);
            return $this->insertBalanceLedger('store', $storeId, 'store_commission', $deltaCent, $before, $after,
                'manual_adjustment', (string)$storeId, $key, $adminUid, $reason);
        });
    }

    private function moveUserToFrozen(array $account, int $amount, array $request): void
    {
        $now = time();
        Db::name('yfth_user_commission_account')->where('id', (int)$account['id'])->update([
            'available_cent' => (int)$account['available_cent'] - $amount,
            'frozen_cent' => (int)$account['frozen_cent'] + $amount,
            'version' => (int)$account['version'] + 1, 'update_time' => $now,
        ]);
        $this->insertTransferLedger('user', (int)$account['uid'], 'c1_commission', $amount,
            (int)$account['available_cent'] - $amount, (int)$account['frozen_cent'] + $amount,
            (int)$account['withdrawn_cent'], 'c1_settlement_requested', (string)$request['id'],
            'c1-settlement-request:' . $request['id'], (int)$account['uid'], $request);
    }

    private function insertTransferLedger(string $type, int $accountId, string $bucket, int $amount,
                                          int $available, int $frozen, int $withdrawn, string $sourceType,
                                          string $sourceId, string $uniqueSeed, int $operatorUid, array $snapshot): array
    {
        $key = hash('sha256', $uniqueSeed);
        $existing = $this->row(Db::name('yfth_commission_ledger')->where('source_unique_key', $key)->find());
        if ($existing) return $existing;
        $row = [
            'ledger_no' => $this->makeNo('YFCL'), 'account_type' => $type, 'account_id' => $accountId,
            'bucket' => $bucket, 'direction' => 'transfer', 'amount_cent' => $amount,
            'balance_before_cent' => $available, 'balance_after_cent' => $available,
            'available_after_cent' => $available, 'frozen_after_cent' => $frozen,
            'withdrawn_after_cent' => $withdrawn, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'source_order_id' => 0, 'source_order_item_id' => '', 'rule_version_id' => 0,
            'c1_ratio_bps' => 0, 'b1_ratio_bps' => 0, 'reverse_ledger_id' => 0,
            'source_unique_key' => $key, 'reason' => '', 'snapshot_json' => $this->json($snapshot),
            'operator_uid' => $operatorUid, 'add_time' => time(),
        ];
        $row['id'] = (int)Db::name('yfth_commission_ledger')->insertGetId($row);
        return $row;
    }

    private function insertBalanceLedger(string $type, int $accountId, string $bucket, int $delta,
                                         int $before, int $after, string $sourceType, string $sourceId,
                                         string $key, int $operatorUid, string $reason): array
    {
        $row = [
            'ledger_no' => $this->makeNo('YFCL'), 'account_type' => $type, 'account_id' => $accountId,
            'bucket' => $bucket, 'direction' => $delta > 0 ? 'credit' : 'debit',
            'amount_cent' => abs($delta), 'balance_before_cent' => $before, 'balance_after_cent' => $after,
            'available_after_cent' => $after, 'frozen_after_cent' => 0, 'withdrawn_after_cent' => 0,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'source_order_id' => 0,
            'source_order_item_id' => '', 'rule_version_id' => 0, 'c1_ratio_bps' => 0,
            'b1_ratio_bps' => 0, 'reverse_ledger_id' => 0, 'source_unique_key' => $key,
            'reason' => substr($reason, 0, 255), 'snapshot_json' => '{}', 'operator_uid' => $operatorUid,
            'add_time' => time(),
        ];
        $row['id'] = (int)Db::name('yfth_commission_ledger')->insertGetId($row);
        $this->audit('commission_ledger', (string)$row['id'], 'manual_adjustment', [], $row,
            $operatorUid, 'headquarters', $type === 'store' ? $accountId : 0, $reason, $key);
        return $this->moneyDto($row, ['amount_cent', 'balance_before_cent', 'balance_after_cent']);
    }

    private function increaseStoreCounter(int $storeId, string $field, int $amount): void
    {
        $account = $this->lockStoreAccount($storeId);
        Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update([
            $field => (int)$account[$field] + $amount,
            'version' => (int)$account['version'] + 1, 'update_time' => time(),
        ]);
    }

    private function assertStoreContext(array $context): int
    {
        $storeId = (int)($context['store_id'] ?? 0);
        $role = (string)($context['role_code'] ?? '');
        $allowed = array_merge(YfthConstants::storeRoles(), YfthConstants::partnerRoles());
        if ($storeId <= 0 || !in_array($role, $allowed, true)) throw new ApiException('store_context_required');
        return $storeId;
    }

    private function ledgerPage(string $type, int $id, array $where): array
    {
        $query = Db::name('yfth_commission_ledger')->where(['account_type' => $type, 'account_id' => $id]);
        if (!empty($where['bucket'])) $query->where('bucket', (string)$where['bucket']);
        if (!empty($where['source_type'])) $query->where('source_type', (string)$where['source_type']);
        return $this->page($query, $where, function (array $row) {
            unset($row['snapshot_json'], $row['source_unique_key']);
            return $this->moneyDto($row, ['amount_cent', 'balance_before_cent', 'balance_after_cent']);
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

    private function settlementBatchDto(array $row, bool $headquarters): array
    {
        if (!$row) return [];
        unset($row['receiver_account_enc'], $row['receiver_name_enc'], $row['merchant_no_enc'], $row['request_id']);
        $row['store_name'] = (string)Db::name('system_store')->where('id', (int)$row['store_id'])->value('name');
        if (!$headquarters) unset($row['admin_uid'], $row['exception_reason'], $row['receiver_id']);
        return $this->moneyDto($row, ['amount_cent']);
    }

    private function receiverDto(array $row, bool $headquarters): array
    {
        if (!$row) return [];
        $dto = [
            'id' => (int)$row['id'], 'store_id' => (int)$row['store_id'],
            'receiver_type' => (string)$row['receiver_type'],
            'receiver_account_masked' => (string)$row['receiver_account_masked'],
            'receiver_name_masked' => $this->maskName($this->decrypt((string)$row['receiver_name_enc'])),
            'status' => (string)$row['status'], 'update_time' => (int)$row['update_time'],
        ];
        if ($headquarters) {
            $dto['receiver_account'] = $this->decrypt((string)$row['receiver_account_enc']);
            $dto['receiver_name'] = $this->decrypt((string)$row['receiver_name_enc']);
        }
        return $dto;
    }

    private function moneyDto(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[preg_replace('/_cent$/', '', $field)] = $this->money((int)$row[$field]);
            }
        }
        return $row;
    }

    private function money(int $cent): string
    {
        return number_format($cent / 100, 2, '.', '');
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
            Db::name('yfth_user_commission_account')->insert([
                'uid' => $uid, 'available_cent' => 0, 'frozen_cent' => 0, 'withdrawn_cent' => 0,
                'version' => 0, 'add_time' => $now, 'update_time' => $now,
            ]);
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
            Db::name('yfth_store_commission_account')->insert([
                'store_id' => $storeId, 'unsettled_cent' => 0, 'settled_cent' => 0,
                'c1_pending_cent' => 0, 'c1_paid_cent' => 0, 'reversed_cent' => 0,
                'version' => 0, 'add_time' => $now, 'update_time' => $now,
            ]);
        } catch (\Throwable $e) {
            if (!$this->uniqueConflict($e)) throw $e;
        }
        return $this->row(Db::name('yfth_store_commission_account')->where('store_id', $storeId)->lock(true)->find());
    }

    private function encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new ApiException('settlement_receiver_encrypt_failed');
        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) throw new ApiException('settlement_receiver_decrypt_failed');
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->encryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        if ($plain === false) throw new ApiException('settlement_receiver_decrypt_failed');
        return $plain;
    }

    private function encryptionKey(): string
    {
        $secret = trim((string)Env::get('yfth.settlement_key', ''));
        if ($secret === '') $secret = trim((string)Env::get('app.app_key', ''));
        if ($secret === '' || strtolower($secret) === 'default') throw new ApiException('settlement_encryption_key_missing');
        return hash('sha256', 'yfth-settlement|' . $secret, true);
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
        return $name === '' ? '' : mb_substr($name, 0, 1) . '**';
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

    private function row($row): array
    {
        return $row ? (is_array($row) ? $row : $row->toArray()) : [];
    }
}
