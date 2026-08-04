<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

class MemberPointsServices
{
    public function creditAccrual(array $accrual, int $pointCent, string $suffix = 'credit'): array
    {
        return $this->post(
            (int)$accrual['c1_uid'],
            $pointCent,
            'automatic_reward',
            (string)$accrual['id'],
            $suffix,
            $accrual
        );
    }

    public function reverseAccrual(array $accrual, int $pointCent, string $reason, string $suffix): array
    {
        return $this->post(
            (int)$accrual['c1_uid'],
            -$pointCent,
            $reason,
            (string)$accrual['id'],
            $suffix,
            $accrual
        );
    }

    public function summary(int $uid): array
    {
        $account = $this->account($uid);
        $observing = (int)Db::name('yfth_commission_accrual')->where('c1_uid', $uid)
            ->where('status', 'observing')->sum('c1_amount_cent');
        return [
            'points' => (int)Db::name('user')->where('uid', $uid)->value('integral'),
            'reward_point_cent' => (int)$account['balance_point_cent'],
            'reward_points' => $this->formatPoints((int)$account['balance_point_cent']),
            'observing_point_cent' => $observing,
            'observing_points' => $this->formatPoints($observing),
            'integral_debt' => (int)$account['integral_debt'],
            'notice' => '推荐奖励统一发放为商城积分，积分不能提现。',
        ];
    }

