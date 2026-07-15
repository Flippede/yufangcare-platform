import request from '@/libs/request';

export function yfthHomepageConfig() {
  return request({ url: 'yfth/homepage/config', method: 'get' });
}

export function yfthHomepageSave(config) {
  return request({ url: 'yfth/homepage/config', method: 'post', data: { config } });
}

export function yfthIdentityList(params) {
  return request({
    url: 'yfth/foundation/identity',
    method: 'get',
    params,
  });
}

export function yfthStoreRoleList(params) {
  return request({
    url: 'yfth/foundation/store_role',
    method: 'get',
    params,
  });
}

export function yfthUserRoleUsers(params) {
  return request({ url: 'yfth/user_role/user', method: 'get', params });
}

export function yfthUserRoleDetail(uid) {
  return request({ url: `yfth/user_role/user/${uid}`, method: 'get' });
}

export function yfthUserRoleGrant(uid, data) {
  return request({ url: `yfth/user_role/user/${uid}/grant`, method: 'post', data });
}

export function yfthUserRoleRevoke(id, data) {
  return request({ url: `yfth/user_role/role/${id}/revoke`, method: 'post', data });
}

export function yfthAcceptanceFixture() {
  return request({ url: 'yfth/user_role/fixture', method: 'get' });
}

export function yfthAcceptanceFixtureGenerate(data) {
  return request({ url: 'yfth/user_role/fixture/generate', method: 'post', data });
}

export function yfthAcceptanceFixtureReset(data) {
  return request({ url: 'yfth/user_role/fixture/reset', method: 'post', data });
}

export function yfthSubjectList(params) {
  return request({
    url: 'yfth/foundation/subject',
    method: 'get',
    params,
  });
}

export function yfthSubjectSave(data) {
  return request({
    url: 'yfth/foundation/subject/save',
    method: 'post',
    data,
  });
}

export function yfthStoreSubjectList(params) {
  return request({
    url: 'yfth/foundation/store_subject',
    method: 'get',
    params,
  });
}

export function yfthStoreSubjectSave(data) {
  return request({
    url: 'yfth/foundation/store_subject/save',
    method: 'post',
    data,
  });
}

export function yfthStoreSubjectDisable(data) {
  return request({
    url: 'yfth/foundation/store_subject/disable',
    method: 'post',
    data,
  });
}

export function yfthQualificationList(params) {
  return request({
    url: 'yfth/foundation/qualification',
    method: 'get',
    params,
  });
}

export function yfthQualificationSave(data) {
  return request({
    url: 'yfth/foundation/qualification/save',
    method: 'post',
    data,
  });
}

export function yfthQualificationAudit(data) {
  return request({
    url: 'yfth/foundation/qualification/audit',
    method: 'post',
    data,
  });
}

export function yfthCapabilityList(params) {
  return request({
    url: 'yfth/foundation/capability',
    method: 'get',
    params,
  });
}

export function yfthPaymentRouteList(params) {
  return request({
    url: 'yfth/foundation/payment_route',
    method: 'get',
    params,
  });
}

export function yfthPaymentRouteSave(data) {
  return request({
    url: 'yfth/foundation/payment_route/save',
    method: 'post',
    data,
  });
}

export function yfthPaymentRouteDisable(data) {
  return request({
    url: 'yfth/foundation/payment_route/disable',
    method: 'post',
    data,
  });
}

export function yfthPaymentRouteResolve(params) {
  return request({
    url: 'yfth/foundation/payment_route/resolve',
    method: 'get',
    params,
  });
}

export function yfthAuditEventList(params) {
  return request({
    url: 'yfth/foundation/audit_event',
    method: 'get',
    params,
  });
}

export function yfthPackageTemplateList(params) {
  return request({
    url: 'yfth/package_benefit/template',
    method: 'get',
    params,
  });
}

export function yfthPackageTemplateSave(data) {
  return request({
    url: 'yfth/package_benefit/template/save',
    method: 'post',
    data,
  });
}

export function yfthPackageRuleSave(data) {
  return request({
    url: 'yfth/package_benefit/rule/save',
    method: 'post',
    data,
  });
}

export function yfthPackageRuleCopy(id) {
  return request({
    url: `yfth/package_benefit/rule/${id}/copy`,
    method: 'post',
  });
}

export function yfthPackageBindingSave(data) {
  return request({
    url: 'yfth/package_benefit/binding/save',
    method: 'post',
    data,
  });
}

export function yfthBenefitTemplateList(params) {
  return request({
    url: 'yfth/package_benefit/benefit_template',
    method: 'get',
    params,
  });
}

