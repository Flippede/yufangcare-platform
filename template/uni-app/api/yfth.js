import request from '@/utils/request.js';

export function getYfthHomepage() {
	return request.get('yfth/homepage', {}, { noAuth: true });
}

export function getYfthIdentities() {
	return request.get('yfth/identities');
}

export function getYfthContext(data) {
	return request.get('yfth/context', data || {});
}

export function getYfthStoreAcquisitionCode(data) {
	return request.get('yfth/store_acquisition/code', data || {});
}

export function issueYfthStoreAcquisitionCode(data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/store_acquisition/code' + payload.query, payload.body);
}

export function resolveYfthStoreAcquisitionCode(token) {
	return request.get('yfth/store_acquisition/resolve', { acquisition_token: token }, { noAuth: true });
}

export function acceptYfthStoreAcquisitionCode(data) {
	return request.post('yfth/store_acquisition/accept', data || {});
}

export function checkYfthCapability(capability, data) {
	return request.get('yfth/capability/' + capability, data || {});
}

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

export function createYfthPackageIntent(data) {
	return request.post('yfth/package/intent', data);
}

export function createYfthPackageOrder(data) {
	return request.post('yfth/package/order', data);
}

export function getYfthPurchaseStatus(purchaseNo) {
	return request.get('yfth/package/purchase/' + purchaseNo);
}

export function getYfthPackageSimulationContext(id) {
	return request.get('yfth/package/simulation_context/' + id);
}

