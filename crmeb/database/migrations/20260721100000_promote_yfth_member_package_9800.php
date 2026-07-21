<?php

use think\migration\Migrator;

class PromoteYfthMemberPackage9800 extends Migrator
{
    private const LEGACY_CODE = 'YFTH-TEST-PACKAGE-V1';
    private const PACKAGE_CODE = 'YFTH-MEMBER-PACKAGE-V1';
    private const RULE_CODE = 'YFTH-MEMBER-PACKAGE-RULE-9800-V1';
    private const PRODUCT_BARCODE = 'YFTHPKG9800';
    private const SKU_UNIQUE = 'yf9800v1';
    private const PRICE = '9800.00';

    public function up()
    {
        foreach (['yfth_package_template', 'yfth_package_rule_version', 'yfth_package_product_binding',
                  'store_product', 'store_product_attr', 'store_product_attr_value', 'store_product_description'] as $table) {
            if (!$this->hasTable($table)) {
                throw new RuntimeException('yfth_member_package_table_missing:' . $table);
            }
        }

        $template = $this->row(
            'SELECT * FROM ' . $this->tableName('yfth_package_template')
            . ' WHERE `package_code` IN (' . $this->quote(self::PACKAGE_CODE) . ',' . $this->quote(self::LEGACY_CODE) . ')'
            . ' ORDER BY (`package_code`=' . $this->quote(self::PACKAGE_CODE) . ') DESC,`id` ASC LIMIT 1'
        );
        if (!$template) {
            return;
        }

        $templateId = (int)$template['id'];
        $ruleId = $this->ensureRule($templateId, (int)($template['current_rule_version_id'] ?? 0));
        [$productId, $skuUnique] = $this->ensureProduct();
        $this->ensureBinding($templateId, $ruleId, $productId, $skuUnique);

        $this->execute(
            'UPDATE ' . $this->tableName('yfth_package_template')
            . ' SET `package_code`=' . $this->quote(self::PACKAGE_CODE)
            . ',`package_name`=' . $this->quote('御方通和9800元康养会员套餐')
            . ',`package_title`=' . $this->quote('御方通和9800元康养会员套餐')
            . ',`base_price`=' . $this->quote(self::PRICE)
            . ',`service_summary`=' . $this->quote('购买后获得套餐权益并激活永久会员资格；推荐奖励按15%/25%/60%规则快照计算。')
            . ',`agreement_title`=' . $this->quote('御方通和康养会员套餐购买协议')
            . ',`current_rule_version_id`=' . $ruleId . ',`status`=' . $this->quote('published')
            . ',`update_time`=' . time() . ' WHERE `id`=' . $templateId
        );
    }

    public function down()
    {
        $template = $this->row(
            'SELECT `id` FROM ' . $this->tableName('yfth_package_template')
            . ' WHERE `package_code`=' . $this->quote(self::PACKAGE_CODE) . ' LIMIT 1'
        );
        if (!$template) return;
        $rule = $this->row(
            'SELECT `id` FROM ' . $this->tableName('yfth_package_rule_version')
            . ' WHERE `template_id`=' . (int)$template['id'] . ' AND `rule_code`=' . $this->quote(self::RULE_CODE) . ' LIMIT 1'
        );
        if ($rule && $this->hasTable('yfth_package_purchase')) {
            $used = $this->row('SELECT `id` FROM ' . $this->tableName('yfth_package_purchase')
                . ' WHERE `rule_version_id`=' . (int)$rule['id'] . ' LIMIT 1');
            if ($used) throw new RuntimeException('yfth_member_package_9800_referenced_rollback_forbidden');
        }
        // The promotion changes an existing public product. Keep it in place on
        // rollback so a migration rollback cannot silently destroy catalog data.
    }