export function yfthBenefitTemplateSave(data) {
  return request({
    url: 'yfth/package_benefit/benefit_template/save',
    method: 'post',
    data,
  });
}

export function yfthMonthlyRuleList(params) {
  return request({
    url: 'yfth/package_benefit/monthly_rule',
    method: 'get',
    params,
  });
}

export function yfthMonthlyRuleSave(data) {
  return request({
    url: 'yfth/package_benefit/monthly_rule/save',
    method: 'post',
    data,
  });
}

export function yfthPackagePurchaseList(params) {
  return request({
    url: 'yfth/package_benefit/purchase',
    method: 'get',
    params,
  });
}

export function yfthPackageInstanceList(params) {
  return request({
    url: 'yfth/package_benefit/instance',
    method: 'get',
    params,
  });
}

export function yfthPackageInstanceDetail(id) {
  return request({
    url: `yfth/package_benefit/instance/${id}`,
    method: 'get',
  });
}

export function yfthPackageInstanceState(id, data) {
  return request({
    url: `yfth/package_benefit/instance/${id}/state`,
    method: 'post',
    data,
  });
}

export function yfthPackageInstanceLifecycle(id, data) {
  return request({
    url: `yfth/package_benefit/instance/${id}/lifecycle`,
    method: 'post',
    data,
  });
}

export function yfthBenefitPlanList(params) {
  return request({
    url: 'yfth/package_benefit/plan',
    method: 'get',
    params,
  });
}

export function yfthOpenDuePeriods(data) {
  return request({
    url: 'yfth/package_benefit/period/open_due',
    method: 'post',
    data,
  });
}

export function yfthPackageActivationRecover(data) {
  return request({
    url: 'yfth/package_benefit/activation/recover',
    method: 'post',
    data,
  });
}

export function yfthPackageActivationRetry(id, data) {
  return request({
    url: `yfth/package_benefit/purchase/${id}/activation_retry`,
    method: 'post',
    data,
  });
}

export function yfthPackageOrphanScan(data) {
  return request({
    url: 'yfth/package_benefit/orphan/scan',
    method: 'post',
    data,
  });
}

export function yfthServiceProjectList(params) {
  return request({
    url: 'yfth/service_appointment/project',
    method: 'get',
    params,
  });
}

export function yfthServiceProjectSave(data) {
  return request({
    url: 'yfth/service_appointment/project/save',
    method: 'post',
    data,
  });
}

export function yfthServiceProjectDisable(data) {
  return request({
    url: 'yfth/service_appointment/project/disable',
    method: 'post',
    data,
  });
}

export function yfthStoreServiceList(params) {
  return request({
    url: 'yfth/service_appointment/store_service',
    method: 'get',
    params,
  });
}

export function yfthStoreServiceSave(data) {
  return request({
    url: 'yfth/service_appointment/store_service/save',
    method: 'post',
    data,
  });
}

export function yfthStoreServiceDisable(data) {
  return request({
    url: 'yfth/service_appointment/store_service/disable',
    method: 'post',
    data,
  });
}

export function yfthServiceScheduleRuleList(params) {
  return request({
    url: 'yfth/service_appointment/schedule_rule',
    method: 'get',
    params,
  });
}

export function yfthServiceScheduleRuleSave(data) {
  return request({
    url: 'yfth/service_appointment/schedule_rule/save',
    method: 'post',
    data,
  });
}

export function yfthServiceScheduleRuleDisable(data) {
  return request({
    url: 'yfth/service_appointment/schedule_rule/disable',
    method: 'post',
    data,
  });
}

export function yfthServiceSpecialDayList(params) {
  return request({
    url: 'yfth/service_appointment/special_day',
    method: 'get',
    params,
  });
}

export function yfthServiceSpecialDaySave(data) {
  return request({
    url: 'yfth/service_appointment/special_day/save',
    method: 'post',
    data,
  });
}

export function yfthServiceSpecialDayDisable(data) {
  return request({
    url: 'yfth/service_appointment/special_day/disable',
    method: 'post',
    data,
  });
}

export function yfthServiceSlotPreview(params) {
  return request({
    url: 'yfth/service_appointment/slot_preview',
    method: 'get',
    params,
  });
}

export function yfthServiceAppointmentList(params) {
  return request({
    url: 'yfth/service_appointment/appointment',
    method: 'get',
    params,
  });
}

export function yfthServiceAppointmentDetail(id) {
  return request({
    url: `yfth/service_appointment/appointment/${id}`,
    method: 'get',
  });
}

export function yfthServiceAppointmentConfirm(id, data) {
  return request({
    url: `yfth/service_appointment/appointment/${id}/confirm`,
    method: 'post',
    data,
  });
}