export function simulateYfthPackagePurchase(data) {
	return request.post('yfth/package/simulate', data || {});
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

export function getYfthServiceProjects(data) {
	return request.get('yfth/service/project', data || {}, { noAuth: true });
}

export function getYfthServiceProjectDetail(id) {
	return request.get('yfth/service/project/' + id, {}, { noAuth: true });
}

export function getYfthServiceStores(id) {
	return request.get('yfth/service/project/' + id + '/stores', {}, { noAuth: true });
}

export function getYfthServiceAvailableDates(id, data) {
	return request.get('yfth/service/project/' + id + '/dates', data || {}, { noAuth: true });
}

export function getYfthServiceDaySlots(id, data) {
	return request.get('yfth/service/project/' + id + '/slots', data || {}, { noAuth: true });
}

export function getYfthAppointmentBenefits(data) {
	return request.get('yfth/service/appointment/benefits', data || {});
}

export function createYfthServiceAppointment(data) {
	return request.post('yfth/service/appointment', data || {});
}

export function getYfthMyAppointments(data) {
	return request.get('yfth/service/appointment/my', data || {});
}

export function getYfthAppointmentDetail(id) {
	return request.get('yfth/service/appointment/' + id);
}

export function cancelYfthServiceAppointment(id, data) {
	return request.post('yfth/service/appointment/' + id + '/cancel', data || {});
}

export function getYfthAppointmentRescheduleSlots(id, data) {
	return request.get('yfth/service/appointment/' + id + '/reschedule_slots', data || {});
}

export function rescheduleYfthServiceAppointment(id, data) {
	return request.post('yfth/service/appointment/' + id + '/reschedule', data || {});
}

export function getYfthAppointmentCodeStatus(id) {
	return request.get('yfth/service/appointment/' + id + '/code_status');
}

export function generateYfthAppointmentCode(id, data) {
	return request.post('yfth/service/appointment/' + id + '/code', data || {});
}

export function getYfthStoreWorkbenchOverview(data) {
	return request.get('yfth/store_workbench/overview', data || {});
}

export function getYfthStoreWorkbenchAppointments(data) {
	return request.get('yfth/store_workbench/appointments', data || {});
}

export function getYfthStoreWorkbenchAppointmentDetail(id, data) {
	return request.get('yfth/store_workbench/appointments/' + id, data || {});
}

export function confirmYfthStoreWorkbenchAppointment(id, data) {
	return request.post('yfth/store_workbench/appointments/' + id + '/confirm', data || {});
}

export function rejectYfthStoreWorkbenchAppointment(id, data) {
	return request.post('yfth/store_workbench/appointments/' + id + '/reject', data || {});
}

export function cancelYfthStoreWorkbenchAppointment(id, data) {
	return request.post('yfth/store_workbench/appointments/' + id + '/cancel', data || {});
}

export function precheckYfthStoreWorkbenchWriteoff(data) {
	return request.post('yfth/store_workbench/writeoff/precheck', data || {});
}

export function writeoffYfthStoreWorkbenchByToken(qrToken, data) {
	return request.post('yfth/store_workbench/writeoff/token', Object.assign({ qr_token: qrToken }, data || {}));
}

export function writeoffYfthStoreWorkbenchByDigital(digitalCode, data) {
	return request.post('yfth/store_workbench/writeoff/digital', Object.assign({ digital_code: digitalCode }, data || {}));
}

export function getYfthStoreWorkbenchWriteoffRecords(data) {
	return request.get('yfth/store_workbench/writeoff/records', data || {});
}

export function getYfthStoreWorkbenchWriteoffRecordDetail(id, data) {
	return request.get('yfth/store_workbench/writeoff/records/' + id, data || {});
}

export function getYfthStoreWorkbenchWriteoffResult(appointmentId, data) {
	return request.get('yfth/store_workbench/writeoff/result/' + appointmentId, data || {});
}

export function getYfthStoreWorkbenchOrders(data) {
	return request.get('yfth/store_workbench/orders', data || {});
}

export function getYfthStoreWorkbenchOrderDetail(id, data) {
	return request.get('yfth/store_workbench/orders/' + id, data || {});
}

export function getYfthCustomerList(data) {
	return request.get('yfth/customer/list', data || {});
}

export function getYfthCustomerDetail(id, data) {
	return request.get('yfth/customer/' + id, data || {});
}

export function createYfthCustomerRelation(data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/customer/relation' + payload.query, payload.body);
}

export function addYfthCustomerFollow(id, data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/customer/' + id + '/follow' + payload.query, payload.body);
}

export function getYfthFranchiseApplications(data) {
	return request.get('yfth/franchise/application/my', data || {});
}

export function getYfthFranchiseApplicationDetail(id) {
	return request.get('yfth/franchise/application/' + id);
}

export function submitYfthFranchiseApplication(data) {
	return request.post('yfth/franchise/application', data || {});
}

export function getYfthFranchiseOpening() {
	return request.get('yfth/franchise/opening/my');
}

export function getYfthFranchiseOpeningContract(id) {
	return request.get('yfth/franchise/opening/contract/' + id);
}

export function confirmYfthFranchiseOpeningContract(id) {
	return request.post('yfth/franchise/opening/contract/' + id + '/confirm', {});
}

export function uploadYfthFranchisePaymentProof(id, data) {
	return request.post('yfth/franchise/opening/payment/' + id + '/proof', data || {});
}

export function getYfthFranchiseOpeningTasks() {
	return request.get('yfth/franchise/opening/tasks');
}

export function submitYfthFranchiseOpeningTask(id, data) {
	return request.post('yfth/franchise/opening/tasks/' + id + '/submit', data || {});
}

export function getYfthFranchiseOpeningAcceptance() {
	return request.get('yfth/franchise/opening/acceptance');
}

export function submitYfthFranchiseOpeningAcceptance(data) {
	return request.post('yfth/franchise/opening/acceptance/submit', data || {});
}

export function getYfthSupplyCatalog(data) {
	return request.get('yfth/supply/catalog', data || {});
}

export function createYfthPurchaseOrder(data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/supply/purchase_order' + payload.query, payload.body);
}

export function getYfthPurchaseOrders(data) {
	return request.get('yfth/supply/purchase_order', data || {});
}

export function getYfthPurchaseOrderDetail(id, data) {
	return request.get('yfth/supply/purchase_order/' + id, data || {});
}

export function getYfthSupplyInTransit(data) {
	return request.get('yfth/supply/in_transit', data || {});
}

export function receiveYfthPurchaseOrder(id, data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/supply/purchase_order/' + id + '/receive' + payload.query, payload.body);
}

export function getYfthInventory(data) {
	return request.get('yfth/supply/inventory', data || {});
}

export function getYfthInventoryLedger(data) {
	return request.get('yfth/supply/ledger', data || {});
}

export function createYfthReferralCode(data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/referral/code' + payload.query, payload.body);
}

export function getYfthReferralCode(data) {
	return request.get('yfth/referral/code', data || {});
}

export function bindYfthReferralCode(data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/referral/bind' + payload.query, payload.body);
}

export function getYfthReferralCandidates(data) {
	return request.get('yfth/referral/candidates', data || {});
}

export function getYfthRewardLedger(data) {
	return request.get('yfth/referral/ledger', data || {});
}

export function getYfthRewardLedgerDetail(id, data) {
	return request.get('yfth/referral/ledger/' + id, data || {});
}

export function getYfthProductQuotaSummary(data) {
	return request.get('yfth/product_quota/summary', data || {});
}

export function getYfthProductQuotaAccounts(data) {
	return request.get('yfth/product_quota/account', data || {});
}

export function getYfthProductQuotaAccountDetail(id, data) {
	return request.get('yfth/product_quota/account/' + id, data || {});
}

export function getYfthProductQuotaLedger(data) {
	return request.get('yfth/product_quota/ledger', data || {});
}

export function getYfthMonthlyBenefitCurrent(data) {
	return request.get('yfth/monthly_benefit/current', data || {});
}

export function getYfthMonthlyBenefitHistory(data) {
	return request.get('yfth/monthly_benefit/history', data || {});
}

export function getYfthMonthlyBenefitFulfillment(id, data) {
	return request.get('yfth/monthly_benefit/fulfillment/' + id, data || {});
}

export function claimYfthMonthlyBenefit(data) {
	return request.post('yfth/monthly_benefit/claim', data || {});
}

export function cancelYfthMonthlyBenefitFulfillment(id, data) {
	return request.post('yfth/monthly_benefit/fulfillment/' + id + '/cancel', data || {});
}

export function getYfthStoreWorkbenchMonthlyBenefitPickup(data) {
	return request.get('yfth/store_workbench/monthly_benefit/pickup', data || {});
}

export function getYfthStoreWorkbenchMonthlyBenefitPickupDetail(id, data) {
	return request.get('yfth/store_workbench/monthly_benefit/pickup/' + id, data || {});
}

export function confirmYfthStoreWorkbenchMonthlyBenefitPickup(id, data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/store_workbench/monthly_benefit/pickup/' + id + '/confirm' + payload.query, payload.body);
}

export function getYfthPackageMembershipMe() {
	return request.get('yfth/package_membership/me');
}

export function issueYfthDirectReferralInvite(data) {
	return request.post('yfth/package_membership/invite', data || {});
}

export function acceptYfthDirectReferralInvite(data) {
	return request.post('yfth/package_membership/invite/accept', data || {});
}

export function getYfthDirectReferralCandidates(data) {
	return request.get('yfth/package_membership/candidate', data || {});
}

export function getYfthDirectReferrals(data) {
	return request.get('yfth/package_membership/referral', data || {});
}

export function getYfthStorePackageMembers(data) {
	return request.get('yfth/store_workbench/package_membership/member', data || {});
}

export function getYfthStorePackageCandidates(data) {
	return request.get('yfth/store_workbench/package_membership/candidate', data || {});
}

export function getYfthStoreRewardSettlementCandidates(data) {
	return request.get('yfth/store_workbench/reward_settlement/candidate', data || {});
}

export function confirmYfthStoreRewardCandidate(id, data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/store_workbench/reward_settlement/candidate/' + id + '/confirm' + payload.query, payload.body);
}

export function settleYfthStoreRewardCandidate(id, data) {
	const payload = splitYfthContext(data || {});
	return request.post('yfth/store_workbench/reward_settlement/candidate/' + id + '/settle' + payload.query, payload.body);
}

export function getYfthMyHqAuthority() {
	return request.get('yfth/hq_authority/me');
}

export function getYfthStoreCustomerAttributions(data) {
	return request.get('yfth/store_workbench/customer_attribution', data || {});
}

export function getYfthStoreCustomerAttributionDetail(id, data) {
	return request.get('yfth/store_workbench/customer_attribution/' + id, data || {});
}

export function getYfthPartnerWorkbench() {
	return request.get('yfth/franchise/partner/workbench');
}

export function createYfthPartnerInvite(data) {
	return request.post('yfth/franchise/partner/invite', data || {});
}

export function getYfthPartnerTeam() {
	return request.get('yfth/franchise/partner/team');
}

export function getYfthPartnerRewards(data) {
	return request.get('yfth/franchise/partner/rewards', data || {});
}

export function applyYfthPartnerPromotion(data) {
	return request.post('yfth/franchise/partner/promotion/apply', data);
}

function splitYfthContext(data) {
	const body = Object.assign({}, data || {});
	const query = {};
	['role_code', 'store_id'].forEach((key) => {
		if (body[key] !== undefined && body[key] !== null && body[key] !== '') {
			query[key] = body[key];
			delete body[key];
		}
	});
	const queryString = Object.keys(query).length ? ('?' + Object.keys(query).map((key) => {
		return encodeURIComponent(key) + '=' + encodeURIComponent(query[key]);
	}).join('&')) : '';
	return { body, query: queryString };
}

export function generateYfthPermanentMembershipIdentityCode() { return request.post('yfth/permanent_membership/identity_code', {}); }
export function getYfthPermanentMembershipMe() { return request.get('yfth/permanent_membership/me'); }
export function confirmYfthPermanentMembership(data) { return request.post('yfth/permanent_membership/confirm', data || {}); }
export function getYfthStorePermanentMemberships(data) { return request.get('yfth/store_workbench/permanent_membership', data || {}); }
export function createYfthStorePermanentMembership(data) { const payload = splitYfthContext(data || {}); return request.post('yfth/store_workbench/permanent_membership' + payload.query, payload.body); }
export function bindYfthStorePermanentMembership(id, data) { const payload = splitYfthContext(data || {}); return request.post('yfth/store_workbench/permanent_membership/' + id + '/bind' + payload.query, payload.body); }
export function payYfthStorePermanentMembership(id, data) { const payload = splitYfthContext(data || {}); return request.post('yfth/store_workbench/permanent_membership/' + id + '/payment' + payload.query, payload.body); }
export function codeYfthStorePermanentMembership(id, data) { const payload = splitYfthContext(data || {}); return request.post('yfth/store_workbench/permanent_membership/' + id + '/confirmation_code' + payload.query, payload.body); }
