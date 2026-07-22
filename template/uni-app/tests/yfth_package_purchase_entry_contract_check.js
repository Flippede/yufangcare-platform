const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const failures = [];
const assertContains = (source, fragment, label) => {
  if (!source.includes(fragment)) failures.push(label);
};

const pages = read('pages.json');
const userCenter = read('pages/user/index.vue');
const membership = read('pages/yfth/package_membership/index.vue');
const list = read('pages/yfth/package/list.vue');
const detail = read('pages/yfth/package/detail.vue');
const agreement = read('pages/yfth/package/agreement_confirm.vue');
const payment = read('pages/yfth/package/payment_confirm.vue');
const api = read('api/yfth.js');

assertContains(pages, '"path": "package/list"', 'package_list_page_registered');
assertContains(userCenter, 'goYfthPackagePurchase', 'user_center_has_package_purchase_entry');
assertContains(userCenter, "url: '/pages/yfth/package/list'", 'user_center_opens_package_list');
assertContains(membership, "isMember ? '再次购买康养套餐'", 'member_repeat_purchase_panel_exists');
assertContains(membership, 'goPurchase()', 'membership_has_package_purchase_action');
assertContains(membership, "url: '/pages/yfth/package/list'", 'membership_opens_package_list');
assertContains(list, 'getYfthPackageList', 'package_list_uses_public_api');
assertContains(list, '(res.data && res.data.list) || []', 'package_list_uses_real_response_list');
assertContains(list, "url: '/pages/yfth/package/detail?id=' + item.id", 'package_list_uses_dynamic_detail_id');
assertContains(detail, 'getYfthPackageMembershipMe', 'detail_reads_authoritative_attribution');
assertContains(detail, 'attribution.status !== \'active\'', 'detail_requires_active_attribution');
assertContains(detail, '/pages/yfth/package/agreement_confirm?id=', 'detail_opens_agreement_with_authoritative_store');
assertContains(agreement, "'/pages/yfth/package/payment_confirm?id=' + this.id + '&store_id=' + this.storeId", 'agreement_opens_payment_confirmation');
assertContains(payment, 'getYfthPackageMembershipMe', 'payment_rechecks_authoritative_attribution');
assertContains(payment, 'this.storeId = storeId', 'payment_uses_authoritative_store');
assertContains(payment, 'createYfthPackageIntent', 'payment_uses_existing_intent');
assertContains(payment, 'createYfthPackageOrder', 'payment_uses_existing_crmeb_order');
assertContains(detail, '已是会员也可以再次购买', 'detail_explains_repeat_purchase');
assertContains(payment, '确认并支付', 'formal_payment_action_is_visible');
assertContains(payment, '<payment', 'formal_payment_component_is_mounted');
assertContains(payment, '每笔订单分别生成套餐权益', 'repeat_purchase_creates_independent_benefits');
assertContains(api, "request.get('yfth/package/list'", 'public_package_list_api_exists');
if (pages.includes('package/store_select')) failures.push('legacy_store_selection_page_must_be_removed');
if (api.includes('yfth/package/service_stores')) failures.push('legacy_public_store_list_api_must_be_removed');
if (api.includes('yfth/package/simulate')) failures.push('simulation_api_must_not_be_exposed');

if (failures.length) {
  failures.forEach((failure) => console.error(`[FAIL] ${failure}`));
  process.exit(1);
}

console.log('YFTH package purchase entry contract check passed.');