export function yfthServiceAppointmentReject(id, data) {
  return request({
    url: `yfth/service_appointment/appointment/${id}/reject`,
    method: 'post',
    data,
  });
}

export function yfthServiceAppointmentCancel(id, data) {
  return request({
    url: `yfth/service_appointment/appointment/${id}/cancel`,
    method: 'post',
    data,
  });
}

export function yfthServiceWriteoffList(params) {
  return request({
    url: 'yfth/service_appointment/writeoff',
    method: 'get',
    params,
  });
}

export function yfthServiceWriteoffDetail(id) {
  return request({
    url: `yfth/service_appointment/writeoff/record/${id}`,
    method: 'get',
  });
}

export function yfthServiceWriteoffResult(id) {
  return request({
    url: `yfth/service_appointment/writeoff/${id}`,
    method: 'get',
  });
}

export function yfthServiceAppointmentExceptionWriteoff(id, data) {
  return request({
    url: `yfth/service_appointment/appointment/${id}/exception_writeoff`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseApplicationList(params) {
  return request({
    url: 'yfth/franchise_application/application',
    method: 'get',
    params,
  });
}

export function yfthFranchiseApplicationDetail(id) {
  return request({
    url: `yfth/franchise_application/application/${id}`,
    method: 'get',
  });
}

export function yfthFranchiseApplicationAssign(id, data) {
  return request({
    url: `yfth/franchise_application/application/${id}/assign`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseApplicationStatus(id, data) {
  return request({
    url: `yfth/franchise_application/application/${id}/status`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseApplicationFollow(id, data) {
  return request({
    url: `yfth/franchise_application/application/${id}/follow`,
    method: 'post',
    data,
  });
}

export function yfthSupplyCatalogList(params) {
  return request({
    url: 'yfth/supply_chain/catalog',
    method: 'get',
    params,
  });
}

export function yfthSupplyCatalogSave(data) {
  return request({
    url: 'yfth/supply_chain/catalog/save',
    method: 'post',
    data,
  });
}

export function yfthSupplyCatalogDisable(data) {
  return request({
    url: 'yfth/supply_chain/catalog/disable',
    method: 'post',
    data,
  });
}

export function yfthSupplyProductSearch(params) {
  return request({
    url: 'yfth/supply_chain/product/search',
    method: 'get',
    params,
  });
}

export function yfthPurchaseOrderList(params) {
  return request({
    url: 'yfth/supply_chain/purchase_order',
    method: 'get',
    params,
  });
}

export function yfthPurchaseOrderDetail(id) {
  return request({
    url: `yfth/supply_chain/purchase_order/${id}`,
    method: 'get',
  });
}

export function yfthPurchaseOrderAudit(id, data) {
  return request({
    url: `yfth/supply_chain/purchase_order/${id}/audit`,
    method: 'post',
    data,
  });
}

export function yfthPurchaseOrderShip(id, data) {
  return request({
    url: `yfth/supply_chain/purchase_order/${id}/ship`,
    method: 'post',
    data,
  });
}

export function yfthSupplyShipmentList(params) {
  return request({
    url: 'yfth/supply_chain/shipment',
    method: 'get',
    params,
  });
}

export function yfthInventoryBalanceList(params) {
  return request({
    url: 'yfth/supply_chain/inventory',
    method: 'get',
    params,
  });
}

export function yfthInventoryLedgerList(params) {
  return request({
    url: 'yfth/supply_chain/ledger',
    method: 'get',
    params,
  });
}

export function yfthInventoryAlertRuleList(params) {
  return request({
    url: 'yfth/supply_chain/alert_rule',
    method: 'get',
    params,
  });
}

export function yfthInventoryAlertRuleSave(data) {
  return request({
    url: 'yfth/supply_chain/alert_rule/save',
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningContractList(params) {
  return request({
    url: 'yfth/franchise_opening/contract',
    method: 'get',
    params,
  });
}

export function yfthFranchiseOpeningContractDetail(id) {
  return request({
    url: `yfth/franchise_opening/contract/${id}`,
    method: 'get',
  });
}

export function yfthFranchiseOpeningContractCreate(data) {
  return request({
    url: 'yfth/franchise_opening/contract/create',
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningContractConfirm(id, data) {
  return request({
    url: `yfth/franchise_opening/contract/${id}/confirm`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningPaymentList(params) {
  return request({
    url: 'yfth/franchise_opening/payment',
    method: 'get',
    params,
  });
}

export function yfthFranchiseOpeningPaymentConfirm(id) {
  return request({
    url: `yfth/franchise_opening/payment/${id}/confirm`,
    method: 'post',
  });
}

export function yfthFranchiseOpeningPaymentReject(id, data) {
  return request({
    url: `yfth/franchise_opening/payment/${id}/reject`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningProfileDetail(applicationId) {
  return request({
    url: `yfth/franchise_opening/profile/${applicationId}`,
    method: 'get',
  });
}

export function yfthFranchiseOpeningProfileSave(data) {
  return request({
    url: 'yfth/franchise_opening/profile/save',
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningProfileBindStore(id, data) {
  return request({
    url: `yfth/franchise_opening/profile/${id}/bind_store`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningTaskList(params) {
  return request({
    url: 'yfth/franchise_opening/task',
    method: 'get',
    params,
  });
}

export function yfthFranchiseOpeningTaskReview(id, data) {
  return request({
    url: `yfth/franchise_opening/task/${id}/review`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningAcceptanceList(params) {
  return request({
    url: 'yfth/franchise_opening/acceptance',
    method: 'get',
    params,
  });
}

export function yfthFranchiseOpeningAcceptanceDetail(id) {
  return request({
    url: `yfth/franchise_opening/acceptance/${id}`,
    method: 'get',
  });
}

export function yfthFranchiseOpeningAcceptanceReview(id, data) {
  return request({
    url: `yfth/franchise_opening/acceptance/${id}/review`,
    method: 'post',
    data,
  });
}

export function yfthFranchiseOpeningIdentityGrant(data) {
  return request({
    url: 'yfth/franchise_opening/identity_grant',
    method: 'post',
    data,
  });
}

export function yfthReferralRewardRuleList(params) {
  return request({
    url: 'yfth/referral_reward/rule',
    method: 'get',
    params,
  });
}

export function yfthReferralRewardRuleSave(data) {
  return request({
    url: 'yfth/referral_reward/rule',
    method: 'post',
    data,
  });
}

export function yfthReferralRewardRulePublish(id) {
  return request({
    url: `yfth/referral_reward/rule/${id}/publish`,
    method: 'post',
  });
}

export function yfthReferralRewardRuleCopy(id) {
  return request({
    url: `yfth/referral_reward/rule/${id}/copy`,
    method: 'post',
  });
}

export function yfthReferralCandidateList(params) {
  return request({
    url: 'yfth/referral_reward/candidate',
    method: 'get',
    params,
  });
}

export function yfthReferralEventList(params) {
  return request({
    url: 'yfth/referral_reward/event',
    method: 'get',
    params,
  });
}

export function yfthReferralAttributionList(params) {
  return request({
    url: 'yfth/referral_reward/attribution',
    method: 'get',
    params,
  });
}

export function yfthRewardLedgerList(params) {
  return request({
    url: 'yfth/referral_reward/ledger',
    method: 'get',
    params,
  });
}

export function yfthRewardLedgerDetail(id) {
  return request({
    url: `yfth/referral_reward/ledger/${id}`,
    method: 'get',
  });
}

export function yfthRewardLedgerSettle(id, data) {
  return request({
    url: `yfth/referral_reward/ledger/${id}/settle`,
    method: 'post',
    data,
  });
}

export function yfthRewardLedgerCancelSettlement(id, data) {
  return request({
    url: `yfth/referral_reward/ledger/${id}/cancel_settlement`,
    method: 'post',
    data,
  });
}

export function yfthRewardLedgerReverse(id, data) {
  return request({
    url: `yfth/referral_reward/ledger/${id}/reverse`,
    method: 'post',
    data,
  });
}

export function yfthReferralRewardScan(data) {
  return request({
    url: 'yfth/referral_reward/scan',
    method: 'post',
    data,
  });
}

export function yfthProductQuotaAccountList(params) {
  return request({
    url: 'yfth/product_quota/account',
    method: 'get',
    params,
  });
}

export function yfthProductQuotaAccountDetail(id) {
  return request({
    url: `yfth/product_quota/account/${id}`,
    method: 'get',
  });
}

export function yfthProductQuotaLedgerList(params) {
  return request({
    url: 'yfth/product_quota/ledger',
    method: 'get',
    params,
  });
}

export function yfthProductQuotaGrantList(params) {
  return request({
    url: 'yfth/product_quota/grant',
    method: 'get',
    params,
  });
}

export function yfthProductQuotaGrantCreate(data) {
  return request({
    url: 'yfth/product_quota/grant',
    method: 'post',
    data,
  });
}

export function yfthProductQuotaGrantConfirm(id) {
  return request({
    url: `yfth/product_quota/grant/${id}/confirm`,
    method: 'post',
  });
}

export function yfthProductQuotaGrantReject(id, data) {
  return request({
    url: `yfth/product_quota/grant/${id}/reject`,
    method: 'post',
    data,
  });
}

export function yfthProductQuotaGrantReverse(id, data) {
  return request({
    url: `yfth/product_quota/grant/${id}/reverse`,
    method: 'post',
    data,
  });
}

export function yfthProductQuotaAdjustmentCreate(data) {
  return request({
    url: 'yfth/product_quota/adjustment',
    method: 'post',
    data,
  });
}

export function yfthProductQuotaAccountFreeze(id, data) {
  return request({
    url: `yfth/product_quota/account/${id}/freeze`,
    method: 'post',
    data,
  });
}

export function yfthProductQuotaAccountUnfreeze(id, data) {
  return request({
    url: `yfth/product_quota/account/${id}/unfreeze`,
    method: 'post',
    data,
  });
}

export function yfthProductQuotaAccountClose(id, data) {
  return request({
    url: `yfth/product_quota/account/${id}/close`,
    method: 'post',
    data,
  });
}

export function yfthMonthlyBenefitFulfillmentList(params) {
  return request({
    url: 'yfth/monthly_benefit/fulfillment',
    method: 'get',
    params,
  });
}

export function yfthMonthlyBenefitFulfillmentDetail(id) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}`,
    method: 'get',
  });
}

export function yfthMonthlyBenefitFulfillmentConfirm(id, data) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}/confirm`,
    method: 'post',
    data,
  });
}

export function yfthMonthlyBenefitFulfillmentReject(id, data) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}/reject`,
    method: 'post',
    data,
  });
}

export function yfthMonthlyBenefitFulfillmentPrepare(id, data) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}/prepare`,
    method: 'post',
    data,
  });
}

export function yfthMonthlyBenefitFulfillmentShip(id, data) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}/ship`,
    method: 'post',
    data,
  });
}

export function yfthMonthlyBenefitFulfillmentComplete(id, data) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}/complete`,
    method: 'post',
    data,
  });
}

export function yfthMonthlyBenefitFulfillmentException(id, data) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}/exception`,
    method: 'post',
    data,
  });
}

export function yfthMonthlyBenefitFulfillmentCancel(id, data) {
  return request({
    url: `yfth/monthly_benefit/fulfillment/${id}/cancel`,
    method: 'post',
    data,
  });
}

export function yfthPackageMembershipMemberList(params) {
  return request({ url: 'yfth/package_membership/member', method: 'get', params });
}

export function yfthPackageMembershipCandidateList(params) {
  return request({ url: 'yfth/package_membership/candidate', method: 'get', params });
}

export function yfthRewardSettlementCandidateList(params) {
  return request({ url: 'yfth/reward_settlement/candidate', method: 'get', params });
}

export function yfthRewardSettlementCandidateCancel(id, data) {
  return request({ url: `yfth/reward_settlement/candidate/${id}/cancel`, method: 'post', data });
}

export function yfthRewardSettlementCandidateCorrect(id, data) {
  return request({ url: `yfth/reward_settlement/candidate/${id}/correct`, method: 'post', data });
}

export function yfthPackageMembershipRuleList(params) {
  return request({ url: 'yfth/package_membership/rule', method: 'get', params });
}

export function yfthPackageMembershipRuleSave(data) {
  return request({ url: 'yfth/package_membership/rule', method: 'post', data });
}

export function yfthPackageMembershipRulePublish(id) {
  return request({ url: `yfth/package_membership/rule/${id}/publish`, method: 'post' });
}

export function yfthPackageMembershipLegacyBackfill(data) {
  return request({ url: 'yfth/package_membership/legacy_backfill', method: 'post', data });
}

export function yfthHqAuthorityAttributionList(params) {
  return request({ url: 'yfth/hq_authority/attribution', method: 'get', params });
}

export function yfthHqAuthorityAttributionDetail(id) {
  return request({ url: `yfth/hq_authority/attribution/${id}`, method: 'get' });
}

export function yfthHqAuthorityAttributionEvents(id) {
  return request({ url: `yfth/hq_authority/attribution/${id}/events`, method: 'get' });
}

export function yfthHqAuthorityReferralList(params) {
  return request({ url: 'yfth/hq_authority/referral', method: 'get', params });
}

export function yfthHqAuthorityReferralDetail(id) {
  return request({ url: `yfth/hq_authority/referral/${id}`, method: 'get' });
}

export function yfthHqAuthorityReferralEvents(id) {
  return request({ url: `yfth/hq_authority/referral/${id}/events`, method: 'get' });
}
