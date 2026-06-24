<?php

use think\migration\Migrator;

class CreateYfthPackageBenefitTables extends Migrator
{
    public function change()
    {
        $this->createPackageTemplateTable();
        $this->createPackageRuleVersionTable();
        $this->createPackageProductBindingTable();
        $this->createAgreementSnapshotTable();
        $this->createPackagePurchaseTable();
        $this->createPackageInstanceTable();
        $this->createBenefitTemplateTable();
        $this->createMonthlyBenefitRuleTable();
        $this->createBenefitPlanTable();
        $this->createBenefitPeriodTable();
        $this->createBenefitItemTable();
    }

    private function baseTable(string $name, string $comment)
    {
        return $this->table($name)
            ->setEngine('InnoDB')
            ->setComment($comment)
            ->addColumn('add_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created_at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated_at']);
    }

    private function createPackageTemplateTable(): void
    {
        $this->baseTable('yfth_package_template', 'YFTH package templates')
            ->addColumn('package_code', 'string', ['limit' => 48, 'default' => '', 'comment' => 'package code'])
            ->addColumn('package_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'package name'])
            ->addColumn('package_title', 'string', ['limit' => 255, 'default' => '', 'comment' => 'display title'])
            ->addColumn('package_type', 'string', ['limit' => 32, 'default' => 'health_package', 'comment' => 'package type'])
            ->addColumn('base_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'base price'])
            ->addColumn('currency', 'string', ['limit' => 8, 'default' => 'CNY', 'comment' => 'currency'])
            ->addColumn('benefit_months', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'default benefit months'])
            ->addColumn('service_summary', 'text', ['null' => true, 'comment' => 'service summary'])
            ->addColumn('agreement_title', 'string', ['limit' => 128, 'default' => '', 'comment' => 'agreement title'])
            ->addColumn('agreement_content', 'text', ['null' => true, 'comment' => 'agreement content'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'draft', 'comment' => 'draft/published/disabled'])
            ->addColumn('current_rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'current published rule id'])
            ->addColumn('publish_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'publish time'])
            ->addColumn('sort', 'integer', ['signed' => true, 'default' => 0, 'comment' => 'sort'])
            ->addIndex(['package_code'], ['unique' => true, 'name' => 'uniq_yfth_pkg_tpl_code'])
            ->addIndex(['status', 'sort'], ['name' => 'idx_yfth_pkg_tpl_status_sort'])
            ->create();
    }

    private function createPackageRuleVersionTable(): void
    {
        $this->baseTable('yfth_package_rule_version', 'YFTH package rule versions')
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('version_no', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'version number'])
            ->addColumn('rule_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'rule code'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'draft', 'comment' => 'draft/published/disabled'])
            ->addColumn('package_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'package price'])
            ->addColumn('month_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit month count'])
            ->addColumn('benefit_rule_snapshot', 'text', ['null' => true, 'comment' => 'rule snapshot json'])
            ->addColumn('agreement_title', 'string', ['limit' => 128, 'default' => '', 'comment' => 'agreement title'])
            ->addColumn('agreement_content_summary', 'string', ['limit' => 512, 'default' => '', 'comment' => 'agreement summary'])
            ->addColumn('agreement_content_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'agreement hash'])
            ->addColumn('created_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'creator uid'])
            ->addColumn('publish_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'publisher uid'])
            ->addColumn('publish_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'publish time'])
            ->addColumn('effective_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'effective time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'one published rule per template'])
            ->addIndex(['template_id', 'version_no'], ['unique' => true, 'name' => 'uniq_yfth_pkg_rule_tpl_ver'])
            ->addIndex(['template_id', 'status'], ['name' => 'idx_yfth_pkg_rule_tpl_status'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_pkg_rule_active'])
            ->create();
    }

    private function createPackageProductBindingTable(): void
    {
        $this->baseTable('yfth_package_product_binding', 'YFTH package product SKU bindings')
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB product id'])
            ->addColumn('product_attr_unique', 'string', ['limit' => 32, 'default' => '', 'comment' => 'CRMEB SKU unique'])
            ->addColumn('sku_price_snapshot', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'SKU price snapshot'])
            ->addColumn('product_snapshot', 'text', ['null' => true, 'comment' => 'product/SKU snapshot'])
            ->addColumn('binding_status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'active/disabled'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'active product SKU binding'])
            ->addIndex(['template_id', 'rule_version_id'], ['name' => 'idx_yfth_pkg_bind_tpl_rule'])
            ->addIndex(['product_id', 'product_attr_unique'], ['name' => 'idx_yfth_pkg_bind_product_sku'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_pkg_bind_active'])
            ->create();
    }

    private function createAgreementSnapshotTable(): void
    {
        $this->baseTable('yfth_package_agreement_snapshot', 'YFTH package agreement snapshots')
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service store id'])
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('template_version', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version number'])
            ->addColumn('agreement_title', 'string', ['limit' => 128, 'default' => '', 'comment' => 'title'])
            ->addColumn('content_summary', 'string', ['limit' => 512, 'default' => '', 'comment' => 'content summary'])
            ->addColumn('content_hash', 'string', ['limit' => 64, 'default' => '', 'comment' => 'content sha256'])
            ->addColumn('source', 'string', ['limit' => 32, 'default' => 'mobile', 'comment' => 'source'])
            ->addColumn('ip', 'string', ['limit' => 64, 'default' => '', 'comment' => 'client ip'])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'default' => '', 'comment' => 'user agent'])
            ->addColumn('accepted_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'accepted time'])
            ->addIndex(['uid', 'template_id', 'rule_version_id'], ['name' => 'idx_yfth_pkg_agree_uid_rule'])
            ->addIndex(['content_hash'], ['name' => 'idx_yfth_pkg_agree_hash'])
            ->create();
    }

    private function createPackagePurchaseTable(): void
    {
        $this->baseTable('yfth_package_purchase', 'YFTH package purchase bindings')
            ->addColumn('purchase_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'purchase number'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service store id'])
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('product_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'product id'])
            ->addColumn('product_attr_unique', 'string', ['limit' => 32, 'default' => '', 'comment' => 'SKU unique'])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB store_order.id'])
            ->addColumn('order_sn', 'string', ['limit' => 32, 'default' => '', 'comment' => 'CRMEB store_order.order_id'])
            ->addColumn('expected_pay_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'server expected price'])
            ->addColumn('order_pay_price', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'order pay price snapshot'])
            ->addColumn('payment_scene', 'string', ['limit' => 48, 'default' => 'package_5980', 'comment' => 'payment scene'])
            ->addColumn('route_snapshot', 'text', ['null' => true, 'comment' => 'payment route snapshot'])
            ->addColumn('agreement_snapshot_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'agreement snapshot id'])
            ->addColumn('validation_snapshot', 'text', ['null' => true, 'comment' => 'pre-purchase validation snapshot'])
            ->addColumn('purchase_status', 'string', ['limit' => 32, 'default' => 'created', 'comment' => 'purchase status'])
            ->addColumn('activation_status', 'string', ['limit' => 32, 'default' => 'pending', 'comment' => 'activation status'])
            ->addColumn('instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('idempotency_key', 'string', ['limit' => 128, 'default' => '', 'comment' => 'activation idempotency key'])
            ->addColumn('source', 'string', ['limit' => 32, 'default' => 'mobile', 'comment' => 'source'])
            ->addIndex(['purchase_no'], ['unique' => true, 'name' => 'uniq_yfth_pkg_purchase_no'])
            ->addIndex(['order_id'], ['name' => 'idx_yfth_pkg_purchase_order_id'])
            ->addIndex(['order_sn'], ['name' => 'idx_yfth_pkg_purchase_order_sn'])
            ->addIndex(['uid', 'purchase_status'], ['name' => 'idx_yfth_pkg_purchase_uid_status'])
            ->create();
    }

    private function createPackageInstanceTable(): void
    {
        $this->baseTable('yfth_package_instance', 'YFTH package instances')
            ->addColumn('instance_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'instance number'])
            ->addColumn('purchase_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'purchase id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service store id'])
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB order id'])
            ->addColumn('order_sn', 'string', ['limit' => 32, 'default' => '', 'comment' => 'CRMEB order no'])
            ->addColumn('plan_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit plan id'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'active', 'comment' => 'active/refunding/refunded/closed/expired'])
            ->addColumn('refund_status', 'string', ['limit' => 32, 'default' => 'none', 'comment' => 'refund state'])
            ->addColumn('fulfilled_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'fulfilled benefit count'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'start time'])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'end time'])
            ->addColumn('activated_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'activated time'])
            ->addColumn('close_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'close reason'])
            ->addColumn('rule_snapshot', 'text', ['null' => true, 'comment' => 'rule snapshot'])
            ->addColumn('store_snapshot', 'text', ['null' => true, 'comment' => 'store snapshot'])
            ->addIndex(['instance_no'], ['unique' => true, 'name' => 'uniq_yfth_pkg_instance_no'])
            ->addIndex(['purchase_id'], ['unique' => true, 'name' => 'uniq_yfth_pkg_instance_purchase'])
            ->addIndex(['order_id'], ['unique' => true, 'name' => 'uniq_yfth_pkg_instance_order'])
            ->addIndex(['uid', 'status'], ['name' => 'idx_yfth_pkg_instance_uid_status'])
            ->create();
    }

    private function createBenefitTemplateTable(): void
    {
        $this->baseTable('yfth_benefit_template', 'YFTH benefit templates')
            ->addColumn('benefit_code', 'string', ['limit' => 48, 'default' => '', 'comment' => 'benefit code'])
            ->addColumn('benefit_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'benefit name'])
            ->addColumn('benefit_type', 'string', ['limit' => 32, 'default' => 'service', 'comment' => 'product/service/coupon'])
            ->addColumn('fulfillment_type', 'string', ['limit' => 32, 'default' => 'manual', 'comment' => 'fulfillment type'])
            ->addColumn('unit', 'string', ['limit' => 24, 'default' => 'item', 'comment' => 'unit'])
            ->addColumn('description', 'text', ['null' => true, 'comment' => 'description'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addColumn('sort', 'integer', ['signed' => true, 'default' => 0, 'comment' => 'sort'])
            ->addIndex(['benefit_code'], ['unique' => true, 'name' => 'uniq_yfth_benefit_tpl_code'])
            ->addIndex(['status', 'sort'], ['name' => 'idx_yfth_benefit_tpl_status_sort'])
            ->create();
    }

    private function createMonthlyBenefitRuleTable(): void
    {
        $this->baseTable('yfth_monthly_benefit_rule', 'YFTH monthly benefit rules')
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('month_no', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'month number'])
            ->addColumn('benefit_template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit template id'])
            ->addColumn('benefit_code', 'string', ['limit' => 48, 'default' => '', 'comment' => 'benefit code snapshot'])
            ->addColumn('benefit_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'benefit name snapshot'])
            ->addColumn('benefit_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'benefit type snapshot'])
            ->addColumn('quantity', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '1.00', 'comment' => 'quantity'])
            ->addColumn('per_limit', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'per fulfillment limit'])
            ->addColumn('available_offset_days', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'offset days from period start'])
            ->addColumn('expire_offset_days', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire offset days'])
            ->addColumn('service_capability', 'string', ['limit' => 48, 'default' => '', 'comment' => 'required store capability'])
            ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active', 'comment' => 'status'])
            ->addIndex(['rule_version_id', 'month_no'], ['name' => 'idx_yfth_month_rule_ver_month'])
            ->addIndex(['benefit_template_id'], ['name' => 'idx_yfth_month_rule_benefit'])
            ->create();
    }

    private function createBenefitPlanTable(): void
    {
        $this->baseTable('yfth_benefit_plan', 'YFTH package benefit plans')
            ->addColumn('plan_no', 'string', ['limit' => 48, 'default' => '', 'comment' => 'plan number'])
            ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'service store id'])
            ->addColumn('template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'template id'])
            ->addColumn('rule_version_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'rule version id'])
            ->addColumn('month_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'month count'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'active', 'comment' => 'status'])
            ->addColumn('start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'start time'])
            ->addColumn('end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'end time'])
            ->addColumn('opened_month_no', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'opened month number'])
            ->addIndex(['plan_no'], ['unique' => true, 'name' => 'uniq_yfth_benefit_plan_no'])
            ->addIndex(['package_instance_id'], ['unique' => true, 'name' => 'uniq_yfth_benefit_plan_instance'])
            ->addIndex(['uid', 'status'], ['name' => 'idx_yfth_benefit_plan_uid_status'])
            ->create();
    }

    private function createBenefitPeriodTable(): void
    {
        $this->baseTable('yfth_benefit_period', 'YFTH monthly benefit periods')
            ->addColumn('plan_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'plan id'])
            ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('month_no', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'month number'])
            ->addColumn('period_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'period code'])
            ->addColumn('period_start_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'period start'])
            ->addColumn('period_end_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'period end'])
            ->addColumn('open_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'open at'])
            ->addColumn('expire_at', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire at'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'unopened', 'comment' => 'unopened/available/expired/closed/refunded'])
            ->addColumn('total_item_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'total item count'])
            ->addColumn('fulfilled_item_count', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'fulfilled item count'])
            ->addIndex(['plan_id', 'month_no'], ['unique' => true, 'name' => 'uniq_yfth_benefit_period_month'])
            ->addIndex(['uid', 'status'], ['name' => 'idx_yfth_benefit_period_uid_status'])
            ->addIndex(['open_at', 'status'], ['name' => 'idx_yfth_benefit_period_open'])
            ->addIndex(['expire_at', 'status'], ['name' => 'idx_yfth_benefit_period_expire'])
            ->create();
    }

    private function createBenefitItemTable(): void
    {
        $this->baseTable('yfth_benefit_item', 'YFTH benefit item details')
            ->addColumn('plan_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'plan id'])
            ->addColumn('period_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'period id'])
            ->addColumn('package_instance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'package instance id'])
            ->addColumn('uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'user id'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store id'])
            ->addColumn('month_no', 'integer', ['signed' => false, 'default' => 1, 'comment' => 'month number'])
            ->addColumn('benefit_template_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'benefit template id'])
            ->addColumn('benefit_code', 'string', ['limit' => 48, 'default' => '', 'comment' => 'benefit code'])
            ->addColumn('benefit_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'benefit name'])
            ->addColumn('benefit_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'benefit type'])
            ->addColumn('quantity_total', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'total quantity'])
            ->addColumn('quantity_available', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'available quantity'])
            ->addColumn('quantity_used', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'used quantity'])
            ->addColumn('available_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'available time'])
            ->addColumn('expire_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'expire time'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'unopened', 'comment' => 'unopened/available/used/expired/refunded/closed'])
            ->addColumn('fulfillment_status', 'string', ['limit' => 32, 'default' => 'none', 'comment' => 'fulfillment status'])
            ->addColumn('source_rule_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'monthly rule id'])
            ->addIndex(['period_id', 'source_rule_id'], ['unique' => true, 'name' => 'uniq_yfth_benefit_item_rule'])
            ->addIndex(['uid', 'status'], ['name' => 'idx_yfth_benefit_item_uid_status'])
            ->addIndex(['package_instance_id'], ['name' => 'idx_yfth_benefit_item_instance'])
            ->create();
    }
}
