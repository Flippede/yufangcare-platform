<?php

namespace app\services\yfth;

use think\facade\Config;
use think\facade\Db;

class PackageMembershipReferralMigrationHealthServices
{
    private const VERSION = '20260716100000';

    private const INDEXES = [
        'yfth_permanent_membership' => [
            'uniq_yfth_pm_uid' => [['uid'], true],
            'uniq_yfth_pm_source_instance' => [['source_package_instance_id'], true],
        ],
        'yfth_permanent_membership_event' => [
            'uniq_yfth_pm_event_source' => [['source_unique_key'], true],
        ],
        'yfth_direct_referral_invite' => [
            'uniq_yfth_direct_invite_hash' => [['token_hash'], true],
            'uniq_yfth_direct_invite_active' => [['active_key'], true],
        ],
        'yfth_direct_referral_rule_version' => [
            'uniq_yfth_direct_rule_active' => [['active_key'], true],
        ],
        'yfth_direct_referral_reward_candidate' => [
            'uniq_yfth_direct_candidate_source' => [['source_unique_key'], true],
            'uniq_yfth_direct_candidate_sequence' => [['referrer_uid', 'reward_sequence_no'], true],
        ],
    ];

    private const PERMISSIONS = [
        'yfth-package-membership-referral-index' => ['yfth/package_membership/member', 'GET', 1],
        'yfth-package-membership-referral-member-read' => ['yfth/package_membership/member', 'GET', 2],
        'yfth-package-membership-referral-candidate-read' => ['yfth/package_membership/candidate', 'GET', 2],
        'yfth-package-membership-referral-rule-read' => ['yfth/package_membership/rule', 'GET', 2],
        'yfth-package-membership-referral-rule-save' => ['yfth/package_membership/rule', 'POST', 2],
        'yfth-package-membership-referral-rule-publish' => ['yfth/package_membership/rule/<id>/publish', 'POST', 2],
        'yfth-package-membership-referral-legacy-backfill' => ['yfth/package_membership/legacy_backfill', 'POST', 2],
    ];

    public function inspect(): array
    {
        $issues = [];
        if (!$this->migrationRecorded()) {
            $issues[] = 'migration_record_missing';
        }
        foreach (['yfth_package_rule_version', 'yfth_package_purchase_snapshot'] as $table) {
            $column = $this->column($table, 'grants_permanent_membership');
            if (!$column
                || (string)$column['DATA_TYPE'] !== 'tinyint'
                || stripos((string)$column['COLUMN_TYPE'], 'unsigned') === false
                || (string)$column['IS_NULLABLE'] !== 'YES'
                || $column['COLUMN_DEFAULT'] !== null) {
                $issues[] = 'column_signature:' . $table . ':grants_permanent_membership';
            }
        }
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $definition) {
                if (!$this->indexMatches($table, $name, $definition[0], $definition[1])) {
                    $issues[] = 'index_signature:' . $table . ':' . $name;
                }
            }
        }
        foreach (self::PERMISSIONS as $auth => $signature) {
            $rows = Db::name('system_menus')->where('unique_auth', $auth)->select()->toArray();
            if (count($rows) !== 1
                || (string)$rows[0]['api_url'] !== $signature[0]
                || strtoupper((string)$rows[0]['methods']) !== $signature[1]
                || (int)$rows[0]['auth_type'] !== $signature[2]
                || (int)$rows[0]['is_del'] !== 0) {
                $issues[] = 'permission_signature:' . $auth;
            }
        }
        return [
            'healthy' => !$issues,
            'forward_repair_required' => (bool)$issues,
            'issues' => $issues,
        ];
    }

    private function migrationRecorded(): bool
    {
        if (!$this->tableExists('migrations')) {
            return false;
        }
        return (int)Db::name('migrations')->where('version', self::VERSION)->count() === 1;
    }

    private function column(string $table, string $column): array
    {
        $rows = Db::query(
            'SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1',
            [$this->prefixed($table), $column]
        );
        return $rows[0] ?? [];
    }

    private function tableExists(string $table): bool
    {
        $rows = Db::query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1',
            [$this->prefixed($table)]
        );
        return !empty($rows);
    }

    private function indexMatches(string $table, string $name, array $columns, bool $unique): bool
    {
        $rows = Db::query(
            'SELECT NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX ASC',
            [$this->prefixed($table), $name]
        );
        if (count($rows) !== count($columns)) {
            return false;
        }
        foreach ($rows as $position => $row) {
            if ((int)$row['NON_UNIQUE'] !== ($unique ? 0 : 1)
                || (int)$row['SEQ_IN_INDEX'] !== $position + 1
                || (string)$row['COLUMN_NAME'] !== $columns[$position]
                || strtoupper((string)$row['INDEX_TYPE']) !== 'BTREE') {
                return false;
            }
        }
        return true;
    }

    private function prefixed(string $table): string
    {
        $default = (string)Config::get('database.default');
        return (string)Config::get('database.connections.' . $default . '.prefix') . $table;
    }
}
