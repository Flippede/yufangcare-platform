<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

/**
 * YFTH automatic commission posting.
 *
 * The account projections are caches. yfth_commission_ledger is the immutable
 * authority and never writes CRMEB now_money/brokerage_price.
 */
class AutomaticCommissionServices
{
    public function ruleList(array $where = []): array
    {
        $query = Db::name('yfth_commission_rule_version');
        foreach (['status', 'scope_type', 'enabled'] as $field) {
            if (($where[$field] ?? '') !== '') $query->where($field, $where[$field]);
        }
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 20)));
        $count = (int)(clone $query)->count();
        return [
            'list' => $query->order('version_no desc,id desc')->page($page, $limit)->select()->toArray(),
            'count' => $count,
        ];
    }

    public function accrualList(array $where = []): array
    {
        $query = Db::name('yfth_commission_accrual');
        foreach (['status', 'source_type'] as $field) {
            if (($where[$field] ?? '') !== '') $query->where($field, (string)$where[$field]);
        }
        foreach (['store_id', 'c1_uid', 'order_id'] as $field) {
            if ((int)($where[$field] ?? 0) > 0) $query->where($field, (int)$where[$field]);
        }
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 20)));
        $count = (int)(clone $query)->count();
        $rows = $query->order('id desc')->page($page, $limit)->select()->toArray();
        foreach ($rows as &$row) {
            unset($row['snapshot_json'], $row['source_unique_key']);
            foreach (['base_amount_cent', 'c1_amount_cent', 'b1_amount_cent', 'reversed_c1_cent', 'reversed_b1_cent'] as $field) {
                $row[preg_replace('/_cent$/', '', $field)] = number_format((int)$row[$field] / 100, 2, '.', '');
            }
        }
        unset($row);
        return ['list' => $rows, 'count' => $count];
    }

    public function legacyCompatibilityReport(): array
    {
        $groups = Db::name('yfth_direct_referral_reward_candidate')
            ->field('candidate_type,status,COUNT(*) AS total')->group('candidate_type,status')->select()->toArray();
        return [
            'candidate_groups' => $groups,
            'automatic_accrual_count' => (int)Db::name('yfth_commission_accrual')->count(),
            'policy' => [
                'settled' => 'preserve_only',
                'cancelled' => 'preserve_only',
                'pending_confirmed' => 'manual_eligibility_review_required',
                'new_orders' => 'automatic_observation_and_credit',
            ],
        ];
    }

    public function saveRule(array $data, int $operatorUid): array
    {
        $scopeType = (string)($data['scope_type'] ?? 'all');
        $scopeId = (int)($data['scope_id'] ?? 0);
        $c1 = (int)($data['c1_ratio_bps'] ?? 0);
        $b1 = (int)($data['b1_ratio_bps'] ?? 0);
        $days = (int)($data['observation_days'] ?? 0);
        if (!in_array($scopeType, ['all', 'category', 'product'], true)
            || ($scopeType !== 'all' && $scopeId <= 0)
            || $c1 < 0 || $b1 < 0 || $c1 + $b1 > 10000
            || $days < 0 || $days > 365) {
            throw new ApiException('commission_rule_invalid');
        }
        $now = time();
        $row = [
            'rule_no' => $this->makeNo('YFCR'),
            'version_no' => (int)Db::name('yfth_commission_rule_version')->max('version_no') + 1,
            'scope_type' => $scopeType,
            'scope_id' => $scopeType === 'all' ? 0 : $scopeId,
            'c1_ratio_bps' => $c1,
            'b1_ratio_bps' => $b1,
            'observation_days' => $days,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'status' => 'draft',
            'effective_at' => max(0, (int)($data['effective_at'] ?? $now)),
            'expires_at' => max(0, (int)($data['expires_at'] ?? 0)),
            'active_key' => null,
            'note' => substr(trim((string)($data['note'] ?? '')), 0, 255),
            'created_uid' => $operatorUid,
            'published_uid' => 0,
            'published_at' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ];
        $row['id'] = (int)Db::name('yfth_commission_rule_version')->insertGetId($row);
        return $row;
    }

    public function publishRule(int $id, int $operatorUid): array
    {
        return Db::transaction(function () use ($id, $operatorUid) {
            $rule = $this->row(Db::name('yfth_commission_rule_version')->where('id', $id)->lock(true)->find());
            if (!$rule) throw new ApiException('commission_rule_not_found');
            if ((string)$rule['status'] === 'published') return $rule;
            $activeKey = (string)$rule['scope_type'] . ':' . (int)$rule['scope_id'];
            Db::name('yfth_commission_rule_version')->where('active_key', $activeKey)->update([
                'status' => 'retired', 'active_key' => null, 'update_time' => time(),
            ]);
            $update = [
                'status' => 'published', 'active_key' => $activeKey,
                'published_uid' => $operatorUid, 'published_at' => time(), 'update_time' => time(),
            ];
            Db::name('yfth_commission_rule_version')->where('id', $id)->update($update);
            return array_merge($rule, $update);
        });
    }

    public function snapshotMallOrderPaid(int $orderId): array
    {
        return Db::transaction(function () use ($orderId) {
            $existing = $this->row(Db::name('yfth_mall_commission_order_snapshot')->where('order_id', $orderId)->lock(true)->find());
            if ($existing) return ['snapshot' => $existing, 'created' => false];
            $order = $this->row(Db::name('store_order')->where('id', $orderId)->lock(true)->find());
            if (!$this->validMallOrder($order, true)) return ['reason' => 'mall_order_not_eligible'];
            if (Db::name('yfth_package_purchase')->where('order_id', $orderId)->count() > 0) {
                return ['reason' => 'package_order_excluded'];
            }

            $buyerUid = (int)$order['uid'];
            $attribution = $this->row(Db::name('yfth_hq_customer_attribution_current')
                ->where(['uid' => $buyerUid, 'status' => 'active'])->lock(true)->find());
            $storeId = (int)($attribution['store_id'] ?? 0);
            if ($storeId <= 0) return ['reason' => 'buyer_store_attribution_missing'];

            $candidate = $this->row(Db::name('yfth_direct_referral_reward_candidate')
                ->where('source_unique_key', hash('sha256', 'mall_consumption|store_order|' . $orderId))->find());
            $referrerUid = (int)($candidate['referrer_uid'] ?? 0);
            $relationId = (int)($candidate['relation_id'] ?? 0);
            if ($referrerUid <= 0) {
                $relation = $this->row(Db::name('yfth_hq_active_referral_current')
                    ->where(['referred_uid' => $buyerUid, 'status' => 'active', 'store_id' => $storeId])->find());
                $referrerUid = (int)($relation['referrer_uid'] ?? 0);
                $relationId = (int)($relation['id'] ?? 0);
            }
            if ($referrerUid === $buyerUid) {
                $referrerUid = 0;
                $relationId = 0;
            }

            $baseCent = max(0, $this->moneyToCents($order['pay_price']) - $this->moneyToCents($order['pay_postage'] ?? 0));
            if ($baseCent <= 0) return ['reason' => 'mall_order_commission_base_zero'];
            $items = $this->commissionItems($orderId, $baseCent);
            if (!$items) return ['reason' => 'mall_order_has_no_commission_rule'];
            $now = time();
            $row = [
                'snapshot_no' => $this->makeNo('YFMCS'), 'order_id' => $orderId,
                'order_sn' => (string)$order['order_id'], 'buyer_uid' => $buyerUid,
                'store_id' => $storeId, 'referrer_uid' => $referrerUid, 'relation_id' => $relationId,
                'pay_amount_cent' => $this->moneyToCents($order['pay_price']),
                'commission_base_cent' => $baseCent,
                'item_snapshot_json' => $this->json($items), 'status' => 'paid',
                'completed_at' => 0, 'due_at' => 0, 'refunded_amount_cent' => 0,
                'add_time' => $now, 'update_time' => $now,
            ];
            $row['id'] = (int)Db::name('yfth_mall_commission_order_snapshot')->insertGetId($row);
            if (!empty($candidate['id'])) {
                Db::name('yfth_direct_referral_reward_candidate')->where('id', (int)$candidate['id'])->update([
                    'status' => 'automatic_pending', 'update_time' => $now,
                ]);
            }
            return ['snapshot' => $row, 'created' => true];
        });
    }

    public function completeMallOrder(int $orderId): array
    {
        return Db::transaction(function () use ($orderId) {
            $snapshot = $this->row(Db::name('yfth_mall_commission_order_snapshot')->where('order_id', $orderId)->lock(true)->find());
            if (!$snapshot) {
                $created = $this->snapshotMallOrderPaid($orderId);
                $snapshot = (array)($created['snapshot'] ?? []);
            }
            if (!$snapshot) return ['reason' => 'mall_order_snapshot_missing'];
            if (in_array((string)$snapshot['status'], ['credited', 'cancelled'], true)) {
                return ['snapshot' => $snapshot, 'created' => false];
            }
            $order = $this->row(Db::name('store_order')->where('id', $orderId)->lock(true)->find());
            if (!$this->validMallOrder($order, false) || (int)$order['status'] < 2) {
                return ['reason' => 'mall_order_not_completed'];
            }
            $items = json_decode((string)$snapshot['item_snapshot_json'], true) ?: [];
            $refundCent = min((int)$snapshot['pay_amount_cent'], $this->moneyToCents($order['refund_price'] ?? 0));
            $payCent = max(1, (int)$snapshot['pay_amount_cent']);
            $now = time();
            $maxDue = $now;
            $ids = [];
            foreach ($items as $index => $item) {
                $sourceKey = hash('sha256', 'mall_order_item|' . $orderId . '|' . (int)$index . '|' . (int)$item['product_id']);
                $accrual = $this->row(Db::name('yfth_commission_accrual')->where('source_unique_key', $sourceKey)->lock(true)->find());
                if (!$accrual) {
                    $base = $this->remainingItemBase($orderId, $item, $refundCent, $payCent);
                    $c1Amount = (int)$snapshot['referrer_uid'] > 0 ? intdiv($base * (int)$item['c1_ratio_bps'], 10000) : 0;
                    $b1Amount = intdiv($base * (int)$item['b1_ratio_bps'], 10000);
                    $dueAt = $now + (int)$item['observation_days'] * 86400;
                    $row = [
                        'accrual_no' => $this->makeNo('YFCA'), 'source_type' => 'mall_order_item',
                        'source_id' => $orderId . ':' . $index, 'source_unique_key' => $sourceKey,
                        'candidate_id' => 0, 'order_id' => $orderId,
                        'product_id' => (int)$item['product_id'], 'category_id' => (int)$item['category_id'],
                        'c1_uid' => (int)$snapshot['referrer_uid'], 'buyer_uid' => (int)$snapshot['buyer_uid'],
                        'store_id' => (int)$snapshot['store_id'], 'base_amount_cent' => $base,
                        'c1_ratio_bps' => (int)$item['c1_ratio_bps'], 'b1_ratio_bps' => (int)$item['b1_ratio_bps'],
                        'c1_amount_cent' => $c1Amount, 'b1_amount_cent' => $b1Amount,
                        'rule_version_id' => (int)$item['rule_version_id'],
                        'status' => $base > 0 && ($c1Amount > 0 || $b1Amount > 0) ? 'observing' : 'cancelled',
                        'due_at' => $dueAt, 'credited_at' => 0,
                        'reversed_c1_cent' => 0, 'reversed_b1_cent' => 0,
                        'snapshot_json' => $this->json($item), 'add_time' => $now, 'update_time' => $now,
                    ];
                    $row['id'] = (int)Db::name('yfth_commission_accrual')->insertGetId($row);
                    $accrual = $row;
                }
                $ids[] = (int)$accrual['id'];
                $maxDue = max($maxDue, (int)$accrual['due_at']);
                if ((string)$accrual['status'] === 'observing' && (int)$accrual['due_at'] <= $now) {
                    $this->creditLockedAccrual($accrual);
                }
            }
            $statuses = $ids ? Db::name('yfth_commission_accrual')->whereIn('id', $ids)->column('status') : [];
            $hasObserving = in_array('observing', $statuses, true);
            $hasCredited = count(array_intersect($statuses, ['credited', 'partially_reversed', 'reversed'])) > 0;
            Db::name('yfth_mall_commission_order_snapshot')->where('id', (int)$snapshot['id'])->update([
                'status' => $hasObserving ? 'observing' : ($hasCredited ? 'credited' : 'cancelled'),
                'completed_at' => $now, 'due_at' => $maxDue,
                'refunded_amount_cent' => $refundCent, 'update_time' => $now,
            ]);
            $this->syncLegacyMallCandidate($orderId);
            return ['snapshot_id' => (int)$snapshot['id'], 'accrual_ids' => $ids];
        });
    }

    public function creditPackageCandidate(array $candidate): array
    {
        return Db::transaction(function () use ($candidate) {
            $candidateId = (int)($candidate['id'] ?? 0);
            $locked = $this->row(Db::name('yfth_direct_referral_reward_candidate')->where('id', $candidateId)->lock(true)->find());
            if (!$locked) throw new ApiException('package_reward_candidate_not_found');
            $sourceKey = hash('sha256', 'package_candidate|' . $candidateId);
            $accrual = $this->row(Db::name('yfth_commission_accrual')->where('source_unique_key', $sourceKey)->lock(true)->find());
            if (!$accrual) {
                $now = time();
                $observationDays = (int)Db::name('yfth_direct_referral_rule_version')
                    ->where('id', (int)$locked['rule_version_id'])->value('package_observation_days');
                $row = [
                    'accrual_no' => $this->makeNo('YFCA'), 'source_type' => 'package_candidate',
                    'source_id' => (string)$candidateId, 'source_unique_key' => $sourceKey,
                    'candidate_id' => $candidateId, 'order_id' => 0, 'product_id' => 0, 'category_id' => 0,
                    'c1_uid' => (int)$locked['referrer_uid'], 'buyer_uid' => (int)$locked['referred_uid'],
                    'store_id' => (int)$locked['store_id'],
                    'base_amount_cent' => (int)$locked['actual_paid_amount_cent'],
                    'c1_ratio_bps' => (int)$locked['ratio_bps'], 'b1_ratio_bps' => 0,
                    'c1_amount_cent' => (int)$locked['reward_amount_cent'], 'b1_amount_cent' => 0,
                    'rule_version_id' => (int)$locked['rule_version_id'], 'status' => 'observing',
                    'due_at' => $now + max(0, $observationDays) * 86400, 'credited_at' => 0,
                    'reversed_c1_cent' => 0, 'reversed_b1_cent' => 0,
                    'snapshot_json' => $this->json($locked), 'add_time' => $now, 'update_time' => $now,
                ];
                $row['id'] = (int)Db::name('yfth_commission_accrual')->insertGetId($row);
                $accrual = $row;
            }
            if ((int)$accrual['due_at'] <= time()) {
                $result = $this->creditLockedAccrual($accrual);
                $candidateStatus = 'credited';
            } else {
                $result = ['accrual' => $accrual, 'created' => false, 'observing' => true];
                $candidateStatus = 'automatic_pending';
            }
            if ((string)$locked['status'] !== $candidateStatus) {
                Db::name('yfth_direct_referral_reward_candidate')->where('id', $candidateId)->update([
                    'status' => $candidateStatus, 'update_time' => time(),
                ]);
            }
            return $result;
        });
    }

    public function reversePackageCandidate(array $candidate, array $snapshot = []): array
    {
        return Db::transaction(function () use ($candidate, $snapshot) {
            $accrual = $this->row(Db::name('yfth_commission_accrual')->where([
                'source_type' => 'package_candidate', 'candidate_id' => (int)$candidate['id'],
            ])->lock(true)->find());
            if (!$accrual) return ['changed' => false, 'reason' => 'automatic_accrual_missing'];
            if ((string)$accrual['status'] === 'observing') {
                Db::name('yfth_commission_accrual')->where('id', (int)$accrual['id'])->update([
                    'status' => 'cancelled', 'update_time' => time(),
                ]);
                return ['changed' => true, 'status' => 'cancelled', 'snapshot' => $snapshot];
            }
            return $this->reverseLockedAccrual($accrual, (int)$accrual['c1_amount_cent'], (int)$accrual['b1_amount_cent'], 'package_invalidated', $snapshot);
        });
    }

    public function refundMallOrder(int $orderId, array $payload = []): array
    {
        return Db::transaction(function () use ($orderId, $payload) {
            $snapshot = $this->row(Db::name('yfth_mall_commission_order_snapshot')->where('order_id', $orderId)->lock(true)->find());
            if (!$snapshot) return ['changed' => false, 'reason' => 'mall_order_snapshot_missing'];
            $order = $this->row(Db::name('store_order')->where('id', $orderId)->lock(true)->find());
            $payCent = max(1, (int)$snapshot['pay_amount_cent']);
            $refundCent = (int)($payload['refunded_amount_cent'] ?? 0);
            if ($refundCent <= 0) $refundCent = $this->moneyToCents($order['refund_price'] ?? 0);
            $refundCent = min($payCent, max((int)$snapshot['refunded_amount_cent'], $refundCent));
            Db::name('yfth_mall_commission_order_snapshot')->where('id', (int)$snapshot['id'])->update([
                'refunded_amount_cent' => $refundCent,
                'status' => $refundCent >= $payCent ? 'cancelled' : (string)$snapshot['status'],
                'update_time' => time(),
            ]);
            $accruals = Db::name('yfth_commission_accrual')->where('order_id', $orderId)->lock(true)->select()->toArray();
            $changed = false;
            foreach ($accruals as $accrual) {
                if ((string)$accrual['status'] === 'observing') {
                    $immutable = json_decode((string)$accrual['snapshot_json'], true) ?: [];
                    $originalBase = (int)($immutable['base_amount_cent'] ?? $accrual['base_amount_cent']);
                    $base = $this->remainingItemBase($orderId, $immutable, $refundCent, $payCent, $payload);
                    $update = [
                        'base_amount_cent' => $base,
                        'c1_amount_cent' => intdiv($base * (int)$accrual['c1_ratio_bps'], 10000),
                        'b1_amount_cent' => intdiv($base * (int)$accrual['b1_ratio_bps'], 10000),
                        'update_time' => time(),
                    ];
                    if ($base === 0) $update['status'] = 'cancelled';
                    Db::name('yfth_commission_accrual')->where('id', (int)$accrual['id'])->update($update);
                    $changed = true;
                    continue;
                }
                if (!in_array((string)$accrual['status'], ['credited', 'partially_reversed', 'reversed'], true)) continue;
                $immutable = json_decode((string)$accrual['snapshot_json'], true) ?: [];
                $originalBase = (int)($immutable['base_amount_cent'] ?? $accrual['base_amount_cent']);
                $remainingBase = $this->remainingItemBase($orderId, $immutable, $refundCent, $payCent, $payload);
                $desiredC1 = intdiv($remainingBase * (int)$accrual['c1_ratio_bps'], 10000);
                $desiredB1 = intdiv($remainingBase * (int)$accrual['b1_ratio_bps'], 10000);
                $targetC1 = max(0, (int)$accrual['c1_amount_cent'] - $desiredC1);
                $targetB1 = max(0, (int)$accrual['b1_amount_cent'] - $desiredB1);
                $deltaC1 = max(0, $targetC1 - (int)$accrual['reversed_c1_cent']);
                $deltaB1 = max(0, $targetB1 - (int)$accrual['reversed_b1_cent']);
                if ($deltaC1 > 0 || $deltaB1 > 0) {
                    $this->reverseLockedAccrual($accrual, $deltaC1, $deltaB1, 'mall_order_refund', $payload);
                    $changed = true;
                }
            }
            $this->syncLegacyMallCandidate($orderId);
            return ['changed' => $changed, 'refunded_amount_cent' => $refundCent];
        });
    }

    public function processDue(int $limit = 100): array
    {
        $ids = Db::name('yfth_commission_accrual')->where('status', 'observing')
            ->where('due_at', '<=', time())->order('due_at asc,id asc')->limit(max(1, min(500, $limit)))->column('id');
        $credited = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                Db::transaction(function () use ($id, &$credited) {
                    $row = $this->row(Db::name('yfth_commission_accrual')->where('id', (int)$id)->lock(true)->find());
                    if ($row && (string)$row['status'] === 'observing' && (int)$row['due_at'] <= time()) {
                        $this->creditLockedAccrual($row);
                        $credited++;
                    }
                });
                $orderId = (int)Db::name('yfth_commission_accrual')->where('id', (int)$id)->value('order_id');
                if ($orderId > 0) {
                    $this->syncMallSnapshotStatus($orderId);
                    $this->syncLegacyMallCandidate($orderId);
                }
                $candidateId = (int)Db::name('yfth_commission_accrual')->where('id', (int)$id)->value('candidate_id');
                if ($candidateId > 0) {
                    Db::name('yfth_direct_referral_reward_candidate')->where('id', $candidateId)->update([
                        'status' => 'credited', 'update_time' => time(),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed[] = ['id' => (int)$id, 'error' => $e->getMessage()];
            }
        }
        return ['scanned' => count($ids), 'credited' => $credited, 'failed' => $failed];
    }

    private function syncLegacyMallCandidate(int $orderId): void
    {
        $candidateId = (int)Db::name('yfth_direct_referral_reward_candidate')
            ->where('source_unique_key', hash('sha256', 'mall_consumption|store_order|' . $orderId))->value('id');
        if ($candidateId <= 0) return;
        $statuses = Db::name('yfth_commission_accrual')->where('order_id', $orderId)->column('status');
        if (!$statuses) return;
        if (!array_diff($statuses, ['cancelled', 'reversed'])) {
            $status = 'cancelled';
        } elseif (!array_intersect($statuses, ['observing'])) {
            $status = 'credited';
        } else {
            $status = 'automatic_pending';
        }
        Db::name('yfth_direct_referral_reward_candidate')->where('id', $candidateId)->update([
            'status' => $status, 'update_time' => time(),
        ]);
    }

    private function syncMallSnapshotStatus(int $orderId): void
    {
        $snapshotId = (int)Db::name('yfth_mall_commission_order_snapshot')->where('order_id', $orderId)->value('id');
        if ($snapshotId <= 0) return;
        $statuses = Db::name('yfth_commission_accrual')->where('order_id', $orderId)->column('status');
        if (!$statuses) return;
        if (array_intersect($statuses, ['observing'])) {
            $status = 'observing';
        } elseif (array_intersect($statuses, ['credited', 'partially_reversed', 'reversed'])) {
            $status = 'credited';
        } else {
            $status = 'cancelled';
        }
        Db::name('yfth_mall_commission_order_snapshot')->where('id', $snapshotId)->update([
            'status' => $status, 'update_time' => time(),
        ]);
    }

    private function creditLockedAccrual(array $accrual): array
    {
        if ((string)$accrual['status'] === 'credited') return ['accrual' => $accrual, 'created' => false];
        if ((string)$accrual['status'] !== 'observing' || (int)$accrual['due_at'] > time()) {
            throw new ApiException('commission_accrual_not_due');
        }
        $c1 = (int)$accrual['c1_amount_cent'];
        $b1 = (int)$accrual['b1_amount_cent'];
        if ($c1 > 0 && (int)$accrual['c1_uid'] > 0) {
            $this->postUser((int)$accrual['c1_uid'], $c1, 'commission_credit', (int)$accrual['id'], 'credit');
            $this->postStore((int)$accrual['store_id'], $c1, 'commission_c1_responsibility_credit', (int)$accrual['id'], 'c1-credit');
        }
        if ($b1 > 0) {
            $this->postStore((int)$accrual['store_id'], $b1, 'commission_b1_credit', (int)$accrual['id'], 'b1-credit');
        }
        $this->syncStoreC1Pending((int)$accrual['store_id']);
        $update = ['status' => 'credited', 'credited_at' => time(), 'update_time' => time()];
        Db::name('yfth_commission_accrual')->where('id', (int)$accrual['id'])->update($update);
        return ['accrual' => array_merge($accrual, $update), 'created' => true];
    }

    private function reverseLockedAccrual(array $accrual, int $c1Cent, int $b1Cent, string $reason, array $snapshot): array
    {
        $c1Cent = min($c1Cent, max(0, (int)$accrual['c1_amount_cent'] - (int)$accrual['reversed_c1_cent']));
        $b1Cent = min($b1Cent, max(0, (int)$accrual['b1_amount_cent'] - (int)$accrual['reversed_b1_cent']));
        $sequence = (int)$accrual['reversed_c1_cent'] . ':' . (int)$accrual['reversed_b1_cent'];
        if ($c1Cent > 0 && (int)$accrual['c1_uid'] > 0) {
            $this->postUser((int)$accrual['c1_uid'], -$c1Cent, $reason, (int)$accrual['id'], 'reverse:' . $sequence);
            $this->postStore((int)$accrual['store_id'], -$c1Cent, $reason . '_c1_responsibility', (int)$accrual['id'], 'c1-reverse:' . $sequence);
        }
        if ($b1Cent > 0) {
            $this->postStore((int)$accrual['store_id'], -$b1Cent, $reason . '_b1', (int)$accrual['id'], 'b1-reverse:' . $sequence);
        }
        $this->syncStoreC1Pending((int)$accrual['store_id']);
        $reversedC1 = (int)$accrual['reversed_c1_cent'] + $c1Cent;
        $reversedB1 = (int)$accrual['reversed_b1_cent'] + $b1Cent;
        $fully = $reversedC1 >= (int)$accrual['c1_amount_cent'] && $reversedB1 >= (int)$accrual['b1_amount_cent'];
        $update = [
            'reversed_c1_cent' => $reversedC1, 'reversed_b1_cent' => $reversedB1,
            'status' => $fully ? 'reversed' : 'partially_reversed', 'update_time' => time(),
        ];
        Db::name('yfth_commission_accrual')->where('id', (int)$accrual['id'])->update($update);
        return ['changed' => $c1Cent > 0 || $b1Cent > 0, 'snapshot' => $snapshot] + $update;
    }

    private function postUser(int $uid, int $delta, string $sourceType, int $sourceId, string $suffix): int
    {
        if ($uid <= 0 || $delta === 0) return 0;
        $key = hash('sha256', 'user|' . $uid . '|' . $sourceType . '|' . $sourceId . '|' . $suffix);
        $existing = (int)Db::name('yfth_commission_ledger')->where('source_unique_key', $key)->value('id');
        if ($existing > 0) return $existing;
        $account = $this->lockUserAccount($uid);
        $after = (int)$account['available_cent'] + $delta;
        Db::name('yfth_user_commission_account')->where('id', (int)$account['id'])->update([
            'available_cent' => $after, 'version' => (int)$account['version'] + 1, 'update_time' => time(),
        ]);
        $meta = $this->ledgerMetaFromAccrual($sourceId);
        $reverseLedgerId = $delta < 0 ? (int)Db::name('yfth_commission_ledger')->where([
            'account_type' => 'user', 'account_id' => $uid, 'bucket' => 'c1_commission',
            'source_id' => (string)$sourceId, 'direction' => 'credit',
        ])->value('id') : 0;
        return (int)Db::name('yfth_commission_ledger')->insertGetId([
            'ledger_no' => $this->makeNo('YFCL'), 'account_type' => 'user', 'account_id' => $uid,
            'bucket' => 'c1_commission', 'direction' => $delta > 0 ? 'credit' : 'debit',
            'amount_cent' => abs($delta), 'balance_before_cent' => (int)$account['available_cent'],
            'balance_after_cent' => $after, 'available_after_cent' => $after,
            'frozen_after_cent' => (int)$account['frozen_cent'], 'withdrawn_after_cent' => (int)$account['withdrawn_cent'],
            'source_type' => $sourceType, 'source_id' => (string)$sourceId,
            'source_order_id' => $meta['source_order_id'], 'source_order_item_id' => $meta['source_order_item_id'],
            'rule_version_id' => $meta['rule_version_id'], 'c1_ratio_bps' => $meta['c1_ratio_bps'],
            'b1_ratio_bps' => $meta['b1_ratio_bps'], 'reverse_ledger_id' => $reverseLedgerId,
            'source_unique_key' => $key, 'reason' => $sourceType, 'snapshot_json' => $this->json($meta),
            'operator_uid' => 0, 'add_time' => time(),
        ]);
    }

    private function postStore(int $storeId, int $delta, string $sourceType, int $sourceId, string $suffix): int
    {
        if ($storeId <= 0 || $delta === 0) return 0;
        $bucket = 'store_commission';
        $key = hash('sha256', 'store|' . $storeId . '|' . $sourceType . '|' . $sourceId . '|' . $suffix);
        $existing = (int)Db::name('yfth_commission_ledger')->where('source_unique_key', $key)->value('id');
        if ($existing > 0) return $existing;
        $account = $this->lockStoreAccount($storeId);
        $after = (int)$account['unsettled_cent'] + $delta;
        $update = ['unsettled_cent' => $after, 'version' => (int)$account['version'] + 1, 'update_time' => time()];
        if ($delta < 0) $update['reversed_cent'] = (int)$account['reversed_cent'] + abs($delta);
        Db::name('yfth_store_commission_account')->where('id', (int)$account['id'])->update($update);
        $meta = $this->ledgerMetaFromAccrual($sourceId);
        $reverseLedgerId = $delta < 0 ? (int)Db::name('yfth_commission_ledger')->where([
            'account_type' => 'store', 'account_id' => $storeId, 'bucket' => $bucket,
            'source_id' => (string)$sourceId, 'direction' => 'credit',
        ])->value('id') : 0;
        return (int)Db::name('yfth_commission_ledger')->insertGetId([
            'ledger_no' => $this->makeNo('YFCL'), 'account_type' => 'store', 'account_id' => $storeId,
            'bucket' => $bucket, 'direction' => $delta > 0 ? 'credit' : 'debit',
            'amount_cent' => abs($delta), 'balance_before_cent' => (int)$account['unsettled_cent'],
            'balance_after_cent' => $after, 'available_after_cent' => $after,
            'frozen_after_cent' => 0, 'withdrawn_after_cent' => (int)$account['settled_cent'],
            'source_type' => $sourceType, 'source_id' => (string)$sourceId,
            'source_order_id' => $meta['source_order_id'], 'source_order_item_id' => $meta['source_order_item_id'],
            'rule_version_id' => $meta['rule_version_id'], 'c1_ratio_bps' => $meta['c1_ratio_bps'],
            'b1_ratio_bps' => $meta['b1_ratio_bps'], 'reverse_ledger_id' => $reverseLedgerId,
            'source_unique_key' => $key, 'reason' => $sourceType, 'snapshot_json' => $this->json($meta),
            'operator_uid' => 0, 'add_time' => time(),
        ]);
    }

    private function syncStoreC1Pending(int $storeId): void
    {
        if ($storeId <= 0) return;
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
            if (!$this->isUniqueConflict($e)) throw $e;
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
                'store_id' => $storeId, 'unsettled_cent' => 0, 'settled_cent' => 0, 'c1_pending_cent' => 0,
                'c1_paid_cent' => 0, 'reversed_cent' => 0, 'version' => 0,
                'add_time' => $now, 'update_time' => $now,
            ]);
        } catch (\Throwable $e) {
            if (!$this->isUniqueConflict($e)) throw $e;
        }
        return $this->row(Db::name('yfth_store_commission_account')->where('store_id', $storeId)->lock(true)->find());
    }

    private function commissionItems(int $orderId, int $baseCent): array
    {
        $rows = Db::name('store_order_cart_info')->where('oid', $orderId)->order('id asc')->select()->toArray();
        if (!$rows) return [];
        $raw = [];
        $totalWeight = 0;
        foreach ($rows as $row) {
            $cart = is_array($row['cart_info']) ? $row['cart_info'] : json_decode((string)$row['cart_info'], true);
            $cart = is_array($cart) ? $cart : [];
            $productId = (int)($row['product_id'] ?? $cart['product_id'] ?? $cart['productInfo']['id'] ?? 0);
            $category = (string)Db::name('store_product')->where('id', $productId)->value('cate_id');
            $categoryId = (int)(preg_split('/[,|]/', $category)[0] ?? 0);
            $weight = $this->moneyToCents($cart['sum_true_price'] ?? bcmul((string)($cart['truePrice'] ?? 0), (string)($cart['cart_num'] ?? 1), 2));
            $rule = $this->activeMallRule($productId, $categoryId);
            if ($weight <= 0) continue;
            $orderItemId = (int)($row['id'] ?? 0);
            $cartNum = max(1, (int)($row['cart_num'] ?? $cart['cart_num'] ?? 1));
            $raw[] = compact('productId', 'categoryId', 'weight', 'rule', 'orderItemId', 'cartNum');
            $totalWeight += $weight;
        }
        if (!$raw || $totalWeight <= 0) return [];
        $allocated = 0;
        $items = [];
        foreach ($raw as $index => $entry) {
            $amount = $index === count($raw) - 1 ? $baseCent - $allocated : intdiv($baseCent * $entry['weight'], $totalWeight);
            $allocated += $amount;
            $rule = $entry['rule'];
            if (!$rule) continue;
            $items[] = [
                'order_item_id' => $entry['orderItemId'], 'cart_num' => $entry['cartNum'],
                'product_id' => $entry['productId'], 'category_id' => $entry['categoryId'],
                'base_amount_cent' => max(0, $amount), 'c1_ratio_bps' => (int)$rule['c1_ratio_bps'],
                'b1_ratio_bps' => (int)$rule['b1_ratio_bps'],
                'observation_days' => (int)$rule['observation_days'], 'rule_version_id' => (int)$rule['id'],
                'rule_scope_type' => (string)$rule['scope_type'], 'rule_scope_id' => (int)$rule['scope_id'],
            ];
        }
        return $items;
    }

    private function activeMallRule(int $productId, int $categoryId): array
    {
        $now = time();
        foreach ([['product', $productId], ['category', $categoryId], ['all', 0]] as $scope) {
            if ($scope[0] !== 'all' && $scope[1] <= 0) continue;
            $query = Db::name('yfth_commission_rule_version')->where([
                'scope_type' => $scope[0], 'scope_id' => $scope[1], 'status' => 'published', 'enabled' => 1,
            ])->where('effective_at', '<=', $now)->where(function ($query) use ($now) {
                $query->where('expires_at', 0)->whereOr('expires_at', '>', $now);
            })->order('version_no desc,id desc');
            $row = $this->row($query->find());
            if ($row) return $row;
        }
        return [];
    }

    private function remainingItemBase(int $orderId, array $item, int $refundCent, int $payCent, array $payload = []): int
    {
        $originalBase = max(0, (int)($item['base_amount_cent'] ?? 0));
        if ($originalBase <= 0) return 0;
        $orderItemId = (int)($item['order_item_id'] ?? 0);
        $itemRefunds = (array)($payload['refunded_item_amounts_cent'] ?? []);
        if ($orderItemId > 0 && array_key_exists((string)$orderItemId, $itemRefunds)) {
            return max(0, $originalBase - min($originalBase, max(0, (int)$itemRefunds[(string)$orderItemId])));
        }
        if ($orderItemId > 0) {
            $orderItem = $this->row(Db::name('store_order_cart_info')->where([
                'id' => $orderItemId, 'oid' => $orderId,
            ])->field('cart_num,refund_num')->find());
            $cartNum = max(1, (int)($item['cart_num'] ?? $orderItem['cart_num'] ?? 1));
            $refundNum = min($cartNum, max(0, (int)($orderItem['refund_num'] ?? 0)));
            if ($refundNum > 0) {
                return intdiv($originalBase * ($cartNum - $refundNum), $cartNum);
            }
        }
        return intdiv($originalBase * max(0, $payCent - $refundCent), max(1, $payCent));
    }

    private function validMallOrder(array $order, bool $requireUnrefunded): bool
    {
        if (!$order) return false;
        return (int)($order['paid'] ?? 0) === 1
            && (int)($order['pid'] ?? 0) === 0
            && (int)($order['is_del'] ?? 0) === 0
            && (int)($order['is_system_del'] ?? 0) === 0
            && (int)($order['is_cancel'] ?? 0) === 0
            && (!$requireUnrefunded || (int)($order['refund_status'] ?? 0) === 0)
            && !in_array((int)($order['status'] ?? 0), [-2, -1], true);
    }

    private function ledgerMetaFromAccrual(int $accrualId): array
    {
        $row = $this->row(Db::name('yfth_commission_accrual')->where('id', $accrualId)->find());
        return [
            'source_order_id' => (int)($row['order_id'] ?? 0),
            'source_order_item_id' => (string)($row['source_id'] ?? ''),
            'rule_version_id' => (int)($row['rule_version_id'] ?? 0),
            'c1_ratio_bps' => (int)($row['c1_ratio_bps'] ?? 0),
            'b1_ratio_bps' => (int)($row['b1_ratio_bps'] ?? 0),
            'accrual_no' => (string)($row['accrual_no'] ?? ''),
        ];
    }

    private function moneyToCents($value): int
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new ApiException('money_snapshot_invalid');
        }
        return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
