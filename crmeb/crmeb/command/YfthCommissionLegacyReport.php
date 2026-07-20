<?php

namespace crmeb\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class YfthCommissionLegacyReport extends Command
{
    protected function configure()
    {
        $this->setName('yfth:commission-legacy-report')
            ->setDescription('Read-only pre-migration summary for legacy YFTH reward candidates and settlements');
    }

    protected function execute(Input $input, Output $output)
    {
        $report = [
            'read_only' => true,
            'generated_at' => date('c'),
            'direct_referral_candidates' => $this->groupedSummary(
                'yfth_direct_referral_reward_candidate',
                ['candidate_type', 'status'],
                'reward_amount_cent'
            ),
            'direct_referral_settlements' => $this->groupedSummary(
                'yfth_direct_referral_reward_settlement_ledger',
                ['candidate_type'],
                'reward_amount_cent'
            ),
            'legacy_settlement_records' => $this->groupedSummary(
                'yfth_reward_settlement_record',
                ['status'],
                'amount_cent'
            ),
        ];
        $output->writeln(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function groupedSummary(string $table, array $groups, string $amountField): array
    {
        try {
            $select = array_merge($groups, [
                'COUNT(*) AS row_count',
                'COALESCE(SUM(`' . $amountField . '`), 0) AS amount_cent',
            ]);
            $rows = Db::name($table)
                ->fieldRaw(implode(',', $select))
                ->group(implode(',', $groups))
                ->order(implode(',', $groups))
                ->select()
                ->toArray();
            foreach ($rows as &$row) {
                $row['row_count'] = (int)$row['row_count'];
                $row['amount_cent'] = (int)$row['amount_cent'];
            }
            unset($row);
            return ['available' => true, 'groups' => $rows];
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'table_unavailable'];
        }
    }
}
