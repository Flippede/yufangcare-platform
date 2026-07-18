<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;
use think\facade\Db;

class ProductQuotaPurchaseServices extends YfthFoundationBaseServices
{
    public function reserve(int $purchaseOrderId, int $storeId, int $orderCent, int $requestedQuotaCent, string $key): array
    {
        $requestedQuotaCent = min(max(0, $requestedQuotaCent), $orderCent);
        return Db::transaction(function () use ($purchaseOrderId, $storeId, $orderCent, $requestedQuotaCent, $key) {
            $existing = Db::name('yfth_product_quota_reservation')->where('purchase_order_id', $purchaseOrderId)->lock(true)->find();
            if ($existing) return $existing;
            $account = Db::name('yfth_product_quota_account')->where('active_key', 'store:' . $storeId . ':return_goods')->lock(true)->find();
            if ($requestedQuotaCent > 0 && (!$account || (string)$account['status'] !== 'active')) throw new ApiException('product_quota_account_not_active');
            if ($requestedQuotaCent > (int)($account['available_cent'] ?? 0)) throw new ApiException('product_quota_available_insufficient');
            $now = time();
            if ($requestedQuotaCent > 0) {
                Db::name('yfth_product_quota_account')->where('id', (int)$account['id'])->update([
                    'available_cent' => (int)$account['available_cent'] - $requestedQuotaCent,
                    'reserved_cent' => (int)$account['reserved_cent'] + $requestedQuotaCent,
                    'version' => (int)$account['version'] + 1, 'update_time' => $now,
                ]);
                $this->ledger($account, 'reserve', $requestedQuotaCent, (int)$account['available_cent'], (int)$account['available_cent'] - $requestedQuotaCent, $purchaseOrderId, $key . ':reserve');
            }
            $row = [
                'reservation_no' => $this->makeNo('PQR'), 'purchase_order_id' => $purchaseOrderId,
                'account_id' => (int)($account['id'] ?? 0), 'store_id' => $storeId,
                'order_amount_cent' => $orderCent, 'quota_amount_cent' => $requestedQuotaCent,
                'online_amount_cent' => $orderCent - $requestedQuotaCent, 'used_cent' => 0,
                'released_cent' => 0, 'refunded_cent' => 0, 'reversed_cent' => 0,
                'status' => $requestedQuotaCent > 0 ? 'reserved' : 'online_only',
                'idempotency_key' => $key, 'create_time' => $now, 'update_time' => $now,
            ];
            $row['id'] = (int)Db::name('yfth_product_quota_reservation')->insertGetId($row);
            return $row;
        });
    }

    public function useForStockIn(int $purchaseOrderId): array
    {
        return $this->mutate($purchaseOrderId, 'use', function (array $reservation, array $account) {
            $amount = (int)$reservation['quota_amount_cent'] - (int)$reservation['used_cent'] - (int)$reservation['released_cent'];
            if ($amount <= 0) return [$reservation, $account, 0];
            if ((int)$account['reserved_cent'] < $amount) throw new ApiException('product_quota_reserved_inconsistent');
            $account['reserved_cent'] -= $amount; $account['consumed_cent'] += $amount;
            $reservation['used_cent'] += $amount; $reservation['status'] = 'used';
            return [$reservation, $account, $amount];
        });
    }

    public function release(int $purchaseOrderId, string $reason = 'purchase_rejected'): array
    {
        return $this->mutate($purchaseOrderId, 'release', function (array $reservation, array $account) {
            $amount = (int)$reservation['quota_amount_cent'] - (int)$reservation['used_cent'] - (int)$reservation['released_cent'];
            if ($amount <= 0) return [$reservation, $account, 0];
            $account['reserved_cent'] -= $amount; $account['available_cent'] += $amount;
            $reservation['released_cent'] += $amount; $reservation['status'] = 'released';
            return [$reservation, $account, $amount];
        }, $reason);
    }

