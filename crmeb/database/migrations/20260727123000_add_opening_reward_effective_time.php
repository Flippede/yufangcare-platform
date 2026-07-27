<?php

use think\migration\Migrator;

class AddOpeningRewardEffectiveTime extends Migrator
{
    private const TABLE = 'yfth_partner_opening_reward_ledger';
    private const INDEX = 'idx_yfth_opening_reward_partner_effective';

    public function up()
    {
        if (!$this->hasTable(self::TABLE)) {
            throw new RuntimeException('yfth_opening_reward_table_required');
        }
        $table = $this->table(self::TABLE);
        if (!$table->hasColumn('effective_time')) {
            $table->addColumn('effective_time', 'integer', [
                'signed' => false,
                'default' => 0,
                'after' => 'source_unique_key',
                'comment' => 'business opening confirmation time used for observation period',
            ])->update();
        }
        $this->backfillEffectiveTime();
        if (!$this->indexExists(self::TABLE, self::INDEX)) {
            $this->table(self::TABLE)
                ->addIndex(['partner_uid', 'status', 'effective_time'], ['name' => self::INDEX])
                ->update();
        }
        $this->assertHealthy();
    }

    public function down()
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }
        if ($this->indexExists(self::TABLE, self::INDEX)) {
            $this->table(self::TABLE)->removeIndexByName(self::INDEX)->update();
        }
        $table = $this->table(self::TABLE);
        if ($table->hasColumn('effective_time')) {
            $table->removeColumn('effective_time')->update();
        }
    }

    private function backfillEffectiveTime(): void
    {
        $reward = $this->prefixed(self::TABLE);
        $audit = $this->prefixed('yfth_audit_event');
        $application = $this->prefixed('yfth_franchise_application');
        $performance = $this->prefixed('yfth_partner_opening_performance');
        $this->execute(
            'UPDATE `' . $reward . '` r ' .
            'LEFT JOIN (' .
                'SELECT CAST(`object_id` AS UNSIGNED) AS `application_id`,' .
                    'MAX(CASE WHEN `action`=\'offline_review_approved\' THEN `add_time` ELSE 0 END) AS `approved_time`,' .
                    'MIN(CASE WHEN `action`=\'offline_review_approved_and_opened\' THEN `add_time` ELSE NULL END) AS `opened_time` ' .
                'FROM `' . $audit . '` ' .
                "WHERE `business_domain`='yfth_franchise_application' " .
                "AND `object_type`='franchise_application' " .
                "AND `action` IN ('offline_review_approved','offline_review_approved_and_opened') AND `add_time`>0 " .
                'GROUP BY CAST(`object_id` AS UNSIGNED)' .
            ') a ON a.`application_id`=r.`application_id` ' .
            'LEFT JOIN `' . $application . '` f ON f.`id`=r.`application_id` ' .
            'LEFT JOIN `' . $performance . '` p ON p.`application_id`=r.`application_id` ' .
            'SET r.`effective_time`=COALESCE(NULLIF(a.`approved_time`,0),NULLIF(a.`opened_time`,0),NULLIF(p.`opened_time`,0),' .
                'NULLIF(f.`update_time`,0),r.`create_time`) ' .
            'WHERE r.`effective_time`=0'
        );
    }

    private function assertHealthy(): void
    {
        if (!$this->columnExists(self::TABLE, 'effective_time')) {
            throw new RuntimeException('yfth_opening_reward_effective_time_missing');
        }
        if (!$this->indexExists(self::TABLE, self::INDEX)) {
            throw new RuntimeException('yfth_opening_reward_effective_index_missing');
        }
        $table = $this->prefixed(self::TABLE);
        $invalid = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM `' . $table . '` WHERE `effective_time`<=0 LIMIT 1'
        );
        if ($invalid) {
            throw new RuntimeException('yfth_opening_reward_effective_time_backfill_incomplete');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool)$this->getAdapter()->fetchRow(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ' .
            'AND TABLE_NAME=' . $this->quote($this->prefixed($table)) .
            ' AND COLUMN_NAME=' . $this->quote($column) . ' LIMIT 1'
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool)$this->getAdapter()->fetchRow(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ' .
            'AND TABLE_NAME=' . $this->quote($this->prefixed($table)) .
            ' AND INDEX_NAME=' . $this->quote($index) . ' LIMIT 1'
        );
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        return (method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '') . $table;
    }

    private function quote($value): string
    {
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }
}
