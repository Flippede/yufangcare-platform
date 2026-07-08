import request from '@/libs/request';

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
