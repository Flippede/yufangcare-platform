<?php

use think\migration\Migrator;

class ProductizeYfthHqAdminMenus extends Migrator
{
    private $upNames = [
        'admin-home' => ['menu_name' => '首页工作台', 'sort' => 127],
        'admin-agent' => ['menu_name' => '加盟与门店', 'sort' => 126],
        'admin-user' => ['menu_name' => '客户与会员', 'sort' => 125],
        'admin-product' => ['menu_name' => '商品与供货', 'sort' => 124],
        'yfth-foundation' => ['menu_name' => '御方通和康养服务', 'sort' => 123],
        'admin-order' => ['menu_name' => '订单与售后', 'sort' => 122],
        'admin-marketing' => ['menu_name' => '推荐与营销', 'sort' => 121],
        'admin-setting-pages' => ['menu_name' => '内容与装修', 'sort' => 120],
        'admin-cms' => ['menu_name' => '内容管理', 'sort' => 119],
        'admin-setting' => ['menu_name' => '系统管理', 'sort' => 10],
        'admin-system' => ['menu_name' => '系统维护', 'sort' => 9],
        'yfth-foundation-index' => ['menu_name' => '业务基础域', 'sort' => 30],
        'yfth-foundation-identity-list' => ['menu_name' => '身份列表'],
        'yfth-foundation-store-role-list' => ['menu_name' => '门店角色列表'],
        'yfth-foundation-subject-list' => ['menu_name' => '经营主体列表'],
        'yfth-foundation-subject-save' => ['menu_name' => '经营主体保存'],
        'yfth-foundation-store-subject-list' => ['menu_name' => '门店主体列表'],
        'yfth-foundation-store-subject-save' => ['menu_name' => '门店主体保存'],
        'yfth-foundation-store-subject-disable' => ['menu_name' => '门店主体停用'],
        'yfth-foundation-qualification-list' => ['menu_name' => '资质列表'],
        'yfth-foundation-qualification-save' => ['menu_name' => '资质保存'],
        'yfth-foundation-qualification-audit' => ['menu_name' => '资质审核'],
        'yfth-foundation-capability-list' => ['menu_name' => '能力列表'],
        'yfth-foundation-payment-route-list' => ['menu_name' => '支付路由列表'],
        'yfth-foundation-payment-route-save' => ['menu_name' => '支付路由保存'],
        'yfth-foundation-payment-route-disable' => ['menu_name' => '支付路由停用'],
        'yfth-foundation-payment-route-resolve' => ['menu_name' => '支付路由解析'],
        'yfth-foundation-audit-event-list' => ['menu_name' => '审计事件列表'],
        'yfth-package-benefit-index' => ['menu_name' => '套餐与权益', 'sort' => 20],
        'yfth-package-template-list' => ['menu_name' => '套餐模板列表'],
        'yfth-package-template-save' => ['menu_name' => '套餐模板保存'],
        'yfth-package-rule-save' => ['menu_name' => '套餐规则保存'],
        'yfth-package-binding-save' => ['menu_name' => '套餐商品绑定保存'],
        'yfth-benefit-template-list' => ['menu_name' => '权益模板列表'],
        'yfth-benefit-template-save' => ['menu_name' => '权益模板保存'],
        'yfth-monthly-rule-list' => ['menu_name' => '月度权益规则列表'],
        'yfth-monthly-rule-save' => ['menu_name' => '月度权益规则保存'],
        'yfth-package-purchase-list' => ['menu_name' => '套餐购买记录'],
        'yfth-package-instance-list' => ['menu_name' => '套餐实例列表'],
        'yfth-package-instance-detail' => ['menu_name' => '套餐实例详情'],
        'yfth-package-instance-state' => ['menu_name' => '套餐实例状态变更'],
        'yfth-benefit-plan-list' => ['menu_name' => '权益计划列表'],
        'yfth-benefit-period-open' => ['menu_name' => '打开到期权益周期'],
        'yfth-package-rule-copy' => ['menu_name' => '套餐规则复制'],
        'yfth-package-activation-recover' => ['menu_name' => '付费套餐激活恢复'],
        'yfth-package-activation-retry' => ['menu_name' => '套餐激活重试'],
        'yfth-package-lifecycle-state' => ['menu_name' => '套餐生命周期变更'],
        'yfth-package-orphan-scan' => ['menu_name' => '孤儿订单扫描恢复'],
        'yfth-service-appointment-index' => ['menu_name' => '服务预约与核销', 'sort' => 10],
        'yfth-service-project-list' => ['menu_name' => '服务项目列表'],
        'yfth-service-project-save' => ['menu_name' => '服务项目保存'],
        'yfth-service-project-disable' => ['menu_name' => '服务项目停用'],
        'yfth-store-service-list' => ['menu_name' => '门店服务列表'],
        'yfth-store-service-save' => ['menu_name' => '门店服务保存'],
        'yfth-store-service-disable' => ['menu_name' => '门店服务停用'],
        'yfth-service-schedule-list' => ['menu_name' => '排班规则列表'],
        'yfth-service-schedule-save' => ['menu_name' => '排班规则保存'],
        'yfth-service-schedule-disable' => ['menu_name' => '排班规则停用'],
        'yfth-service-special-day-list' => ['menu_name' => '特殊日期列表'],
        'yfth-service-special-day-save' => ['menu_name' => '特殊日期保存'],
        'yfth-service-special-day-disable' => ['menu_name' => '特殊日期停用'],
        'yfth-service-slot-preview' => ['menu_name' => '可预约时段预览'],
        'yfth-service-appointment-booking-list' => ['menu_name' => '预约列表'],
        'yfth-service-appointment-booking-detail' => ['menu_name' => '预约详情'],
        'yfth-service-appointment-booking-confirm' => ['menu_name' => '预约确认'],
        'yfth-service-appointment-booking-reject' => ['menu_name' => '预约拒绝'],
        'yfth-service-appointment-booking-cancel' => ['menu_name' => '后台取消预约'],
        'yfth-service-writeoff-list' => ['menu_name' => '核销记录列表'],
        'yfth-service-writeoff-detail' => ['menu_name' => '核销记录详情'],
        'yfth-service-writeoff-precheck' => ['menu_name' => '核销预检'],
        'yfth-service-writeoff-token' => ['menu_name' => '动态码核销'],
        'yfth-service-writeoff-digital' => ['menu_name' => '数字码核销'],
        'yfth-service-writeoff-result' => ['menu_name' => '核销结果'],
        'yfth-service-writeoff-exception' => ['menu_name' => '总部例外核销'],
    ];

