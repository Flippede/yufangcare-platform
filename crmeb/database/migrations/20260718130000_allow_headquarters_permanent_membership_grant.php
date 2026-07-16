<?php

use think\migration\Migrator;

class AllowHeadquartersPermanentMembershipGrant extends Migrator
{
    private const TABLE = 'yfth_permanent_membership';

    public function up()
    {
        if (!$this->tableExists(self::TABLE) || !$this->hasColumn(self::TABLE, 'source_package_instance_id')) {
            throw new RuntimeException('yfth_permanent_membership_schema_required');
        }
        $this->execute(
            'ALTER TABLE `' . $this->prefixed(self::TABLE) . '` '
            . 'MODIFY `source_package_instance_id` INT UNSIGNED NULL DEFAULT NULL '
            . "COMMENT 'source package instance; null for headquarters grant'"
        );
    }

    public function down()
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }
        $table = '`' . $this->prefixed(self::TABLE) . '`';
        $manual = $this->getAdapter()->fetchRow(
            'SELECT `id` FROM ' . $table . " WHERE `source_type`='headquarters_membership_grant' LIMIT 1"
        );
        if ($manual) {
            throw new RuntimeException('headquarters_membership_grant_exists_rollback_forbidden');
        }
        $this->execute('UPDATE ' . $table . ' SET `source_package_instance_id`=0 WHERE `source_package_instance_id` IS NULL');
        $this->execute(
            'ALTER TABLE ' . $table . ' MODIFY `source_package_instance_id` INT UNSIGNED NOT NULL DEFAULT 0 '
            . "COMMENT 'source package instance id'"
        );
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (bool)$this->getAdapter()->fetchRow(
            'SHOW COLUMNS FROM `' . $this->prefixed($table) . '` LIKE ' . $this->quote($column)
        );
    }

    private function tableExists(string $table): bool
    {
        return (bool)$this->getAdapter()->fetchRow(
            'SHOW TABLES LIKE ' . $this->quote($this->prefixed($table))
        );
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