    private function ensureRule(int $templateId, int $oldRuleId): int
    {
        $table = $this->tableName('yfth_package_rule_version');
        $rule = $this->row('SELECT * FROM ' . $table . ' WHERE `template_id`=' . $templateId
            . ' AND `rule_code`=' . $this->quote(self::RULE_CODE) . ' LIMIT 1');
        if (!$rule) {
            $next = $this->row('SELECT COALESCE(MAX(`version_no`),0)+1 AS `value` FROM ' . $table
                . ' WHERE `template_id`=' . $templateId);
            $version = (int)($next['value'] ?? 1);
            $now = time();
            $agreement = '御方通和9800元康养会员套餐购买协议';
            $snapshot = json_encode([
                'formal_member_package' => true,
                'real_payment_required' => true,
                'package_reward_ratios_bps' => [1500, 2500, 6000],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->execute('INSERT INTO ' . $table
                . ' (`template_id`,`version_no`,`rule_code`,`status`,`package_price`,`month_count`,`grants_permanent_membership`,'
                . '`benefit_rule_snapshot`,`agreement_title`,`agreement_content_summary`,`agreement_content_hash`,'
                . '`created_uid`,`publish_uid`,`publish_time`,`effective_time`,`expire_time`,`active_key`,`add_time`,`update_time`) VALUES ('
                . $templateId . ',' . $version . ',' . $this->quote(self::RULE_CODE) . ',' . $this->quote('draft') . ','
                . $this->quote(self::PRICE) . ',10,1,' . $this->quote($snapshot) . ',' . $this->quote($agreement) . ','
                . $this->quote($agreement) . ',' . $this->quote(hash('sha256', $agreement))
                . ',0,0,0,0,0,NULL,' . $now . ',' . $now . ')');
            $rule = $this->row('SELECT * FROM ' . $table . ' WHERE `template_id`=' . $templateId
                . ' AND `rule_code`=' . $this->quote(self::RULE_CODE) . ' LIMIT 1');
        }
        if (!$rule) throw new RuntimeException('yfth_member_package_rule_create_failed');

        $ruleId = (int)$rule['id'];
        $now = time();
        $this->execute('UPDATE ' . $table . ' SET `status`=' . $this->quote('superseded')
            . ',`active_key`=NULL,`update_time`=' . $now . ' WHERE `template_id`=' . $templateId
            . ' AND `id`<>' . $ruleId . ' AND `status`=' . $this->quote('published'));
        $this->execute('UPDATE ' . $table . ' SET `status`=' . $this->quote('published')
            . ',`package_price`=' . $this->quote(self::PRICE) . ',`grants_permanent_membership`=1'
            . ',`active_key`=' . $this->quote($templateId . ':published')
            . ',`publish_time`=' . $now . ',`update_time`=' . $now . ' WHERE `id`=' . $ruleId);

        if ($oldRuleId > 0 && $oldRuleId !== $ruleId && $this->hasTable('yfth_monthly_benefit_rule')) {
            $count = $this->row('SELECT COUNT(*) AS `value` FROM ' . $this->tableName('yfth_monthly_benefit_rule')
                . ' WHERE `rule_version_id`=' . $ruleId);
            if ((int)($count['value'] ?? 0) === 0) {
                $this->execute('INSERT INTO ' . $this->tableName('yfth_monthly_benefit_rule')
                    . ' (`template_id`,`rule_version_id`,`month_no`,`benefit_template_id`,`benefit_code`,`benefit_name`,`benefit_type`,'
                    . '`quantity`,`per_limit`,`available_offset_days`,`expire_offset_days`,`service_capability`,`status`,`add_time`,`update_time`)'
                    . ' SELECT `template_id`,' . $ruleId . ',`month_no`,`benefit_template_id`,`benefit_code`,`benefit_name`,`benefit_type`,'
                    . '`quantity`,`per_limit`,`available_offset_days`,`expire_offset_days`,`service_capability`,`status`,' . $now . ',' . $now
                    . ' FROM ' . $this->tableName('yfth_monthly_benefit_rule') . ' WHERE `rule_version_id`=' . $oldRuleId);
            }
        }
        return $ruleId;
    }

    private function ensureProduct(): array
    {
        $productTable = $this->tableName('store_product');
        $product = $this->row('SELECT * FROM ' . $productTable . ' WHERE `bar_code`=' . $this->quote(self::PRODUCT_BARCODE) . ' LIMIT 1');
        $now = time();
        $image = '/statics/system_images/login_logo.jpeg';
        if (!$product) {
            $this->execute('INSERT INTO ' . $productTable
                . ' (`image`,`slider_image`,`store_name`,`store_info`,`bar_code`,`price`,`ot_price`,`unit_name`,`stock`,'
                . '`is_show`,`is_virtual`,`add_time`,`is_postage`,`cost`,`spu`,`logistics`,`freight`,`min_qty`) VALUES ('
                . $this->quote($image) . ',' . $this->quote(json_encode([$image])) . ','
                . $this->quote('御方通和9800元康养会员套餐') . ',' . $this->quote('购买后激活永久会员并生成套餐权益') . ','
                . $this->quote(self::PRODUCT_BARCODE) . ',' . $this->quote(self::PRICE) . ',' . $this->quote(self::PRICE)
                . ',' . $this->quote('套') . ',999999,1,1,' . $now . ',1,0,' . $this->quote('YFTH9800PKG01') . ','
                . $this->quote('2') . ',2,1)');
            $product = $this->row('SELECT * FROM ' . $productTable . ' WHERE `bar_code`=' . $this->quote(self::PRODUCT_BARCODE) . ' LIMIT 1');
        }
        if (!$product) throw new RuntimeException('yfth_member_package_product_create_failed');
        $productId = (int)$product['id'];
        $this->execute('UPDATE ' . $productTable . ' SET `store_name`=' . $this->quote('御方通和9800元康养会员套餐')
            . ',`store_info`=' . $this->quote('购买后激活永久会员并生成套餐权益')
            . ',`price`=' . $this->quote(self::PRICE) . ',`ot_price`=' . $this->quote(self::PRICE)
            . ',`stock`=GREATEST(`stock`,999999),`is_show`=1,`is_del`=0 WHERE `id`=' . $productId);

        $attrTable = $this->tableName('store_product_attr');
        if (!$this->row('SELECT `id` FROM ' . $attrTable . ' WHERE `product_id`=' . $productId . ' AND `type`=0 LIMIT 1')) {
            $this->execute('INSERT INTO ' . $attrTable . ' (`product_id`,`attr_name`,`attr_values`,`type`) VALUES ('
                . $productId . ',' . $this->quote('规格') . ',' . $this->quote('标准套餐') . ',0)');
        }

        $skuTable = $this->tableName('store_product_attr_value');
        $sku = $this->row('SELECT * FROM ' . $skuTable . ' WHERE `product_id`=' . $productId
            . ' AND `unique`=' . $this->quote(self::SKU_UNIQUE) . ' LIMIT 1');
        if (!$sku) {
            $this->execute('INSERT INTO ' . $skuTable
                . ' (`product_id`,`suk`,`stock`,`price`,`image`,`unique`,`ot_price`,`type`,`is_virtual`,`is_show`,`is_default_select`) VALUES ('
                . $productId . ',' . $this->quote('标准套餐') . ',999999,' . $this->quote(self::PRICE) . ','
                . $this->quote($image) . ',' . $this->quote(self::SKU_UNIQUE) . ',' . $this->quote(self::PRICE) . ',0,1,1,1)');
        } else {
            $this->execute('UPDATE ' . $skuTable . ' SET `price`=' . $this->quote(self::PRICE)
                . ',`ot_price`=' . $this->quote(self::PRICE) . ',`stock`=GREATEST(`stock`,999999),`is_show`=1'
                . ' WHERE `id`=' . (int)$sku['id']);
        }

        $descriptionTable = $this->tableName('store_product_description');
        if (!$this->row('SELECT `product_id` FROM ' . $descriptionTable . ' WHERE `product_id`=' . $productId . ' AND `type`=0 LIMIT 1')) {
            $this->execute('INSERT INTO ' . $descriptionTable . ' (`product_id`,`description`,`type`) VALUES ('
                . $productId . ',' . $this->quote('<p>御方通和9800元康养会员套餐</p>') . ',0)');
        }
        return [$productId, self::SKU_UNIQUE];
    }

    private function ensureBinding(int $templateId, int $ruleId, int $productId, string $skuUnique): void
    {
        $table = $this->tableName('yfth_package_product_binding');
        $now = time();
        $this->execute('UPDATE ' . $table . ' SET `binding_status`=' . $this->quote('disabled')
            . ',`active_key`=NULL,`update_time`=' . $now . ' WHERE `template_id`=' . $templateId
            . ' AND `binding_status`=' . $this->quote('active') . ' AND `rule_version_id`<>' . $ruleId);
        $binding = $this->row('SELECT * FROM ' . $table . ' WHERE `template_id`=' . $templateId
            . ' AND `rule_version_id`=' . $ruleId . ' AND `product_id`=' . $productId
            . ' AND `product_attr_unique`=' . $this->quote($skuUnique) . ' LIMIT 1');
        $snapshot = json_encode([
            'product_id' => $productId,
            'product_name' => '御方通和9800元康养会员套餐',
            'sku_unique' => $skuUnique,
            'sku_name' => '标准套餐',
            'sku_price' => self::PRICE,
            'rule_version_id' => $ruleId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!$binding) {
            $this->execute('INSERT INTO ' . $table
                . ' (`template_id`,`rule_version_id`,`product_id`,`product_attr_unique`,`sku_price_snapshot`,`product_snapshot`,'
                . '`binding_status`,`active_key`,`add_time`,`update_time`) VALUES ('
                . $templateId . ',' . $ruleId . ',' . $productId . ',' . $this->quote($skuUnique) . ',' . $this->quote(self::PRICE)
                . ',' . $this->quote($snapshot) . ',' . $this->quote('active') . ','
                . $this->quote($productId . ':' . $skuUnique) . ',' . $now . ',' . $now . ')');
        } else {
            $this->execute('UPDATE ' . $table . ' SET `sku_price_snapshot`=' . $this->quote(self::PRICE)
                . ',`product_snapshot`=' . $this->quote($snapshot) . ',`binding_status`=' . $this->quote('active')
                . ',`active_key`=' . $this->quote($productId . ':' . $skuUnique) . ',`update_time`=' . $now
                . ' WHERE `id`=' . (int)$binding['id']);
        }
    }

    private function tableName(string $table): string
    {
        return '`' . (string)$this->getAdapter()->getOption('table_prefix') . $table . '`';
    }

    private function row(string $sql): array
    {
        $row = $this->getAdapter()->fetchRow($sql);
        return $row ?: [];
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
