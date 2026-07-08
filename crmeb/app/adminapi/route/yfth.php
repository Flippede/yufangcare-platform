<?php

use think\facade\Route;

Route::group('yfth', function () {
    Route::group('foundation', function () {
        Route::get('identity', 'v1.yfth.Foundation/identity')->option(['real_name' => '身份列表']);
        Route::get('store_role', 'v1.yfth.Foundation/storeRole')->option(['real_name' => '门店角色列表']);
        Route::get('subject', 'v1.yfth.Foundation/subject')->option(['real_name' => '经营主体列表']);
        Route::post('subject/save', 'v1.yfth.Foundation/subjectSave')->option(['real_name' => '经营主体保存']);
        Route::get('store_subject', 'v1.yfth.Foundation/storeSubject')->option(['real_name' => '门店主体列表']);
        Route::post('store_subject/save', 'v1.yfth.Foundation/storeSubjectSave')->option(['real_name' => '门店主体保存']);
        Route::post('store_subject/disable', 'v1.yfth.Foundation/storeSubjectDisable')->option(['real_name' => '门店主体停用']);
        Route::get('qualification', 'v1.yfth.Foundation/qualification')->option(['real_name' => '资质列表']);
        Route::post('qualification/save', 'v1.yfth.Foundation/qualificationSave')->option(['real_name' => '资质保存']);
        Route::post('qualification/audit', 'v1.yfth.Foundation/qualificationAudit')->option(['real_name' => '资质审核']);
        Route::get('capability', 'v1.yfth.Foundation/capability')->option(['real_name' => '能力列表']);
        Route::get('payment_route', 'v1.yfth.Foundation/paymentRoute')->option(['real_name' => '支付路由列表']);
        Route::post('payment_route/save', 'v1.yfth.Foundation/paymentRouteSave')->option(['real_name' => '支付路由保存']);
        Route::post('payment_route/disable', 'v1.yfth.Foundation/paymentRouteDisable')->option(['real_name' => '支付路由停用']);
        Route::get('payment_route/resolve', 'v1.yfth.Foundation/paymentRouteResolve')->option(['real_name' => '支付路由解析']);
        Route::get('audit_event', 'v1.yfth.Foundation/auditEvent')->option(['real_name' => '审计事件列表']);
    })->option(['parent' => 'yfth', 'cate_name' => '业务基础域']);

    Route::group('package_benefit', function () {
        Route::get('template', 'v1.yfth.PackageBenefit/templateList')->option(['real_name' => '套餐模板列表']);
        Route::post('template/save', 'v1.yfth.PackageBenefit/templateSave')->option(['real_name' => '套餐模板保存']);
        Route::post('rule/save', 'v1.yfth.PackageBenefit/ruleSave')->option(['real_name' => '套餐规则保存']);
        Route::post('rule/:id/copy', 'v1.yfth.PackageBenefit/ruleCopy')->option(['real_name' => '套餐规则复制']);
        Route::post('binding/save', 'v1.yfth.PackageBenefit/bindingSave')->option(['real_name' => '套餐商品绑定保存']);
        Route::get('benefit_template', 'v1.yfth.PackageBenefit/benefitTemplateList')->option(['real_name' => '权益模板列表']);
        Route::post('benefit_template/save', 'v1.yfth.PackageBenefit/benefitTemplateSave')->option(['real_name' => '权益模板保存']);
        Route::get('monthly_rule', 'v1.yfth.PackageBenefit/monthlyRuleList')->option(['real_name' => '月度权益规则列表']);
        Route::post('monthly_rule/save', 'v1.yfth.PackageBenefit/monthlyRuleSave')->option(['real_name' => '月度权益规则保存']);
        Route::get('purchase', 'v1.yfth.PackageBenefit/purchaseList')->option(['real_name' => '套餐购买记录']);
        Route::get('instance', 'v1.yfth.PackageBenefit/instanceList')->option(['real_name' => '套餐实例列表']);
        Route::get('instance/:id', 'v1.yfth.PackageBenefit/instanceDetail')->option(['real_name' => '套餐实例详情']);
        Route::post('instance/:id/state', 'v1.yfth.PackageBenefit/instanceState')->option(['real_name' => '套餐实例状态变更']);
        Route::post('instance/:id/lifecycle', 'v1.yfth.PackageBenefit/instanceLifecycle')->option(['real_name' => '套餐生命周期变更']);
        Route::get('plan', 'v1.yfth.PackageBenefit/planList')->option(['real_name' => '权益计划列表']);
        Route::post('period/open_due', 'v1.yfth.PackageBenefit/openPeriods')->option(['real_name' => '打开到期权益周期']);
        Route::post('activation/recover', 'v1.yfth.PackageBenefit/recoverActivation')->option(['real_name' => '付费套餐激活恢复']);
        Route::post('purchase/:id/activation_retry', 'v1.yfth.PackageBenefit/retryActivation')->option(['real_name' => '套餐激活重试']);
        Route::post('orphan/scan', 'v1.yfth.PackageBenefit/scanOrphanOrders')->option(['real_name' => '孤儿订单扫描恢复']);
    })->option(['parent' => 'yfth', 'cate_name' => '套餐与权益']);

    Route::group('service_appointment', function () {
        Route::get('project', 'v1.yfth.ServiceAppointment/projectList')->option(['real_name' => '服务项目列表']);
        Route::post('project/save', 'v1.yfth.ServiceAppointment/projectSave')->option(['real_name' => '服务项目保存']);
        Route::post('project/disable', 'v1.yfth.ServiceAppointment/projectDisable')->option(['real_name' => '服务项目停用']);
        Route::get('store_service', 'v1.yfth.ServiceAppointment/storeServiceList')->option(['real_name' => '门店服务列表']);
        Route::post('store_service/save', 'v1.yfth.ServiceAppointment/storeServiceSave')->option(['real_name' => '门店服务保存']);
        Route::post('store_service/disable', 'v1.yfth.ServiceAppointment/storeServiceDisable')->option(['real_name' => '门店服务停用']);
        Route::get('schedule_rule', 'v1.yfth.ServiceAppointment/scheduleRuleList')->option(['real_name' => '排班规则列表']);
        Route::post('schedule_rule/save', 'v1.yfth.ServiceAppointment/scheduleRuleSave')->option(['real_name' => '排班规则保存']);
        Route::post('schedule_rule/disable', 'v1.yfth.ServiceAppointment/scheduleRuleDisable')->option(['real_name' => '排班规则停用']);
        Route::get('special_day', 'v1.yfth.ServiceAppointment/specialDayList')->option(['real_name' => '特殊日期列表']);
        Route::post('special_day/save', 'v1.yfth.ServiceAppointment/specialDaySave')->option(['real_name' => '特殊日期保存']);
        Route::post('special_day/disable', 'v1.yfth.ServiceAppointment/specialDayDisable')->option(['real_name' => '特殊日期停用']);
        Route::get('slot_preview', 'v1.yfth.ServiceAppointment/slotPreview')->option(['real_name' => '可预约时段预览']);
        Route::get('appointment', 'v1.yfth.ServiceAppointment/appointmentList')->option(['real_name' => '预约列表']);
        Route::get('appointment/:id', 'v1.yfth.ServiceAppointment/appointmentDetail')->option(['real_name' => '预约详情']);
        Route::post('appointment/:id/confirm', 'v1.yfth.ServiceAppointment/appointmentConfirm')->option(['real_name' => '预约确认']);
        Route::post('appointment/:id/reject', 'v1.yfth.ServiceAppointment/appointmentReject')->option(['real_name' => '预约拒绝']);
        Route::post('appointment/:id/cancel', 'v1.yfth.ServiceAppointment/appointmentCancel')->option(['real_name' => '后台取消预约']);
        Route::get('writeoff', 'v1.yfth.ServiceAppointment/writeoffList')->option(['real_name' => '核销记录列表']);
        Route::get('writeoff/record/:id', 'v1.yfth.ServiceAppointment/writeoffDetail')->option(['real_name' => '核销记录详情']);
        Route::post('writeoff/precheck', 'v1.yfth.ServiceAppointment/writeoffPrecheck')->option(['real_name' => '核销预检']);
        Route::post('writeoff/token', 'v1.yfth.ServiceAppointment/writeoffToken')->option(['real_name' => '动态码核销']);
        Route::post('writeoff/digital', 'v1.yfth.ServiceAppointment/writeoffDigital')->option(['real_name' => '数字码核销']);
        Route::get('writeoff/:id', 'v1.yfth.ServiceAppointment/writeoffResult')->option(['real_name' => '核销结果']);
        Route::post('appointment/:id/exception_writeoff', 'v1.yfth.ServiceAppointment/appointmentExceptionWriteoff')->option(['real_name' => '总部例外核销']);
    })->option(['parent' => 'yfth', 'cate_name' => '服务预约与核销']);

    Route::group('franchise_application', function () {
        Route::get('application', 'v1.yfth.FranchiseApplication/applicationList')->option(['real_name' => '加盟申请列表']);
        Route::get('application/status_options', 'v1.yfth.FranchiseApplication/statusOptions')->option(['real_name' => '加盟申请状态选项']);
        Route::get('application/:id', 'v1.yfth.FranchiseApplication/applicationDetail')->option(['real_name' => '加盟申请详情']);
        Route::post('application/:id/assign', 'v1.yfth.FranchiseApplication/assign')->option(['real_name' => '加盟申请分配负责人']);
        Route::post('application/:id/status', 'v1.yfth.FranchiseApplication/status')->option(['real_name' => '加盟申请状态推进']);
        Route::post('application/:id/follow', 'v1.yfth.FranchiseApplication/follow')->option(['real_name' => '加盟申请沟通记录']);
    })->option(['parent' => 'yfth', 'cate_name' => '加盟管理']);
    Route::group('supply_chain', function () {
        Route::get('catalog', 'v1.yfth.SupplyChain/catalogList')->option(['real_name' => 'Supply catalog list']);
        Route::post('catalog/save', 'v1.yfth.SupplyChain/catalogSave')->option(['real_name' => 'Supply catalog save']);
        Route::post('catalog/disable', 'v1.yfth.SupplyChain/catalogDisable')->option(['real_name' => 'Supply catalog disable']);
        Route::get('product/search', 'v1.yfth.SupplyChain/productSearch')->option(['real_name' => 'Supply product search']);
        Route::get('purchase_order', 'v1.yfth.SupplyChain/purchaseOrderList')->option(['real_name' => 'Purchase order list']);
        Route::get('purchase_order/:id', 'v1.yfth.SupplyChain/purchaseOrderDetail')->option(['real_name' => 'Purchase order detail']);
        Route::post('purchase_order/:id/audit', 'v1.yfth.SupplyChain/purchaseOrderAudit')->option(['real_name' => 'Purchase order audit']);
        Route::post('purchase_order/:id/ship', 'v1.yfth.SupplyChain/purchaseOrderShip')->option(['real_name' => 'Purchase order ship']);
        Route::get('shipment', 'v1.yfth.SupplyChain/shipmentList')->option(['real_name' => 'Shipment list']);
        Route::get('inventory', 'v1.yfth.SupplyChain/inventoryList')->option(['real_name' => 'Inventory balance list']);
        Route::get('ledger', 'v1.yfth.SupplyChain/ledgerList')->option(['real_name' => 'Inventory ledger list']);
        Route::get('alert_rule', 'v1.yfth.SupplyChain/alertRuleList')->option(['real_name' => 'Inventory alert rule list']);
        Route::post('alert_rule/save', 'v1.yfth.SupplyChain/alertRuleSave')->option(['real_name' => 'Inventory alert rule save']);
    })->option(['parent' => 'yfth', 'cate_name' => 'Supply Chain']);
})->middleware([
    \app\http\middleware\AllowOriginMiddleware::class,
    \app\adminapi\middleware\AdminAuthTokenMiddleware::class,
    \app\adminapi\middleware\AdminCheckRoleMiddleware::class,
    \app\adminapi\middleware\AdminLogMiddleware::class
])->option(['mark' => 'yfth', 'mark_name' => '御方通和']);