    private $downNames = [
        'admin-home' => ['menu_name' => '主页', 'sort' => 127],
        'admin-agent' => ['menu_name' => '分销', 'sort' => 104],
        'admin-user' => ['menu_name' => '用户', 'sort' => 125],
        'admin-product' => ['menu_name' => '商品', 'sort' => 115],
        'admin-order' => ['menu_name' => '订单', 'sort' => 120],
        'admin-marketing' => ['menu_name' => '营销', 'sort' => 110],
        'admin-setting-pages' => ['menu_name' => '装修', 'sort' => 80],
        'admin-cms' => ['menu_name' => '内容', 'sort' => 85],
        'admin-setting' => ['menu_name' => '设置', 'sort' => 1],
        'admin-system' => ['menu_name' => '维护', 'sort' => 0],
        'yfth-foundation' => ['menu_name' => 'YFTH', 'sort' => 32],
        'yfth-foundation-index' => ['menu_name' => 'Foundation', 'sort' => 10],
        'yfth-foundation-identity-list' => ['menu_name' => 'Identity list'],
        'yfth-foundation-store-role-list' => ['menu_name' => 'Store role list'],
        'yfth-foundation-subject-list' => ['menu_name' => 'Subject list'],
        'yfth-foundation-subject-save' => ['menu_name' => 'Subject save'],
        'yfth-foundation-store-subject-list' => ['menu_name' => 'Store subject list'],
        'yfth-foundation-store-subject-save' => ['menu_name' => 'Store subject save'],
        'yfth-foundation-store-subject-disable' => ['menu_name' => 'Store subject disable'],
        'yfth-foundation-qualification-list' => ['menu_name' => 'Qualification list'],
        'yfth-foundation-qualification-save' => ['menu_name' => 'Qualification save'],
        'yfth-foundation-qualification-audit' => ['menu_name' => 'Qualification audit'],
        'yfth-foundation-capability-list' => ['menu_name' => 'Capability list'],
        'yfth-foundation-payment-route-list' => ['menu_name' => 'Payment route list'],
        'yfth-foundation-payment-route-save' => ['menu_name' => 'Payment route save'],
        'yfth-foundation-payment-route-disable' => ['menu_name' => 'Payment route disable'],
        'yfth-foundation-payment-route-resolve' => ['menu_name' => 'Payment route resolve'],
        'yfth-foundation-audit-event-list' => ['menu_name' => 'Audit event list'],
        'yfth-package-benefit-index' => ['menu_name' => 'Package Benefits', 'sort' => 20],
        'yfth-package-template-list' => ['menu_name' => 'Template list'],
        'yfth-package-template-save' => ['menu_name' => 'Template save'],
        'yfth-package-rule-save' => ['menu_name' => 'Rule save'],
        'yfth-package-binding-save' => ['menu_name' => 'Binding save'],
        'yfth-benefit-template-list' => ['menu_name' => 'Benefit template list'],
        'yfth-benefit-template-save' => ['menu_name' => 'Benefit template save'],
        'yfth-monthly-rule-list' => ['menu_name' => 'Monthly rule list'],
        'yfth-monthly-rule-save' => ['menu_name' => 'Monthly rule save'],
        'yfth-package-purchase-list' => ['menu_name' => 'Purchase list'],
        'yfth-package-instance-list' => ['menu_name' => 'Instance list'],
        'yfth-package-instance-detail' => ['menu_name' => 'Instance detail'],
        'yfth-package-instance-state' => ['menu_name' => 'Instance state'],
        'yfth-benefit-plan-list' => ['menu_name' => 'Plan list'],
        'yfth-benefit-period-open' => ['menu_name' => 'Open periods'],
        'yfth-package-rule-copy' => ['menu_name' => 'Copy rule version'],
        'yfth-package-activation-recover' => ['menu_name' => 'Recover paid packages'],
        'yfth-package-activation-retry' => ['menu_name' => 'Retry package activation'],
        'yfth-package-lifecycle-state' => ['menu_name' => 'Lifecycle state change'],
        'yfth-package-orphan-scan' => ['menu_name' => 'Package orphan scan'],
        'yfth-service-appointment-index' => ['menu_name' => 'Service Appointment', 'sort' => 30],
        'yfth-service-project-list' => ['menu_name' => 'Project list'],
        'yfth-service-project-save' => ['menu_name' => 'Project save'],
        'yfth-service-project-disable' => ['menu_name' => 'Project disable'],
        'yfth-store-service-list' => ['menu_name' => 'Store service list'],
        'yfth-store-service-save' => ['menu_name' => 'Store service save'],
        'yfth-store-service-disable' => ['menu_name' => 'Store service disable'],
        'yfth-service-schedule-list' => ['menu_name' => 'Schedule list'],
        'yfth-service-schedule-save' => ['menu_name' => 'Schedule save'],
        'yfth-service-schedule-disable' => ['menu_name' => 'Schedule disable'],
        'yfth-service-special-day-list' => ['menu_name' => 'Special day list'],
        'yfth-service-special-day-save' => ['menu_name' => 'Special day save'],
        'yfth-service-special-day-disable' => ['menu_name' => 'Special day disable'],
        'yfth-service-slot-preview' => ['menu_name' => 'Slot preview'],
        'yfth-service-appointment-booking-list' => ['menu_name' => 'Appointment list'],
        'yfth-service-appointment-booking-detail' => ['menu_name' => 'Appointment detail'],
        'yfth-service-appointment-booking-confirm' => ['menu_name' => 'Appointment confirm'],
        'yfth-service-appointment-booking-reject' => ['menu_name' => 'Appointment reject'],
        'yfth-service-appointment-booking-cancel' => ['menu_name' => 'Appointment cancel'],
        'yfth-service-writeoff-list' => ['menu_name' => 'Service writeoff list'],
        'yfth-service-writeoff-detail' => ['menu_name' => 'Service writeoff detail'],
        'yfth-service-writeoff-precheck' => ['menu_name' => 'Service writeoff precheck'],
        'yfth-service-writeoff-token' => ['menu_name' => 'Service QR writeoff'],
        'yfth-service-writeoff-digital' => ['menu_name' => 'Service digital writeoff'],
        'yfth-service-writeoff-result' => ['menu_name' => 'Service writeoff result'],
        'yfth-service-writeoff-exception' => ['menu_name' => 'Service exception writeoff'],
    ];

    public function up()
    {
        $this->renameMenus($this->upNames);
    }

    public function down()
    {
        $this->renameMenus($this->downNames);
    }

    private function renameMenus(array $names): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        foreach ($names as $auth => $data) {
            $sets = [
                '`menu_name` = ' . $this->quote($data['menu_name']),
            ];
            if (array_key_exists('sort', $data)) {
                $sets[] = '`sort` = ' . (int)$data['sort'];
            }
            $this->execute(
                'UPDATE ' . $table .
                ' SET ' . implode(', ', $sets) .
                ' WHERE `unique_auth` = ' . $this->quote($auth)
            );
        }
    }

    private function quote($value): string
    {
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
