<?php

use think\facade\Route;

Route::group('yfth', function () {
    Route::group('homepage', function () {
        Route::get('config', 'v1.yfth.Homepage/config')->option(['real_name' => 'YFTH homepage configuration']);
        Route::post('config', 'v1.yfth.Homepage/save')->option(['real_name' => 'YFTH homepage configuration save']);
    })->option(['parent' => 'yfth', 'cate_name' => 'YFTH Homepage']);

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
    Route::group('franchise_opening', function () {
        Route::get('contract', 'v1.yfth.FranchiseOpening/contractList')->option(['real_name' => 'Franchise opening contract list']);
        Route::get('contract/:id', 'v1.yfth.FranchiseOpening/contractDetail')->option(['real_name' => 'Franchise opening contract detail']);
        Route::post('contract/create', 'v1.yfth.FranchiseOpening/contractCreate')->option(['real_name' => 'Franchise opening contract create']);
        Route::post('contract/:id/confirm', 'v1.yfth.FranchiseOpening/contractConfirm')->option(['real_name' => 'Franchise opening contract confirm']);
        Route::get('payment', 'v1.yfth.FranchiseOpening/paymentList')->option(['real_name' => 'Franchise opening payment list']);
        Route::post('payment/:id/confirm', 'v1.yfth.FranchiseOpening/paymentConfirm')->option(['real_name' => 'Franchise opening payment confirm']);
        Route::post('payment/:id/reject', 'v1.yfth.FranchiseOpening/paymentReject')->option(['real_name' => 'Franchise opening payment reject']);
        Route::get('profile/:application_id', 'v1.yfth.FranchiseOpening/profileDetail')->option(['real_name' => 'Franchise opening store profile']);
        Route::post('profile/save', 'v1.yfth.FranchiseOpening/profileSave')->option(['real_name' => 'Franchise opening store profile save']);
        Route::post('profile/:id/bind_store', 'v1.yfth.FranchiseOpening/profileBindStore')->option(['real_name' => 'Franchise opening bind store']);
        Route::post('profile/:id/create_store', 'v1.yfth.FranchiseOpening/profileCreateStore')->option(['real_name' => '总部正式创建加盟门店']);
        Route::get('task', 'v1.yfth.FranchiseOpening/taskList')->option(['real_name' => 'Franchise opening task list']);
        Route::post('task/:id/review', 'v1.yfth.FranchiseOpening/taskReview')->option(['real_name' => 'Franchise opening task review']);
        Route::get('acceptance', 'v1.yfth.FranchiseOpening/acceptanceList')->option(['real_name' => 'Franchise opening acceptance list']);
        Route::get('acceptance/:id', 'v1.yfth.FranchiseOpening/acceptanceDetail')->option(['real_name' => 'Franchise opening acceptance detail']);
        Route::post('acceptance/:id/review', 'v1.yfth.FranchiseOpening/acceptanceReview')->option(['real_name' => 'Franchise opening acceptance review']);
        Route::post('identity_grant', 'v1.yfth.FranchiseOpening/identityGrant')->option(['real_name' => 'Franchise opening identity grant']);
    })->option(['parent' => 'yfth', 'cate_name' => 'Franchise Opening']);
    Route::group('franchise_partner', function () {
        Route::get('dashboard', 'v1.yfth.FranchisePartner/dashboard')->option(['real_name' => '招商合伙人工作台']);
        Route::get('rule', 'v1.yfth.FranchisePartner/ruleList')->option(['real_name' => '招商职级规则列表']);
        Route::post('rule', 'v1.yfth.FranchisePartner/ruleSave')->option(['real_name' => '招商职级规则保存']);
        Route::post('rule/:id/publish', 'v1.yfth.FranchisePartner/rulePublish')->option(['real_name' => '招商职级规则发布']);
        Route::get('partner', 'v1.yfth.FranchisePartner/partnerList')->option(['real_name' => '招商合伙人列表']);
        Route::get('partner/:uid', 'v1.yfth.FranchisePartner/partnerDetail')->option(['real_name' => '招商合伙人详情']);
        Route::post('partner/:uid/rank', 'v1.yfth.FranchisePartner/rankChange')->option(['real_name' => '招商合伙人职级调整']);
        Route::post('partner/:uid/parent', 'v1.yfth.FranchisePartner/parentChange')->option(['real_name' => '招商上下级调整']);
        Route::post('source/:application_id/correct', 'v1.yfth.FranchisePartner/sourceCorrect')->option(['real_name' => '加盟申请招商来源纠错']);
        Route::get('performance', 'v1.yfth.FranchisePartner/performanceList')->option(['real_name' => '招商开店业绩']);
        Route::get('reward', 'v1.yfth.FranchisePartner/rewardList')->option(['real_name' => '招商收益候选']);
        Route::post('reward/:id/confirm', 'v1.yfth.FranchisePartner/rewardConfirm')->option(['real_name' => '招商收益确认']);
        Route::post('reward/:id/cancel', 'v1.yfth.FranchisePartner/rewardCancel')->option(['real_name' => '招商收益取消']);
        Route::post('reward/:id/settle', 'v1.yfth.FranchisePartner/rewardSettle')->option(['real_name' => '招商收益线下结算']);
        Route::get('warning', 'v1.yfth.FranchisePartner/warningList')->option(['real_name' => '招商保级预警']);
        Route::get('promotion', 'v1.yfth.FranchisePartner/promotionList')->option(['real_name' => '招商晋级申请']);
        Route::post('promotion/:id/review', 'v1.yfth.FranchisePartner/promotionReview')->option(['real_name' => '招商晋级审批']);
        Route::post('opening/complete', 'v1.yfth.FranchisePartner/openingComplete')->option(['real_name' => '总部正式开店']);
    })->option(['parent' => 'yfth', 'cate_name' => '招商合伙人与开店']);
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
    Route::group('referral_reward', function () {
        Route::get('rule', 'v1.yfth.ReferralReward/ruleList')->option(['real_name' => 'Referral reward rule list']);
        Route::post('rule', 'v1.yfth.ReferralReward/ruleSave')->option(['real_name' => 'Referral reward rule save']);
        Route::post('rule/:id/publish', 'v1.yfth.ReferralReward/rulePublish')->option(['real_name' => 'Referral reward rule publish']);
        Route::post('rule/:id/copy', 'v1.yfth.ReferralReward/ruleCopy')->option(['real_name' => 'Referral reward rule copy']);
        Route::get('candidate', 'v1.yfth.ReferralReward/candidateList')->option(['real_name' => 'Referral candidate list']);
        Route::get('event', 'v1.yfth.ReferralReward/eventList')->option(['real_name' => 'Referral event list']);
        Route::get('attribution', 'v1.yfth.ReferralReward/attributionList')->option(['real_name' => 'Referral attribution list']);
        Route::get('ledger', 'v1.yfth.ReferralReward/ledgerList')->option(['real_name' => 'Reward ledger list']);
        Route::get('ledger/:id', 'v1.yfth.ReferralReward/ledgerDetail')->option(['real_name' => 'Reward ledger detail']);
        Route::post('ledger/:id/settle', 'v1.yfth.ReferralReward/ledgerSettle')->option(['real_name' => 'Reward ledger offline settlement mark']);
        Route::post('ledger/:id/cancel_settlement', 'v1.yfth.ReferralReward/ledgerCancelSettlement')->option(['real_name' => 'Reward ledger cancel offline settlement']);
        Route::post('ledger/:id/reverse', 'v1.yfth.ReferralReward/ledgerReverse')->option(['real_name' => 'Reward ledger reverse']);
        Route::post('scan', 'v1.yfth.ReferralReward/scan')->option(['real_name' => 'Referral reward compensation scan']);
    })->option(['parent' => 'yfth', 'cate_name' => 'Referral Reward']);
    Route::group('product_quota', function () {
        Route::get('account', 'v1.yfth.ProductQuota/accountList')->option(['real_name' => 'Product quota account list']);
        Route::get('account/:id', 'v1.yfth.ProductQuota/accountDetail')->option(['real_name' => 'Product quota account detail']);
        Route::get('ledger', 'v1.yfth.ProductQuota/ledgerList')->option(['real_name' => 'Product quota ledger list']);
        Route::get('grant', 'v1.yfth.ProductQuota/grantList')->option(['real_name' => 'Product quota grant list']);
        Route::post('grant', 'v1.yfth.ProductQuota/grantCreate')->option(['real_name' => 'Product quota grant create']);
        Route::post('grant/:id/confirm', 'v1.yfth.ProductQuota/grantConfirm')->option(['real_name' => 'Product quota grant confirm']);
        Route::post('grant/:id/reject', 'v1.yfth.ProductQuota/grantReject')->option(['real_name' => 'Product quota grant reject']);
        Route::post('grant/:id/reverse', 'v1.yfth.ProductQuota/grantReverse')->option(['real_name' => 'Product quota grant reverse']);
        Route::post('adjustment', 'v1.yfth.ProductQuota/adjustmentCreate')->option(['real_name' => 'Product quota adjustment create']);
        Route::post('account/:id/freeze', 'v1.yfth.ProductQuota/accountFreeze')->option(['real_name' => 'Product quota account freeze']);
        Route::post('account/:id/unfreeze', 'v1.yfth.ProductQuota/accountUnfreeze')->option(['real_name' => 'Product quota account unfreeze']);
        Route::post('account/:id/close', 'v1.yfth.ProductQuota/accountClose')->option(['real_name' => 'Product quota account close']);
    })->option(['parent' => 'yfth', 'cate_name' => 'Product Quota']);
    Route::group('monthly_benefit', function () {
        Route::get('fulfillment', 'v1.yfth.MonthlyBenefitFulfillment/index')->option(['real_name' => 'Monthly benefit fulfillment list']);
        Route::get('fulfillment/:id', 'v1.yfth.MonthlyBenefitFulfillment/detail')->option(['real_name' => 'Monthly benefit fulfillment detail']);
        Route::post('fulfillment/:id/confirm', 'v1.yfth.MonthlyBenefitFulfillment/confirm')->option(['real_name' => 'Monthly benefit fulfillment confirm']);
        Route::post('fulfillment/:id/reject', 'v1.yfth.MonthlyBenefitFulfillment/reject')->option(['real_name' => 'Monthly benefit fulfillment reject']);
        Route::post('fulfillment/:id/prepare', 'v1.yfth.MonthlyBenefitFulfillment/prepare')->option(['real_name' => 'Monthly benefit fulfillment prepare']);
        Route::post('fulfillment/:id/ship', 'v1.yfth.MonthlyBenefitFulfillment/ship')->option(['real_name' => 'Monthly benefit fulfillment ship']);
        Route::post('fulfillment/:id/complete', 'v1.yfth.MonthlyBenefitFulfillment/complete')->option(['real_name' => 'Monthly benefit fulfillment complete']);
        Route::post('fulfillment/:id/exception', 'v1.yfth.MonthlyBenefitFulfillment/exception')->option(['real_name' => 'Monthly benefit fulfillment exception']);
        Route::post('fulfillment/:id/cancel', 'v1.yfth.MonthlyBenefitFulfillment/cancel')->option(['real_name' => 'Monthly benefit fulfillment cancel']);
    })->option(['parent' => 'yfth', 'cate_name' => 'Monthly Benefit Fulfillment']);
    Route::group('hq_authority', function () {
        Route::get('attribution', 'v1.yfth.HqAuthorityRead/attributionList')->option(['real_name' => 'Headquarters attribution list']);
        Route::get('attribution/:id/events', 'v1.yfth.HqAuthorityRead/attributionEvents')->option(['real_name' => 'Headquarters attribution events']);
        Route::get('attribution/:id', 'v1.yfth.HqAuthorityRead/attributionDetail')->option(['real_name' => 'Headquarters attribution detail']);
        Route::get('referral', 'v1.yfth.HqAuthorityRead/referralList')->option(['real_name' => 'Headquarters referral list']);
        Route::get('referral/:id/events', 'v1.yfth.HqAuthorityRead/referralEvents')->option(['real_name' => 'Headquarters referral events']);
        Route::get('referral/:id', 'v1.yfth.HqAuthorityRead/referralDetail')->option(['real_name' => 'Headquarters referral detail']);
    })->option(['parent' => 'yfth', 'cate_name' => 'HQ Authority Read']);
    Route::group('package_membership', function () {
        Route::get('member', 'v1.yfth.PackageMembershipReferral/members')->option(['real_name' => 'Package permanent membership list']);
        Route::get('candidate', 'v1.yfth.PackageMembershipReferral/candidates')->option(['real_name' => 'Direct referral reward candidate list']);
        Route::get('rule', 'v1.yfth.PackageMembershipReferral/rules')->option(['real_name' => 'Direct referral rule list']);
        Route::post('rule', 'v1.yfth.PackageMembershipReferral/saveRule')->option(['real_name' => 'Direct referral rule save']);
        Route::post('rule/:id/publish', 'v1.yfth.PackageMembershipReferral/publishRule')->option(['real_name' => 'Direct referral rule publish']);
        Route::post('legacy_backfill', 'v1.yfth.PackageMembershipReferral/legacyBackfill')->option(['real_name' => 'Historical package membership backfill']);
    })->option(['parent' => 'yfth', 'cate_name' => 'Package Membership And Direct Referral']);
    Route::group('user_role', function () {
        Route::get('fixture', 'v1.yfth.HqUserRole/fixture')->option(['real_name' => '总部查看受控验收测试数据']);
        Route::post('fixture/generate', 'v1.yfth.HqUserRole/generateFixture')->option(['real_name' => '总部生成受控验收测试数据']);
        Route::post('fixture/reset', 'v1.yfth.HqUserRole/resetFixture')->option(['real_name' => '总部重置受控验收测试数据']);
        Route::post('fixture/password/reset', 'v1.yfth.HqUserRole/resetFixturePasswords')->option(['real_name' => '总部重置受控验收测试账号密码']);
        Route::get('user', 'v1.yfth.HqUserRole/users')->option(['real_name' => '总部用户经营身份列表']);
        Route::get('user/:uid', 'v1.yfth.HqUserRole/detail')->option(['real_name' => '总部用户经营身份详情']);
        Route::post('user/:uid/grant', 'v1.yfth.HqUserRole/grant')->option(['real_name' => '总部授予用户经营身份']);
        Route::post('user/:uid/membership/grant', 'v1.yfth.HqUserRole/grantMembership')->option(['real_name' => '总部授予用户永久会员']);
        Route::get('user/:uid/purge/preflight', 'v1.yfth.HqUserRole/purgePreflight')->option(['real_name' => '总部预检调试用户删除']);
        Route::delete('user/:uid/purge', 'v1.yfth.HqUserRole/purge')->option(['real_name' => '总部删除无业务事实的调试用户']);
        Route::post('role/:id/revoke', 'v1.yfth.HqUserRole/revoke')->option(['real_name' => '总部撤销用户经营身份']);
    })->option(['parent' => 'yfth', 'cate_name' => 'HQ User Role Management']);
    Route::group('reward_settlement', function () {
        Route::get('candidate', 'v1.yfth.RewardSettlement/candidates')->option(['real_name' => 'Direct referral reward settlement candidate list']);
        Route::post('candidate/:id/cancel', 'v1.yfth.RewardSettlement/cancel')->option(['real_name' => 'Direct referral reward candidate exception cancel']);
        Route::post('candidate/:id/correct', 'v1.yfth.RewardSettlement/correct')->option(['real_name' => 'Direct referral reward candidate exception correct']);
    })->option(['parent' => 'yfth', 'cate_name' => 'Reward Settlement Ledger']);
})->middleware([
    \app\http\middleware\AllowOriginMiddleware::class,
    \app\adminapi\middleware\AdminAuthTokenMiddleware::class,
    \app\adminapi\middleware\AdminCheckRoleMiddleware::class,
    \app\adminapi\middleware\AdminLogMiddleware::class
])->option(['mark' => 'yfth', 'mark_name' => '御方通和']);
