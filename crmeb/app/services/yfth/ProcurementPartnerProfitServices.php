<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

class ProcurementPartnerProfitServices
{
    private const RANKS = [
        'county_partner' => ['name' => '县级合伙人', 'level' => 1],
        'prefecture_partner' => ['name' => '地级合伙人', 'level' => 2],
        'province_partner' => ['name' => '省级合伙人', 'level' => 3],
        'regional_director' => ['name' => '大区总监', 'level' => 4],
        'platform_director' => ['name' => '平台董事', 'level' => 5],
    ];

    public function freezeForPurchaseOrder(array $order, int $baseAmountCent): array
    {
        $orderId = (int)($order['id'] ?? 0);
        $storeId = (int)($order['store_id'] ?? 0);
        if ($orderId <= 0 || $storeId <= 0 || $baseAmountCent < 0) {
            throw new ApiException('procurement_profit_snapshot_input_invalid');
        }
        $existing = Db::name('yfth_procurement_profit_snapshot')->where('purchase_order_id', $orderId)->find();
        if ($existing) {
            return $this->formatSnapshot($existing);
        }

        $rule = $this->activeRule(true);
        $rankRules = $this->rankRuleMap((int)$rule['id']);
        $directPartnerUid = $this->resolveDirectCountyPartner($storeId);
        $chain = $directPartnerUid > 0 ? $this->partnerChain($directPartnerUid) : [];
        $rates = [];
        foreach ($chain as $member) {
            $code = (string)$member['rank_code'];
            $rates[$code] = max(0, min(10000, (int)($rankRules[$code]['procurement_rate_bps'] ?? 0)));
        }
        $now = time();
        try {
            $id = (int)Db::name('yfth_procurement_profit_snapshot')->insertGetId([
                'purchase_order_id' => $orderId,
                'purchase_no' => (string)($order['purchase_no'] ?? ''),
                'store_id' => $storeId,
                'rule_version_id' => (int)$rule['id'],
                'base_amount_cent' => $baseAmountCent,
                'platform_dividend_bps' => max(0, min(10000, (int)$rule['platform_dividend_bps'])),
                'chain_snapshot' => $this->json($chain),
                'rate_snapshot' => $this->json($rates),
                'status' => 'frozen',
                'recognized_time' => 0,
                'reversed_amount_cent' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (\Throwable $e) {
            $existing = Db::name('yfth_procurement_profit_snapshot')->where('purchase_order_id', $orderId)->find();
            if (!$existing) {
                throw $e;
            }
            return $this->formatSnapshot($existing);
        }
        return $this->formatSnapshot(Db::name('yfth_procurement_profit_snapshot')->where('id', $id)->find() ?: []);
    }

    public function recognizeForReceipt(int $purchaseOrderId): array
    {
        $snapshot = Db::name('yfth_procurement_profit_snapshot')
            ->where('purchase_order_id', $purchaseOrderId)->lock(true)->find();
        if (!$snapshot) {
            throw new ApiException('procurement_profit_snapshot_missing');
        }
        if ((string)$snapshot['status'] === 'recognized') {
            return $this->formatSnapshot($snapshot);
        }
        $chain = $this->decode((string)$snapshot['chain_snapshot']);
        $rates = $this->decode((string)$snapshot['rate_snapshot']);
        $now = time();
        foreach ($chain as $member) {
            $uid = (int)($member['uid'] ?? 0);
            $rank = (string)($member['rank_code'] ?? '');
            $rate = max(0, min(10000, (int)($rates[$rank] ?? 0)));
            if ($uid <= 0 || $rate <= 0) {
                continue;
            }
            $amount = intdiv((int)$snapshot['base_amount_cent'] * $rate, 10000);
            if ($amount <= 0) {
                continue;
            }
            $sourceKey = 'purchase:' . $purchaseOrderId . ':partner:' . $uid . ':rank:' . $rank;
            $this->insertImmutable('yfth_procurement_profit_ledger', [
                'snapshot_id' => (int)$snapshot['id'],
                'purchase_order_id' => $purchaseOrderId,
                'store_id' => (int)$snapshot['store_id'],
                'beneficiary_uid' => $uid,
                'rank_code' => $rank,
                'entry_type' => 'procurement_profit',
                'base_amount_cent' => (int)$snapshot['base_amount_cent'],
                'rate_bps' => $rate,
                'amount_cent' => $amount,
                'status' => 'pending',
                'source_unique_key' => $sourceKey,
                'settled_time' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        Db::name('yfth_procurement_profit_snapshot')->where('id', (int)$snapshot['id'])->update([
            'status' => 'recognized',
            'recognized_time' => $now,
            'update_time' => $now,
        ]);
        $snapshot['status'] = 'recognized';
        $snapshot['recognized_time'] = $now;
        return $this->formatSnapshot($snapshot);
    }

    public function reverseForPurchaseOrder(int $purchaseOrderId, int $amountCent, string $sourceKey): array
    {
        if ($amountCent <= 0 || trim($sourceKey) === '') {
            throw new ApiException('procurement_profit_reversal_invalid');
        }
        $snapshot = Db::name('yfth_procurement_profit_snapshot')
            ->where('purchase_order_id', $purchaseOrderId)->lock(true)->find();
        if (!$snapshot || (string)$snapshot['status'] !== 'recognized') {
            throw new ApiException('procurement_profit_not_recognized');
        }
        $sourceHash = hash('sha256', $sourceKey);
        $existingReversal = Db::name('yfth_procurement_profit_ledger')
            ->where('snapshot_id', (int)$snapshot['id'])
            ->whereLike('source_unique_key', 'reversal:' . $sourceHash . ':%')
            ->find();
        if ($existingReversal) {
            return ['reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'], 'idempotent' => true];
        }
        $remaining = max(0, (int)$snapshot['base_amount_cent'] - (int)$snapshot['reversed_amount_cent']);
        $reversalBase = min($remaining, $amountCent);
        if ($reversalBase <= 0) {
            return ['reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'], 'idempotent' => true];
        }
        $positiveRows = Db::name('yfth_procurement_profit_ledger')
            ->where(['snapshot_id' => (int)$snapshot['id'], 'entry_type' => 'procurement_profit'])
            ->order('id asc')->select()->toArray();
        $now = time();
        foreach ($positiveRows as $row) {
            $key = 'reversal:' . $sourceHash . ':accrual:' . (int)$row['id'];
            $amount = -intdiv($reversalBase * (int)$row['rate_bps'], 10000);
            if ($amount === 0) {
                continue;
            }
            $this->insertImmutable('yfth_procurement_profit_ledger', [
                'snapshot_id' => (int)$snapshot['id'],
                'purchase_order_id' => $purchaseOrderId,
                'store_id' => (int)$snapshot['store_id'],
                'beneficiary_uid' => (int)$row['beneficiary_uid'],
                'rank_code' => (string)$row['rank_code'],
                'entry_type' => 'procurement_reversal',
                'base_amount_cent' => $reversalBase,
                'rate_bps' => (int)$row['rate_bps'],
                'amount_cent' => $amount,
                'status' => 'pending',
                'source_unique_key' => $key,
                'settled_time' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        Db::name('yfth_procurement_profit_snapshot')->where('id', (int)$snapshot['id'])->update([
            'reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'] + $reversalBase,
            'update_time' => $now,
        ]);
        return ['reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'] + $reversalBase, 'idempotent' => false];
    }

    public function freezeForStoreOrder(array $order): array
    {
        $orderId = (int)($order['id'] ?? 0);
        $sidecar = Db::name('yfth_native_procurement_order')->where('store_order_id', $orderId)->find();
        if (!$sidecar) {
            throw new ApiException('native_procurement_order_missing');
        }
        $existing = Db::name('yfth_procurement_profit_snapshot')->where([
            'source_type' => 'store_order',
            'source_id' => $orderId,
        ])->find();
        if ($existing) {
            return $this->formatSnapshot($existing);
        }
        $baseAmountCent = max(0, (int)bcmul(
            bcsub((string)($order['pay_price'] ?? '0'), (string)($order['pay_postage'] ?? '0'), 2),
            '100',
            0
        ));
        $rule = $this->activeRule(true);
        $rankRules = $this->rankRuleMap((int)$rule['id']);
        $storeId = (int)$sidecar['store_id'];
        $directPartnerUid = $this->resolveDirectCountyPartner($storeId);
        $chain = $directPartnerUid > 0 ? $this->partnerChain($directPartnerUid) : [];
        $rates = [];
        foreach ($chain as $member) {
            $code = (string)$member['rank_code'];
            $rates[$code] = max(0, min(10000, (int)($rankRules[$code]['procurement_rate_bps'] ?? 0)));
        }
        $now = time();
        try {
            $id = (int)Db::name('yfth_procurement_profit_snapshot')->insertGetId([
                'purchase_order_id' => 0,
                'purchase_no' => (string)($order['order_id'] ?? ''),
                'source_type' => 'store_order',
                'source_id' => $orderId,
                'store_order_id' => $orderId,
                'store_id' => $storeId,
                'rule_version_id' => (int)$rule['id'],
                'base_amount_cent' => $baseAmountCent,
                'platform_dividend_bps' => max(0, min(10000, (int)$rule['platform_dividend_bps'])),
                'chain_snapshot' => $this->json($chain),
                'rate_snapshot' => $this->json($rates),
                'status' => 'frozen',
                'recognized_time' => 0,
                'reversed_amount_cent' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (\Throwable $e) {
            $existing = Db::name('yfth_procurement_profit_snapshot')->where([
                'source_type' => 'store_order',
                'source_id' => $orderId,
            ])->find();
            if (!$existing) throw $e;
            return $this->formatSnapshot($existing);
        }
        return $this->formatSnapshot(Db::name('yfth_procurement_profit_snapshot')->where('id', $id)->find() ?: []);
    }

    public function recognizeForStoreOrder(int $orderId): array
    {
        $snapshot = Db::name('yfth_procurement_profit_snapshot')->where([
            'source_type' => 'store_order',
            'source_id' => $orderId,
        ])->lock(true)->find();
        if (!$snapshot) {
            throw new ApiException('procurement_profit_snapshot_missing');
        }
        if ((string)$snapshot['status'] === 'recognized') {
            return $this->formatSnapshot($snapshot);
        }
        $chain = $this->decode((string)$snapshot['chain_snapshot']);
        $rates = $this->decode((string)$snapshot['rate_snapshot']);
        $now = time();
        foreach ($chain as $member) {
            $uid = (int)($member['uid'] ?? 0);
            $rank = (string)($member['rank_code'] ?? '');
            $rate = max(0, min(10000, (int)($rates[$rank] ?? 0)));
            $amount = $uid > 0 && $rate > 0 ? intdiv((int)$snapshot['base_amount_cent'] * $rate, 10000) : 0;
            if ($amount <= 0) continue;
            $this->insertImmutable('yfth_procurement_profit_ledger', [
                'snapshot_id' => (int)$snapshot['id'],
                'purchase_order_id' => 0,
                'source_type' => 'store_order',
                'source_id' => $orderId,
                'store_order_id' => $orderId,
                'store_id' => (int)$snapshot['store_id'],
                'beneficiary_uid' => $uid,
                'rank_code' => $rank,
                'entry_type' => 'procurement_profit',
                'base_amount_cent' => (int)$snapshot['base_amount_cent'],
                'rate_bps' => $rate,
                'amount_cent' => $amount,
                'status' => 'pending',
                'source_unique_key' => 'store_order:' . $orderId . ':partner:' . $uid . ':rank:' . $rank,
                'settled_time' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        Db::name('yfth_procurement_profit_snapshot')->where('id', (int)$snapshot['id'])->update([
            'status' => 'recognized',
            'recognized_time' => $now,
            'update_time' => $now,
        ]);
        $snapshot['status'] = 'recognized';
        $snapshot['recognized_time'] = $now;
        return $this->formatSnapshot($snapshot);
    }

    public function reverseForStoreOrder(int $orderId, int $amountCent, string $sourceKey): array
    {
        if ($amountCent <= 0 || trim($sourceKey) === '') {
            throw new ApiException('procurement_profit_reversal_invalid');
        }
        return Db::transaction(function () use ($orderId, $amountCent, $sourceKey) {
            $snapshot = $this->lockRecognizedStoreOrderSnapshot($orderId);
            return $this->reverseStoreOrderSnapshot($snapshot, $amountCent, $sourceKey);
        });
    }

    public function synchronizeStoreOrderRefund(int $orderId, int $cumulativeAmountCent): array
    {
        if ($cumulativeAmountCent <= 0) {
            throw new ApiException('procurement_profit_reversal_invalid');
        }
        return Db::transaction(function () use ($orderId, $cumulativeAmountCent) {
            $snapshot = $this->lockRecognizedStoreOrderSnapshot($orderId);
            $target = min((int)$snapshot['base_amount_cent'], $cumulativeAmountCent);
            $delta = max(0, $target - (int)$snapshot['reversed_amount_cent']);
            if ($delta <= 0) {
                return ['reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'], 'idempotent' => true];
            }
            return $this->reverseStoreOrderSnapshot(
                $snapshot,
                $delta,
                'store_order_refund:' . $orderId . ':cumulative:' . $target
            );
        });
    }

    private function lockRecognizedStoreOrderSnapshot(int $orderId): array
    {
        $snapshot = Db::name('yfth_procurement_profit_snapshot')->where([
            'source_type' => 'store_order',
            'source_id' => $orderId,
        ])->lock(true)->find();
        if (!$snapshot || (string)$snapshot['status'] !== 'recognized') {
            throw new ApiException('procurement_profit_not_recognized');
        }
        return $snapshot;
    }

    private function reverseStoreOrderSnapshot(array $snapshot, int $amountCent, string $sourceKey): array
    {
        $orderId = (int)($snapshot['store_order_id'] ?: $snapshot['source_id']);
        $sourceHash = hash('sha256', $sourceKey);
        if (Db::name('yfth_procurement_profit_ledger')->where('snapshot_id', (int)$snapshot['id'])
            ->whereLike('source_unique_key', 'reversal:' . $sourceHash . ':%')->find()) {
            return ['reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'], 'idempotent' => true];
        }
        $remaining = max(0, (int)$snapshot['base_amount_cent'] - (int)$snapshot['reversed_amount_cent']);
        $reversalBase = min($remaining, $amountCent);
        if ($reversalBase <= 0) {
            return ['reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'], 'idempotent' => true];
        }
        $rows = Db::name('yfth_procurement_profit_ledger')->where([
            'snapshot_id' => (int)$snapshot['id'],
            'entry_type' => 'procurement_profit',
        ])->order('id asc')->select()->toArray();
        $now = time();
        foreach ($rows as $row) {
            $amount = -intdiv($reversalBase * (int)$row['rate_bps'], 10000);
            if ($amount === 0) continue;
            $this->insertImmutable('yfth_procurement_profit_ledger', [
                'snapshot_id' => (int)$snapshot['id'],
                'purchase_order_id' => 0,
                'source_type' => 'store_order',
                'source_id' => $orderId,
                'store_order_id' => $orderId,
                'store_id' => (int)$snapshot['store_id'],
                'beneficiary_uid' => (int)$row['beneficiary_uid'],
                'rank_code' => (string)$row['rank_code'],
                'entry_type' => 'procurement_reversal',
                'base_amount_cent' => $reversalBase,
                'rate_bps' => (int)$row['rate_bps'],
                'amount_cent' => $amount,
                'status' => 'pending',
                'source_unique_key' => 'reversal:' . $sourceHash . ':accrual:' . (int)$row['id'],
                'settled_time' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        Db::name('yfth_procurement_profit_snapshot')->where('id', (int)$snapshot['id'])->update([
            'reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'] + $reversalBase,
            'update_time' => $now,
        ]);
        return ['reversed_amount_cent' => (int)$snapshot['reversed_amount_cent'] + $reversalBase, 'idempotent' => false];
    }

    public function recordOpeningReward(int $applicationId, int $storeId, int $directPartnerUid): array
    {
        if ($applicationId <= 0 || $storeId <= 0 || $directPartnerUid <= 0) {
            return ['created' => false, 'reason' => 'opening_reward_scope_missing'];
        }
        $profile = Db::name('yfth_partner_profile')->where(['uid' => $directPartnerUid, 'status' => 'active'])->find();
        if (!$profile || (string)$profile['rank_code'] !== 'county_partner') {
            return ['created' => false, 'reason' => 'county_partner_required'];
        }
        $rule = $this->activeRule(true);
        $rankRules = $this->rankRuleMap((int)$rule['id']);
        $amount = max(0, (int)($rankRules['county_partner']['opening_reward_amount_cent'] ?? 0));
        if ($amount <= 0) {
            return ['created' => false, 'reason' => 'opening_reward_disabled'];
        }
        $sourceKey = 'opening:' . $applicationId . ':county:' . $directPartnerUid;
        $existing = Db::name('yfth_partner_opening_reward_ledger')->where('source_unique_key', $sourceKey)->find();
        if ($existing) {
            return ['created' => false, 'idempotent' => true, 'reward' => $existing];
        }
        $now = time();
        $inserted = $this->insertImmutable('yfth_partner_opening_reward_ledger', [
            'application_id' => $applicationId,
            'store_id' => $storeId,
            'partner_uid' => $directPartnerUid,
            'rank_code' => 'county_partner',
            'rule_version_id' => (int)$rule['id'],
            'amount_cent' => $amount,
            'status' => 'pending',
            'source_unique_key' => $sourceKey,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $row = Db::name('yfth_partner_opening_reward_ledger')->where('source_unique_key', $sourceKey)->find() ?: [];
        return ['created' => $inserted, 'idempotent' => !$inserted, 'reward' => $row];
    }

    public function partnerSummary(int $uid): array
    {
        $procurement = $this->sumByStatus('yfth_procurement_profit_ledger', 'beneficiary_uid', $uid);
        $opening = $this->sumByStatus('yfth_partner_opening_reward_ledger', 'partner_uid', $uid);
        $dividend = $this->sumByStatus('yfth_platform_dividend_item', 'beneficiary_uid', $uid);
        return [
            'procurement' => $procurement,
            'opening_service' => $opening,
            'platform_dividend' => $dividend,
            'recent_procurement' => Db::name('yfth_procurement_profit_ledger')
                ->where('beneficiary_uid', $uid)->order('id desc')->limit(30)->select()->toArray(),
            'recent_opening_service' => Db::name('yfth_partner_opening_reward_ledger')
                ->where('partner_uid', $uid)->order('id desc')->limit(30)->select()->toArray(),
            'recent_dividend' => Db::name('yfth_platform_dividend_item')
                ->where('beneficiary_uid', $uid)->order('id desc')->limit(30)->select()->toArray(),
            'disclaimer' => '采购分润、开店服务奖励和平台加权分红均为业务台账，不代表已自动支付。',
        ];
    }

    public function adminProcurementProfits(array $filters): array
    {
        [$page, $limit] = $this->page($filters);
        $query = Db::name('yfth_procurement_profit_ledger')->alias('l')
            ->leftJoin('user u', 'u.uid=l.beneficiary_uid')
            ->leftJoin('system_store s', 's.id=l.store_id')
            ->leftJoin('yfth_purchase_order o', 'o.id=l.purchase_order_id')
            ->leftJoin('yfth_native_procurement_order n', 'n.store_order_id=l.store_order_id');
        if (!empty($filters['rank_code'])) {
            $query->where('l.rank_code', trim((string)$filters['rank_code']));
        }
        if (!empty($filters['status'])) {
            $query->where('l.status', trim((string)$filters['status']));
        }
        if ((int)($filters['store_id'] ?? 0) > 0) {
            $query->where('l.store_id', (int)$filters['store_id']);
        }
        $count = (int)(clone $query)->count();
        $list = $query->field('l.*,u.nickname,u.phone,s.name AS store_name,COALESCE(o.purchase_no,n.order_no) AS purchase_no')
            ->page($page, $limit)->order('l.id desc')->select()->toArray();
        return ['list' => $list, 'count' => $count];
    }

    public function adminOpeningRewards(array $filters): array
    {
        [$page, $limit] = $this->page($filters);
        $query = Db::name('yfth_partner_opening_reward_ledger')->alias('l')
            ->leftJoin('user u', 'u.uid=l.partner_uid')
            ->leftJoin('system_store s', 's.id=l.store_id');
        if (!empty($filters['status'])) {
            $query->where('l.status', trim((string)$filters['status']));
        }
        $count = (int)(clone $query)->count();
        $list = $query->field('l.*,u.nickname,u.phone,s.name AS store_name')
            ->page($page, $limit)->order('l.id desc')->select()->toArray();
        return ['list' => $list, 'count' => $count];
    }

    public function adminDividends(array $filters): array
    {
        [$page, $limit] = $this->page($filters);
        $query = Db::name('yfth_platform_dividend_batch');
        if (!empty($filters['status'])) {
            $query->where('status', trim((string)$filters['status']));
        }
        $count = (int)(clone $query)->count();
        $list = $query->page($page, $limit)->order('id desc')->select()->toArray();
        foreach ($list as &$row) {
            $row['items'] = Db::name('yfth_platform_dividend_item')->alias('i')
                ->leftJoin('user u', 'u.uid=i.beneficiary_uid')
                ->where('i.batch_id', (int)$row['id'])
                ->field('i.*,u.nickname,u.phone')->order('i.id asc')->select()->toArray();
        }
        return ['list' => $list, 'count' => $count];
    }

    public function generateDividend(string $periodKey): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            throw new ApiException('dividend_period_invalid');
        }
        $rule = $this->activeRule(true);
        $existing = Db::name('yfth_platform_dividend_batch')
            ->where(['period_key' => $periodKey, 'rule_version_id' => (int)$rule['id']])->find();
        if ($existing) {
            return ['batch' => $existing, 'idempotent' => true];
        }
        $start = strtotime($periodKey . '-01 00:00:00');
        $end = strtotime('+1 month', $start);
        $performance = (int)Db::name('yfth_procurement_profit_snapshot')
            ->where('status', 'recognized')->where('recognized_time', '>=', $start)->where('recognized_time', '<', $end)
            ->sum('base_amount_cent');
        $poolBps = max(0, min(10000, (int)$rule['platform_dividend_bps']));
        $poolCent = intdiv($performance * $poolBps, 10000);
        try {
            return Db::transaction(function () use ($periodKey, $rule, $performance, $poolBps, $poolCent, $start, $end) {
                $now = time();
                $directors = Db::name('yfth_partner_profile')->where([
                    'rank_code' => 'platform_director', 'status' => 'active',
                ])->order('uid asc')->select()->toArray();
                $weights = [];
                $totalWeight = 0;
                foreach ($directors as $director) {
                    $uid = (int)$director['uid'];
                    $weight = max(1, (int)Db::name('yfth_partner_opening_performance')
                        ->where('status', 'valid')->where('create_time', '>=', $start)->where('create_time', '<', $end)
                        ->whereLike('chain_snapshot', '%"uid":' . $uid . '%')->count());
                    $weights[$uid] = $weight;
                    $totalWeight += $weight;
                }
                $status = $poolCent > 0 && $totalWeight > 0 ? 'pending' : 'waiting';
                $batchId = (int)Db::name('yfth_platform_dividend_batch')->insertGetId([
                    'period_key' => $periodKey,
                    'rule_version_id' => (int)$rule['id'],
                    'performance_cent' => $performance,
                    'pool_bps' => $poolBps,
                    'pool_cent' => $poolCent,
                    'status' => $status,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $allocated = 0;
                $uids = array_keys($weights);
                foreach ($uids as $index => $uid) {
                    $amount = $index === count($uids) - 1
                        ? $poolCent - $allocated
                        : intdiv($poolCent * $weights[$uid], $totalWeight);
                    $allocated += $amount;
                    Db::name('yfth_platform_dividend_item')->insert([
                        'batch_id' => $batchId,
                        'beneficiary_uid' => $uid,
                        'weight_basis' => $weights[$uid],
                        'amount_cent' => max(0, $amount),
                        'status' => 'pending',
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                }
                return ['batch' => Db::name('yfth_platform_dividend_batch')->where('id', $batchId)->find(), 'idempotent' => false];
            });
        } catch (\Throwable $e) {
            $existing = Db::name('yfth_platform_dividend_batch')
                ->where(['period_key' => $periodKey, 'rule_version_id' => (int)$rule['id']])->find();
            if ($existing) {
                return ['batch' => $existing, 'idempotent' => true];
            }
            throw $e;
        }
    }

    private function resolveDirectCountyPartner(int $storeId): int
    {
        $binding = Db::name('yfth_partner_store_binding')
            ->where(['store_id' => $storeId, 'status' => 'active'])->order('id desc')->find();
        if ($binding) {
            $countyUid = $this->countyFromChain((int)$binding['partner_uid']);
            if ($countyUid > 0) {
                return $countyUid;
            }
        }
        $performance = Db::name('yfth_partner_opening_performance')
            ->where(['store_id' => $storeId, 'status' => 'valid'])->order('id desc')->find();
        if ($performance) {
            $countyUid = $this->countyFromChain((int)$performance['direct_partner_uid']);
            if ($countyUid > 0) {
                return $countyUid;
            }
        }
        return $this->nearestCountyPartner($storeId);
    }

    private function nearestCountyPartner(int $storeId): int
    {
        $store = Db::name('system_store')->where('id', $storeId)->field('id,address,detailed_address')->find() ?: [];
        $address = $this->addressParts((string)($store['address'] ?? '') . ' ' . (string)($store['detailed_address'] ?? ''));
        $profiles = Db::name('yfth_partner_profile')->where([
            'rank_code' => 'county_partner',
            'status' => 'active',
        ])->field('uid,primary_store_id,start_time')->select()->toArray();
        $candidates = [];
        foreach ($profiles as $profile) {
            $uid = (int)$profile['uid'];
            $areas = Db::name('yfth_partner_service_area')->where([
                'partner_uid' => $uid,
                'status' => 'active',
            ])->order('priority asc,id asc')->select()->toArray();
            if (!$areas && (int)$profile['primary_store_id'] > 0) {
                $primaryStore = Db::name('system_store')->where('id', (int)$profile['primary_store_id'])
                    ->field('address,detailed_address')->find() ?: [];
                $primaryAddress = $this->addressParts(
                    (string)($primaryStore['address'] ?? '') . ' ' . (string)($primaryStore['detailed_address'] ?? '')
                );
                $areas[] = array_merge($primaryAddress, ['priority' => 100]);
            }
            if (!$areas) {
                $areas[] = ['province' => '', 'city' => '', 'district' => '', 'priority' => 999];
            }
            $best = [9, 999];
            foreach ($areas as $area) {
                $score = $this->areaMatchScore($address, $area);
                $candidateScore = [$score, (int)($area['priority'] ?? 999)];
                if ($candidateScore < $best) {
                    $best = $candidateScore;
                }
            }
            $candidates[] = [
                'uid' => $uid,
                'match_score' => $best[0],
                'priority' => $best[1],
                'workload' => (int)Db::name('yfth_partner_store_binding')
                    ->where(['partner_uid' => $uid, 'status' => 'active'])->count(),
                'start_time' => (int)$profile['start_time'],
            ];
        }
        usort($candidates, function (array $left, array $right): int {
            foreach (['match_score', 'priority', 'workload', 'start_time', 'uid'] as $field) {
                $comparison = (int)$left[$field] <=> (int)$right[$field];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });
        return (int)($candidates[0]['uid'] ?? 0);
    }

    private function areaMatchScore(array $target, array $candidate): int
    {
        if ($target['district'] !== '' && $target['district'] === (string)($candidate['district'] ?? '')) {
            return 0;
        }
        if ($target['city'] !== '' && $target['city'] === (string)($candidate['city'] ?? '')) {
            return 1;
        }
        if ($target['province'] !== '' && $target['province'] === (string)($candidate['province'] ?? '')) {
            return 2;
        }
        return 3;
    }

    private function countyFromChain(int $uid): int
    {
        $seen = [];
        for ($depth = 0; $depth < 20 && $uid > 0; $depth++) {
            if (isset($seen[$uid])) {
                throw new ApiException('partner_relation_cycle_detected');
            }
            $seen[$uid] = true;
            $profile = Db::name('yfth_partner_profile')->where(['uid' => $uid, 'status' => 'active'])->find();
            if (!$profile) {
                return 0;
            }
            if ((string)$profile['rank_code'] === 'county_partner') {
                return $uid;
            }
            $relation = Db::name('yfth_partner_relation')->where([
                'partner_uid' => $uid, 'status' => 'active',
            ])->order('id desc')->find();
            $uid = (int)($relation['parent_uid'] ?? 0);
        }
        return 0;
    }

    private function partnerChain(int $uid): array
    {
        $chain = [];
        $seen = [];
        for ($depth = 0; $depth < 20 && $uid > 0; $depth++) {
            if (isset($seen[$uid])) {
                throw new ApiException('partner_relation_cycle_detected');
            }
            $seen[$uid] = true;
            $profile = Db::name('yfth_partner_profile')->where(['uid' => $uid, 'status' => 'active'])->find();
            if (!$profile) {
                break;
            }
            $rank = (string)$profile['rank_code'];
            if (isset(self::RANKS[$rank])) {
                $chain[] = [
                    'uid' => $uid,
                    'rank_code' => $rank,
                    'rank_name' => self::RANKS[$rank]['name'],
                    'rank_level' => self::RANKS[$rank]['level'],
                ];
            }
            $relation = Db::name('yfth_partner_relation')->where([
                'partner_uid' => $uid, 'status' => 'active',
            ])->order('id desc')->find();
            $uid = (int)($relation['parent_uid'] ?? 0);
        }
        usort($chain, function (array $left, array $right): int {
            return (int)$left['rank_level'] <=> (int)$right['rank_level'];
        });
        return $chain;
    }

    private function activeRule(bool $required): array
    {
        $rule = Db::name('yfth_partner_rule_version')->where([
            'status' => 'published', 'active_key' => 'published',
        ])->order('version_no desc')->find();
        if (!$rule && $required) {
            throw new ApiException('partner_rule_not_published');
        }
        return $rule ?: [];
    }

    private function rankRuleMap(int $ruleId): array
    {
        $rows = Db::name('yfth_partner_rank_rule')->where('rule_version_id', $ruleId)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['rank_code']] = $row;
        }
        return $map;
    }

    private function sumByStatus(string $table, string $uidField, int $uid): array
    {
        $result = ['pending_cent' => 0, 'settled_cent' => 0, 'reversed_cent' => 0];
        $rows = Db::name($table)->where($uidField, $uid)->field('status,SUM(amount_cent) AS amount_cent')->group('status')->select()->toArray();
        foreach ($rows as $row) {
            $status = (string)$row['status'];
            $amount = (int)$row['amount_cent'];
            if ($status === 'settled') {
                $result['settled_cent'] += $amount;
            } elseif ($status === 'reversed') {
                $result['reversed_cent'] += $amount;
            } else {
                $result['pending_cent'] += $amount;
            }
        }
        foreach ($result as $key => $value) {
            $result[str_replace('_cent', '', $key)] = number_format($value / 100, 2, '.', '');
        }
        return $result;
    }

    private function formatSnapshot(array $row): array
    {
        if (!$row) {
            return [];
        }
        $row['chain_snapshot'] = $this->decode((string)($row['chain_snapshot'] ?? ''));
        $row['rate_snapshot'] = $this->decode((string)($row['rate_snapshot'] ?? ''));
        return $row;
    }

    private function addressParts(string $address): array
    {
        $text = preg_replace('/[\[\]\"\\\\,，\s]+/u', ' ', $address) ?: '';
        preg_match('/([\x{4e00}-\x{9fa5}]{1,12}省)/u', $text, $province);
        preg_match('/([\x{4e00}-\x{9fa5}]{1,12}市)/u', $text, $city);
        preg_match('/([\x{4e00}-\x{9fa5}]{1,12}(?:区|县))/u', $text, $district);
        return [
            'province' => (string)($province[1] ?? ''),
            'city' => (string)($city[1] ?? ''),
            'district' => (string)($district[1] ?? ''),
        ];
    }

    private function page(array $filters): array
    {
        return [max(1, (int)($filters['page'] ?? 1)), max(1, min(100, (int)($filters['limit'] ?? 20)))];
    }

    private function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function insertImmutable(string $table, array $row): bool
    {
        try {
            return (int)Db::name($table)->insert($row) > 0;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ((string)$e->getCode() === '23000'
                || strpos($message, 'Duplicate entry') !== false
                || strpos($message, '1062') !== false) {
                return false;
            }
            throw $e;
        }
    }
}
