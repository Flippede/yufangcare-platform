<?php

use think\migration\Migrator;

class EnableYfthSimulatedPackagePurchase extends Migrator
{
    private const PACKAGE_CODE = 'YFTH-TEST-PACKAGE-V1';
    private const RULE_CODE = 'YFTH-TEST-PACKAGE-RULE-SIM-010-V1';
    private const TEST_MARKER = '[YFTH-ACCEPTANCE-TEST-V1]';

    public function up()
    {
        $this->hardenPackageInstanceOrderKey();
        $this->upgradeAcceptancePackageRule();
    }

    public function down()
    {
        $this->restoreAcceptancePackageRule();
        $this->restorePackageInstanceOrderIndex();
    }

    private function hardenPackageInstanceOrderKey(): void
    {
        if (!$this->hasTable('yfth_package_instance')) {
            throw new RuntimeException('yfth_package_instance_missing');
        }
        $table = $this->table('yfth_package_instance');
        if (!$table->hasColumn('order_unique_key')) {
            $table->addColumn('order_unique_key', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'after' => 'order_sn',
                'comment' => 'nullable unique real CRMEB order id',
            ])->update();
        }
        $physical = '`' . $this->prefixed('yfth_package_instance') . '`';
        $duplicates = $this->getAdapter()->fetchRow(
            'SELECT `order_id` FROM ' . $physical . ' WHERE `order_id` > 0 GROUP BY `order_id` HAVING COUNT(*) > 1 LIMIT 1'
        );
        if ($duplicates) {
            throw new RuntimeException('yfth_package_instance_duplicate_real_order');
        }
        $this->execute(
            'UPDATE ' . $physical . ' SET `order_unique_key`=CAST(`order_id` AS CHAR)'
            . " WHERE `order_id` > 0 AND (`order_unique_key` IS NULL OR `order_unique_key`='')"
        );
        if ($this->indexExists('yfth_package_instance', 'uniq_yfth_pkg_instance_order')) {
            $this->table('yfth_package_instance')->removeIndexByName('uniq_yfth_pkg_instance_order')->update();
        }
        if (!$this->indexExists('yfth_package_instance', 'idx_yfth_pkg_instance_order')) {
            $this->table('yfth_package_instance')
                ->addIndex(['order_id'], ['name' => 'idx_yfth_pkg_instance_order'])
                ->update();
        }
        if (!$this->indexExists('yfth_package_instance', 'uniq_yfth_pkg_instance_order_key')) {
            $this->table('yfth_package_instance')
                ->addIndex(['order_unique_key'], ['unique' => true, 'name' => 'uniq_yfth_pkg_instance_order_key'])
                ->update();
        }
    }

    private function upgradeAcceptancePackageRule(): void
    {
        foreach (['yfth_package_template', 'yfth_package_rule_version'] as $table) {
            if (!$this->hasTable($table)) {
                throw new RuntimeException('yfth_simulated_package_table_missing:' . $table);
            }
        }
        $templateTable = '`' . $this->prefixed('yfth_package_template') . '`';
        $ruleTable = '`' . $this->prefixed('yfth_package_rule_version') . '`';
        $template = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $templateTable . ' WHERE `package_code`=' . $this->quote(self::PACKAGE_CODE) . ' LIMIT 1'
        );
        if (!$template) {
            return;
        }
        $templateId = (int)$template['id'];
        $current = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $ruleTable . ' WHERE `template_id`=' . $templateId
            . ' AND `status`=' . $this->quote('published') . ' ORDER BY `version_no` DESC,`id` DESC LIMIT 1'
        );
        $existing = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $ruleTable . ' WHERE `template_id`=' . $templateId
            . ' AND `rule_code`=' . $this->quote(self::RULE_CODE) . ' LIMIT 1'
        );
        if (!$existing) {
            $version = (int)$this->getAdapter()->fetchRow(
                'SELECT COALESCE(MAX(`version_no`),0)+1 AS `next_version` FROM ' . $ruleTable . ' WHERE `template_id`=' . $templateId
            )['next_version'];
            $now = time();
            $agreement = self::TEST_MARKER . ' 仅用于0.1元模拟购买验收，不发起真实支付。';
            $snapshot = json_encode([
                'test_marker' => self::TEST_MARKER,
                'simulation_only' => true,
                'real_payment_created' => false,
            ], JSON_UNESCAPED_UNICODE);
            $this->execute(
                'INSERT INTO ' . $ruleTable
                . ' (`template_id`,`version_no`,`rule_code`,`status`,`package_price`,`month_count`,`grants_permanent_membership`,'
                . '`benefit_rule_snapshot`,`agreement_title`,`agreement_content_summary`,`agreement_content_hash`,'
                . '`created_uid`,`publish_uid`,`publish_time`,`effective_time`,`expire_time`,`active_key`,`add_time`,`update_time`) VALUES ('
                . $templateId . ',' . $version . ',' . $this->quote(self::RULE_CODE) . ',' . $this->quote('draft') . ','
                . $this->quote('0.10') . ',10,1,' . $this->quote($snapshot) . ',' . $this->quote('0.1元模拟套餐购买协议') . ','
                . $this->quote($agreement) . ',' . $this->quote(hash('sha256', $agreement)) . ',0,0,0,0,0,NULL,' . $now . ',' . $now . ')'
            );
            $existing = $this->getAdapter()->fetchRow(
                'SELECT * FROM ' . $ruleTable . ' WHERE `template_id`=' . $templateId
                . ' AND `rule_code`=' . $this->quote(self::RULE_CODE) . ' LIMIT 1'
            );
        }
        if (!$existing) {
            throw new RuntimeException('yfth_simulated_package_rule_create_failed');
        }
        $now = time();
        $this->execute(
            'UPDATE ' . $ruleTable . ' SET `status`=' . $this->quote('superseded') . ',`active_key`=NULL,`update_time`=' . $now
            . ' WHERE `template_id`=' . $templateId . ' AND `status`=' . $this->quote('published') . ' AND `id`<>' . (int)$existing['id']
        );
        $this->execute(
            'UPDATE ' . $ruleTable . ' SET `status`=' . $this->quote('published') . ',`package_price`=' . $this->quote('0.10')
            . ',`grants_permanent_membership`=1,`active_key`=' . $this->quote($templateId . ':published')
            . ',`publish_time`=' . $now . ',`update_time`=' . $now . ' WHERE `id`=' . (int)$existing['id']
        );
        $this->execute(
            'UPDATE ' . $templateTable . ' SET `package_name`=' . $this->quote('0.1元模拟购买套餐')
            . ',`package_title`=' . $this->quote('TEST 0.1元模拟购买套餐（不发起真实支付）')
            . ',`base_price`=' . $this->quote('0.10') . ',`benefit_months`=10,`current_rule_version_id`=' . (int)$existing['id']
            . ',`service_summary`=' . $this->quote(self::TEST_MARKER . ' 仅用于受控模拟购买和会员流程验收。')
            . ',`agreement_title`=' . $this->quote('0.1元模拟套餐购买协议')
            . ',`agreement_content`=' . $this->quote(self::TEST_MARKER . ' 本套餐不发起真实支付，不产生真实扣款。')
            . ',`update_time`=' . $now . ' WHERE `id`=' . $templateId
        );
    }

    private function restoreAcceptancePackageRule(): void
    {
        if (!$this->hasTable('yfth_package_template') || !$this->hasTable('yfth_package_rule_version')) {
            return;
        }
        $templateTable = '`' . $this->prefixed('yfth_package_template') . '`';
        $ruleTable = '`' . $this->prefixed('yfth_package_rule_version') . '`';
        $purchaseTable = '`' . $this->prefixed('yfth_package_purchase') . '`';
        $rule = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $ruleTable . ' WHERE `rule_code`=' . $this->quote(self::RULE_CODE) . ' LIMIT 1'
        );
        if (!$rule) {
            return;
        }
        if ($this->hasTable('yfth_package_purchase')) {
            $referenced = $this->getAdapter()->fetchRow(
                'SELECT `id` FROM ' . $purchaseTable . ' WHERE `rule_version_id`=' . (int)$rule['id'] . ' LIMIT 1'
            );
            if ($referenced) {
                throw new RuntimeException('yfth_simulated_package_rule_referenced_rollback_forbidden');
            }
        }
        $templateId = (int)$rule['template_id'];
        $previous = $this->getAdapter()->fetchRow(
            'SELECT * FROM ' . $ruleTable . ' WHERE `template_id`=' . $templateId . ' AND `id`<>' . (int)$rule['id']
            . ' ORDER BY `version_no` DESC,`id` DESC LIMIT 1'
        );
        $this->execute('DELETE FROM ' . $ruleTable . ' WHERE `id`=' . (int)$rule['id']);
        if ($previous) {
            $now = time();
            $this->execute(
                'UPDATE ' . $ruleTable . ' SET `status`=' . $this->quote('published') . ',`active_key`=' . $this->quote($templateId . ':published')
                . ',`update_time`=' . $now . ' WHERE `id`=' . (int)$previous['id']
            );
            $this->execute(
                'UPDATE ' . $templateTable . ' SET `package_name`=' . $this->quote('验收专用套餐')
                . ',`package_title`=' . $this->quote('TEST 验收专用套餐（禁止真实支付）')
                . ',`base_price`=' . $this->quote((string)$previous['package_price'])
                . ',`current_rule_version_id`=' . (int)$previous['id'] . ',`update_time`=' . $now . ' WHERE `id`=' . $templateId
            );
        }
    }

    private function restorePackageInstanceOrderIndex(): void
    {
        if (!$this->hasTable('yfth_package_instance')) {
            return;
        }
        $physical = '`' . $this->prefixed('yfth_package_instance') . '`';
        $duplicate = $this->getAdapter()->fetchRow(
            'SELECT `order_id` FROM ' . $physical . ' GROUP BY `order_id` HAVING COUNT(*) > 1 LIMIT 1'
        );
        if ($duplicate) {
            throw new RuntimeException('yfth_package_instance_order_index_rollback_forbidden');
        }
        foreach (['uniq_yfth_pkg_instance_order_key', 'idx_yfth_pkg_instance_order'] as $index) {
            if ($this->indexExists('yfth_package_instance', $index)) {
                $this->table('yfth_package_instance')->removeIndexByName($index)->update();
            }
        }
        $table = $this->table('yfth_package_instance');
        if ($table->hasColumn('order_unique_key')) {
            $table->removeColumn('order_unique_key')->update();
        }
        if (!$this->indexExists('yfth_package_instance', 'uniq_yfth_pkg_instance_order')) {
            $this->table('yfth_package_instance')
                ->addIndex(['order_id'], ['unique' => true, 'name' => 'uniq_yfth_pkg_instance_order'])
                ->update();
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool)$this->getAdapter()->fetchRow(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()'
            . ' AND TABLE_NAME=' . $this->quote($this->prefixed($table))
            . ' AND INDEX_NAME=' . $this->quote($index) . ' LIMIT 1'
        );
    }

    private function prefixed(string $table): string
    {
        return (string)$this->getAdapter()->getOption('table_prefix') . $table;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
