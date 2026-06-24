import request from '@/utils/request.js';

export function getYfthPackageList(data) {
	return request.get('yfth/package/list', data || {}, { noAuth: true });
}

export function getYfthPackageDetail(id) {
	return request.get('yfth/package/detail/' + id, {}, { noAuth: true });
}

export function getYfthPackageStores(id) {
	return request.get('yfth/package/service_stores/' + id, {}, { noAuth: true });
}

export function getYfthPackageRulePreview(id, data) {
	return request.get('yfth/package/rule_preview/' + id, data || {}, { noAuth: true });
}

export function createYfthPackagePurchase(data) {
	return request.post('yfth/package/purchase', data);
}

export function getYfthPurchaseStatus(purchaseNo) {
	return request.get('yfth/package/purchase/' + purchaseNo);
}

export function getYfthMyPackages(data) {
	return request.get('yfth/package/my', data || {});
}

export function getYfthMyPackageDetail(id) {
	return request.get('yfth/package/my/' + id);
}

export function getYfthBenefitPlan(instanceId) {
	return request.get('yfth/package/plan/' + instanceId);
}

export function getYfthTimeline(instanceId) {
	return request.get('yfth/package/timeline/' + instanceId);
}

export function getYfthCurrentBenefits(data) {
	return request.get('yfth/package/current_benefits', data || {});
}

export function getYfthBenefitHistory(data) {
	return request.get('yfth/package/benefit_history', data || {});
}

export function getYfthAgreementRecord(purchaseNo) {
	return request.get('yfth/package/agreement/' + purchaseNo);
}
