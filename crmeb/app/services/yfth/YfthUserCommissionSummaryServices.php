<?php

namespace app\services\yfth;

use think\facade\Db;

class YfthUserCommissionSummaryServices
{
    public function summaries(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids) {
            return [];
        }

        $result = [];
        foreach ($uids as $uid) {
            $result[$uid] = [
                'total_cent' => 0,
                'total' => '0.00',
                'breakdown' => [],
            ];
        }

        $this->appendUserCommission($result, $uids);
        $this->appendStoreCommission($result, $uids);
        $this->appendPartnerCommission($result, $uids);

        foreach ($result as &$summary) {
            $summary['total'] = $this->yuan((int)$summary['total_cent']);
        }
        return $result;
    }

    private function appendUserCommission(array &$result, array $uids): void
    {
        $rows = $this->safeRows(function () use ($uids) {
            return Db::name('yfth_user_commission_account')
                ->whereIn('uid', $uids)
                ->field('uid,available_cent,frozen_cent')
                ->select()->toArray();
        });
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $amount = (int)$row['available_cent'] + (int)$row['frozen_cent'];
            $this->append($result, $uid, 'C1佣金', $amount);
        }
    }

    private function appendStoreCommission(array &$result, array $uids): void
    {
        $roles = $this->safeRows(function () use ($uids) {
            return Db::name('yfth_user_store_role')
                ->whereIn('uid', $uids)
                ->where(['role_code' => 'store_manager', 'status' => 'active'])
                ->field('uid,store_id')->select()->toArray();
        });
        if (!$roles) {
            return;
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', array_column($roles, 'store_id')))));
        $accounts = $this->safeRows(function () use ($storeIds) {
            return Db::name('yfth_store_commission_account')
                ->whereIn('store_id', $storeIds)
                ->field('store_id,unsettled_cent,settled_cent')->select()->toArray();
        });
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int)$account['store_id']] = $account;
        }
        foreach ($roles as $role) {
            $account = $accountMap[(int)$role['store_id']] ?? [];
            $amount = (int)($account['unsettled_cent'] ?? 0) + (int)($account['settled_cent'] ?? 0);
            $this->append($result, (int)$role['uid'], 'B1门店佣金', $amount);
        }
    }

    private function appendPartnerCommission(array &$result, array $uids): void
    {
        $sources = [
            ['yfth_procurement_profit_ledger', 'beneficiary_uid', []],
            ['yfth_partner_opening_reward_ledger', 'partner_uid', [['status', 'not in', ['reversed', 'cancelled', 'invalid']]]],
            ['yfth_platform_dividend_item', 'beneficiary_uid', [['status', 'not in', ['reversed', 'cancelled', 'invalid']]]],
        ];
        $amounts = array_fill_keys($uids, 0);
        foreach ($sources as [$table, $uidField, $conditions]) {
            $rows = $this->safeRows(function () use ($table, $uidField, $uids, $conditions) {
                $query = Db::name($table)->whereIn($uidField, $uids);
                foreach ($conditions as $condition) {
                    $query->where($condition[0], $condition[1], $condition[2]);
                }
                return $query->fieldRaw($uidField . ' AS uid,COALESCE(SUM(amount_cent),0) AS amount_cent')
                    ->group($uidField)->select()->toArray();
            });
            foreach ($rows as $row) {
                $amounts[(int)$row['uid']] = (int)($amounts[(int)$row['uid']] ?? 0) + (int)$row['amount_cent'];
            }
        }
        foreach ($amounts as $uid => $amount) {
            if ($amount !== 0) {
                $this->append($result, (int)$uid, '合伙人收益', $amount);
            }
        }
    }

    private function append(array &$result, int $uid, string $label, int $amountCent): void
    {
        if (!isset($result[$uid]) || $amountCent === 0) {
            return;
        }
        $result[$uid]['total_cent'] += $amountCent;
        $result[$uid]['breakdown'][] = [
            'label' => $label,
            'amount_cent' => $amountCent,
            'amount' => $this->yuan($amountCent),
        ];
    }

    private function safeRows(callable $query): array
    {
        try {
            return (array)$query();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function yuan(int $cent): string
    {
        return number_format($cent / 100, 2, '.', '');
    }
}
