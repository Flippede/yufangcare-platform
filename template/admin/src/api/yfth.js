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

export function yfthAuditEventList(params) {
  return request({
    url: 'yfth/foundation/audit_event',
    method: 'get',
    params,
  });
}