    public function ledger(int $uid, array $where = []): array
    {
        $query = Db::name('yfth_member_points_ledger')->where('uid', $uid);
        if (!empty($where['source_type'])) $query->where('source_type', (string)$where['source_type']);
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 20)));
        $count = (int)(clone $query)->count();
        $rows = $query->order('id desc')->page($page, $limit)->select()->toArray();
        foreach ($rows as &$row) {
            $row['points'] = $this->formatPoints((int)$row['point_cent']);
            $row['balance_points'] = $this->formatPoints((int)$row['balance_after_point_cent']);
            unset($row['snapshot_json'], $row['source_unique_key']);
        }
        unset($row);
        return ['list' => $rows, 'count' => $count];
    }

    public function config(): array
    {
        $row = Db::name('yfth_member_points_config')->where('config_key', 'default')->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : [
            'config_key' => 'default', 'enabled' => 1, 'point_yuan_cent_per_point' => 100,
            'max_deduction_bps' => 9900, 'min_cash_cent' => 10,
        ];
    }

    public function saveConfig(array $data, int $operatorUid): array
    {
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $maxBps = (int)($data['max_deduction_bps'] ?? 9900);
        $minCash = (int)($data['min_cash_cent'] ?? 10);
        if ($maxBps < 0 || $maxBps > 9999 || $minCash < 10) {
            throw new ApiException('member_points_config_invalid');
        }
        $values = [
            'enabled' => $enabled,
            'point_yuan_cent_per_point' => 100,
            'max_deduction_bps' => $maxBps,
            'min_cash_cent' => $minCash,
            'operator_uid' => $operatorUid,
            'update_time' => time(),
        ];
        if (Db::name('yfth_member_points_config')->where('config_key', 'default')->count()) {
            Db::name('yfth_member_points_config')->where('config_key', 'default')->update($values);
        } else {
            Db::name('yfth_member_points_config')->insert(array_merge($values, [
                'config_key' => 'default', 'add_time' => time(),
            ]));
        }
        return array_merge($this->config(), $values);
    }

    public function deductionPolicy(array $cartInfo, string $payPrice): array
    {
        $config = $this->config();
        if (empty($config['enabled']) || !$this->isEligibleCart($cartInfo)) {
            return ['eligible' => false, 'max_deduction' => '0.00', 'min_cash' => '0.10'];
        }
        $payCent = max(0, (int)round(((float)$payPrice) * 100));
        $minCashCent = max(10, (int)$config['min_cash_cent']);
        $ratioCap = intdiv($payCent * (int)$config['max_deduction_bps'], 10000);
        $cashCap = max(0, $payCent - $minCashCent);
        $maxCent = min($ratioCap, $cashCap);
        return [
            'eligible' => true,
            'max_deduction' => number_format($maxCent / 100, 2, '.', ''),
            'min_cash' => number_format($minCashCent / 100, 2, '.', ''),
            'point_yuan_ratio' => '1.00',
        ];
    }

    public function convertLegacyBalances(int $limit = 500): array
    {
        $uids = Db::name('yfth_user_commission_account')->where('available_cent', '<>', 0)
            ->whereOr('frozen_cent', '<>', 0)->limit(max(1, $limit))->column('uid');
        $converted = 0;
        foreach ($uids as $uid) {
            Db::transaction(function () use ($uid, &$converted) {
                $legacy = $this->row(Db::name('yfth_user_commission_account')->where('uid', (int)$uid)->lock(true)->find());
                $cent = max(0, (int)$legacy['available_cent'] + (int)$legacy['frozen_cent']);
                if ($cent <= 0) return;
                $this->post((int)$uid, $cent, 'legacy_c1_balance_conversion', (string)$uid, 'v1', $legacy);
                Db::name('yfth_user_commission_account')->where('uid', (int)$uid)->update([
                    'available_cent' => 0, 'frozen_cent' => 0,
                    'version' => (int)$legacy['version'] + 1, 'update_time' => time(),
                ]);
                Db::name('yfth_member_points_account')->where('uid', (int)$uid)->update([
                    'legacy_converted_at' => time(), 'update_time' => time(),
                ]);
                Db::name('yfth_c1_settlement_request')->where('uid', (int)$uid)->where('status', 'pending')->update([
                    'status' => 'cancelled', 'remark' => 'C端奖励已统一转换为积分', 'update_time' => time(),
                ]);
                $converted++;
            });
        }
        if ($converted > 0) {
            Db::name('yfth_store_commission_account')->where('c1_pending_cent', '<>', 0)->update([
                'c1_pending_cent' => 0, 'update_time' => time(),
            ]);
        }
        return ['converted_accounts' => $converted];
    }

    private function post(int $uid, int $deltaPointCent, string $sourceType, string $sourceId, string $suffix, array $snapshot): array
    {
        if ($uid <= 0 || $deltaPointCent === 0) return ['changed' => false];
        $key = hash('sha256', 'member_points|' . $uid . '|' . $sourceType . '|' . $sourceId . '|' . $suffix);
        $existing = $this->row(Db::name('yfth_member_points_ledger')->where('source_unique_key', $key)->find());
        if ($existing) return ['changed' => false, 'ledger' => $existing];

        $account = $this->lockAccount($uid);
        $user = $this->row(Db::name('user')->where('uid', $uid)->lock(true)->find());
        if (!$user) throw new ApiException('member_points_user_missing');
        $beforeCent = (int)$account['balance_point_cent'];
        $afterCent = $beforeCent + $deltaPointCent;
        $oldTarget = (int)$account['issued_integral'];
        $newTarget = intdiv(max(0, $afterCent), 100);
        $grossDelta = $newTarget - $oldTarget;
        $debt = (int)$account['integral_debt'];
        $integralDelta = 0;
        if ($grossDelta > 0) {
            $offset = min($grossDelta, $debt);
            $debt -= $offset;
            $integralDelta = $grossDelta - $offset;
        } elseif ($grossDelta < 0) {
            $need = abs($grossDelta);
            $available = max(0, (int)$user['integral']);
            $deduct = min($need, $available);
            $integralDelta = -$deduct;
            $debt += $need - $deduct;
        }
        $newIntegral = max(0, (int)$user['integral'] + $integralDelta);
        if ($integralDelta !== 0) {
            Db::name('user')->where('uid', $uid)->update(['integral' => $newIntegral]);
            Db::name('user_bill')->insert([
                'uid' => $uid, 'link_id' => (int)$sourceId, 'pm' => $integralDelta > 0 ? 1 : 0,
                'title' => $integralDelta > 0 ? '御方通和推荐积分' : '御方通和推荐积分冲正',
                'category' => 'integral', 'type' => $integralDelta > 0 ? 'yfth_reward_points' : 'yfth_reward_points_reverse',
                'number' => abs($integralDelta), 'balance' => $newIntegral,
                'mark' => $integralDelta > 0 ? '推荐奖励转换为商城积分' : '退款或业务冲正扣回推荐积分',
                'add_time' => time(), 'status' => 1, 'take' => 0, 'frozen_time' => 0,
            ]);
        }
        Db::name('yfth_member_points_account')->where('id', (int)$account['id'])->update([
            'balance_point_cent' => $afterCent, 'issued_integral' => $newTarget,
            'integral_debt' => $debt, 'version' => (int)$account['version'] + 1, 'update_time' => time(),
        ]);
        $ledger = [
            'ledger_no' => 'YFMP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12)),
            'uid' => $uid, 'direction' => $deltaPointCent > 0 ? 'credit' : 'debit',
            'point_cent' => abs($deltaPointCent), 'balance_before_point_cent' => $beforeCent,
            'balance_after_point_cent' => $afterCent, 'integral_delta' => $integralDelta,
            'integral_debt_after' => $debt, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'source_order_id' => (int)($snapshot['order_id'] ?? 0),
            'rule_version_id' => (int)($snapshot['rule_version_id'] ?? 0),
            'ratio_bps' => (int)($snapshot['c1_ratio_bps'] ?? 0), 'source_unique_key' => $key,
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'add_time' => time(),
        ];
        $ledger['id'] = (int)Db::name('yfth_member_points_ledger')->insertGetId($ledger);
        return ['changed' => true, 'ledger' => $ledger, 'integral_delta' => $integralDelta];
    }

    private function isEligibleCart(array $cartInfo): bool
    {
        if (!$cartInfo) return false;
        $productIds = [];
        foreach ($cartInfo as $cart) {
            if (($cart['yfth_channel'] ?? '') === 'procurement') return false;
            $productIds[] = (int)($cart['product_id'] ?? $cart['productInfo']['id'] ?? 0);
        }
        $productIds = array_values(array_filter(array_unique($productIds)));
        if (!$productIds) return false;
        if (Db::name('yfth_package_product_binding')->whereIn('product_id', $productIds)->where('binding_status', 'active')->count() > 0) {
            return false;
        }
        $disabled = (int)Db::name('yfth_member_points_product_rule')->whereIn('product_id', $productIds)->where('enabled', 0)->count();
        return $disabled === 0;
    }

    private function lockAccount(int $uid): array
    {
        $row = $this->row(Db::name('yfth_member_points_account')->where('uid', $uid)->lock(true)->find());
        if ($row) return $row;
        try {
            Db::name('yfth_member_points_account')->insert([
                'uid' => $uid, 'balance_point_cent' => 0, 'issued_integral' => 0, 'integral_debt' => 0,
                'legacy_converted_at' => 0, 'version' => 0, 'add_time' => time(), 'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'Duplicate') === false) throw $e;
        }
        return $this->row(Db::name('yfth_member_points_account')->where('uid', $uid)->lock(true)->find());
    }

    private function account(int $uid): array
    {
        $row = $this->row(Db::name('yfth_member_points_account')->where('uid', $uid)->find());
        return $row ?: ['uid' => $uid, 'balance_point_cent' => 0, 'issued_integral' => 0, 'integral_debt' => 0];
    }

    private function formatPoints(int $pointCent): string
    {
        return rtrim(rtrim(number_format($pointCent / 100, 2, '.', ''), '0'), '.');
    }

    private function row($row): array
    {
        if (!$row) return [];
        return is_array($row) ? $row : $row->toArray();
    }
}