    public function refundUsed(int $purchaseOrderId, int $amountCent, string $reason = 'purchase_after_sale_return'): array
    {
        return $this->mutate($purchaseOrderId, 'refund', function (array $reservation, array $account) use ($amountCent) {
            $remaining = (int)$reservation['used_cent'] - (int)$reservation['refunded_cent'] - (int)$reservation['reversed_cent'];
            $amount = min(max(0, $amountCent), $remaining);
            if ($amount <= 0) return [$reservation, $account, 0];
            $account['consumed_cent'] -= $amount; $account['available_cent'] += $amount;
            $reservation['refunded_cent'] += $amount;
            $reservation['status'] = $amount === $remaining ? 'refunded' : 'partially_refunded';
            return [$reservation, $account, $amount];
        }, $reason);
    }

    public function reverseRefund(int $purchaseOrderId, int $amountCent, string $reason = 'purchase_refund_reversal'): array
    {
        return $this->mutate($purchaseOrderId, 'refund_reversal', function (array $reservation, array $account) use ($amountCent) {
            $amount = min(max(0, $amountCent), (int)$reservation['refunded_cent'] - (int)$reservation['reversed_cent']);
            if ($amount <= 0) return [$reservation, $account, 0];
            if ((int)$account['available_cent'] < $amount) throw new ApiException('product_quota_refund_reversal_insufficient');
            $account['available_cent'] -= $amount; $account['consumed_cent'] += $amount;
            $reservation['reversed_cent'] += $amount; $reservation['status'] = 'used';
            return [$reservation, $account, $amount];
        }, $reason);
    }

    private function mutate(int $orderId, string $action, callable $callback, string $reason = ''): array
    {
        return Db::transaction(function () use ($orderId, $action, $callback, $reason) {
            $reservation = Db::name('yfth_product_quota_reservation')->where('purchase_order_id', $orderId)->lock(true)->find();
            if (!$reservation || (int)$reservation['quota_amount_cent'] <= 0) return $reservation ?: [];
            $account = Db::name('yfth_product_quota_account')->where('id', (int)$reservation['account_id'])->lock(true)->find();
            if (!$account) throw new ApiException('product_quota_account_not_found');
            $before = (int)$account['available_cent'];
            [$reservation, $account, $amount] = $callback($reservation, $account);
            if ($amount <= 0) return $reservation;
            $now = time();
            Db::name('yfth_product_quota_account')->where('id', (int)$account['id'])->update([
                'available_cent' => (int)$account['available_cent'], 'reserved_cent' => (int)$account['reserved_cent'],
                'consumed_cent' => (int)$account['consumed_cent'], 'version' => (int)$account['version'] + 1, 'update_time' => $now,
            ]);
            Db::name('yfth_product_quota_reservation')->where('id', (int)$reservation['id'])->update([
                'used_cent' => (int)$reservation['used_cent'], 'released_cent' => (int)$reservation['released_cent'],
                'refunded_cent' => (int)$reservation['refunded_cent'], 'reversed_cent' => (int)$reservation['reversed_cent'],
                'status' => (string)$reservation['status'], 'update_time' => $now,
            ]);
            $cumulative = implode(':', [
                (int)$reservation['used_cent'], (int)$reservation['released_cent'],
                (int)$reservation['refunded_cent'], (int)$reservation['reversed_cent'],
            ]);
            $this->ledger($account, $action, $amount, $before, (int)$account['available_cent'], $orderId, 'purchase_quota:' . $orderId . ':' . $action . ':' . $cumulative, $reason);
            return $reservation;
        });
    }

    private function ledger(array $account, string $action, int $amount, int $before, int $after, int $orderId, string $key, string $reason = ''): void
    {
        Db::name('yfth_product_quota_ledger')->insert([
            'ledger_no' => $this->makeNo('PQL'), 'account_id' => (int)$account['id'], 'store_id' => (int)$account['store_id'],
            'quota_type' => (string)$account['quota_type'], 'direction' => in_array($action, ['release', 'refund'], true) ? 'in' : 'out',
            'action_type' => 'purchase_' . $action, 'amount_cent' => $amount,
            'balance_before_cent' => $before, 'balance_after_cent' => $after,
            'source_type' => 'purchase_order', 'source_id' => $orderId, 'idempotency_key' => substr($key, 0, 160),
            'status' => 'posted', 'operator_type' => 'system', 'operator_uid' => 0,
            'reason' => $reason ?: 'purchase_' . $action, 'create_time' => time(),
        ]);
    }

    private function makeNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
    }
}
