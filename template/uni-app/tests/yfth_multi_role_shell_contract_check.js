const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');

function read(relativePath) {
	return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function assert(condition, message) {
	if (!condition) {
		throw new Error(message);
	}
}

function assertContains(file, text, message) {
	assert(read(file).includes(text), message || `${file} missing ${text}`);
}

function assertNotContains(file, text, message) {
	assert(!read(file).includes(text), message || `${file} must not contain ${text}`);
}

const pagesJson = read('pages.json');
assertContains('pages.json', '"path": "workbench/index"', 'workbench index must be registered');
assertContains('pages.json', '"path": "workbench/role_switch"', 'role switch page must be registered');
assertContains('pages.json', '"path": "workbench/store_switch"', 'store switch page must be registered');
assertContains('pages.json', '"path": "workbench/customer/index"', 'customer relation page must be registered');
assertContains('pages.json', '"path": "workbench/customer/detail"', 'customer detail page must be registered');
assertContains('pages.json', '"path": "workbench/customer/follow"', 'customer follow page must be registered');
assert(pagesJson.includes('"root": "pages/yfth"'), 'YFTH subpackage must be registered');

const userPage = read('pages/user/index.vue');
assert(userPage.includes('hasYfthBusinessIdentity'), 'user center entry must be gated by business identity');
assert(userPage.includes('loadYfthIdentities'), 'business entry must read server-side identities');
assert(userPage.includes('isBusinessRole'), 'business entry must use the role whitelist helper');
assert(userPage.includes('onShow: function') && userPage.includes('this.loadYfthBusinessEntry();'), 'user center onShow path must refresh business identities');
assert(userPage.includes('resetYfthBusinessEntry'), 'user center must reset business entry before async identity loading');
assert(userPage.includes('yfthBusinessIdentityRequestSeq'), 'user center must guard identity requests with a sequence');
assert(userPage.includes('requestUid') && userPage.includes('currentUid'), 'user center must prevent stale identity requests from writing after user switch');
assert(userPage.includes("uni.navigateTo({ url: '/pages/yfth/referral/code' });"), 'customer identity-code entry must open the shared identity/referral code page');
assert(!userPage.includes('if (!this.isYfthPermanentMember) { this.goYfthPackagePurchase(); return; }'), 'ordinary customers must not be redirected from their identity code to package purchase');

const referralCodePage = read('pages/yfth/referral/code.vue');
assert(referralCodePage.includes('v-if="!isMember"') && referralCodePage.includes('我的身份码'), 'ordinary customers must render the identity-code panel');
assert(referralCodePage.includes('return this.issueIdentity();'), 'ordinary customers must issue an identity code instead of a referral invite');
assert(referralCodePage.includes("{{ isMember ? '我的推广码' : '我的身份码' }}"), 'identity-code page heading must follow permanent membership state');
assert(referralCodePage.includes('普通用户身份码不具备推广能力，也不会建立一级推荐关系'), 'ordinary identity code must explicitly reject referral semantics');
assert(referralCodePage.includes("if (!this.isMember) return { title: '御方通和总部商城'"), 'ordinary identity code sharing must not publish a referral invite');
assert(referralCodePage.includes('else uni.hideShareMenu();'), 'ordinary identity code must hide the mini-program referral share action');

const context = read('libs/yfthContext.js');
const cache = read('utils/cache.js');
assert(context.includes("const PARTNER_ROLES = ['county_partner', 'prefecture_partner', 'province_partner', 'regional_director', 'platform_director']"), 'all five partner ranks must be operating roles');
assert(context.includes("['store_manager', 'store_staff', 'service_mentor'].concat(PARTNER_ROLES)"), 'business role whitelist must include store and partner roles');
assert(context.includes('getYfthContext(data)'), 'role/store context must be verified by the backend');
assert(context.includes('uid: Number(context.uid || currentUid() || 0)'), 'cached context must carry the current uid');
assert(context.includes('Number(context.uid) !== Number(uid)'), 'cached context must be rejected after user switch');
assert(context.includes('YFTH_ROLE_PRIORITY'), 'operating identities must define a stable priority order');
assert(!context.includes('franchisee: 400') && context.includes('store_manager: 300') && context.includes('store_staff: 200'), 'removed franchisee role must not remain in live priority order');
assert(context.includes('dominantYfthIdentities'), 'identity selection must calculate the highest active operating role');
assert(context.includes('resolveDominantYfthContext'), 'cached lower roles must be replaced by the highest server identity');
assert(context.includes('PARTNER_ROLES.forEach((role) => { YFTH_ROLE_NAVS[role] = YFTH_ROLE_NAVS.partner_workbench; })'), 'partner roles must inherit the partner workbench navigation');
assert(context.indexOf('export const YFTH_ROLE_NAVS = {') < context.indexOf('PARTNER_ROLES.forEach((role) => { YFTH_ROLE_NAVS[role] = YFTH_ROLE_NAVS.partner_workbench; })'), 'partner navigation inheritance must run after YFTH_ROLE_NAVS initialization');
assert(context.includes('enterYfthBusinessMall') && context.includes('leaveYfthBusinessMall') && context.includes('isYfthBusinessMallBrowsing'), 'business mall browsing must be explicit and session-scoped');
assert(context.includes("const BUSINESS_SURFACE_KEY = 'YFTH_BUSINESS_SURFACE'"), 'business mall and user-center intent must survive a tab chunk reload');
assert(context.includes("Cache.set(BUSINESS_SURFACE_KEY, { uid, action }, BUSINESS_SURFACE_TTL)"), 'business surface intent must be scoped to the current uid and expire');
assert(context.includes("Number(surface.uid) !== uid"), 'business surface intent must be rejected after a user switch');
assert(!context.includes('let businessMallBrowsing = false'), 'business mall intent must not rely on module memory only');
assert(cache.includes('window.localStorage.setItem') && cache.includes('window.localStorage.getItem'), 'H5 auth and operating context must survive tab switches and browser refreshes');
assert(context.includes("title: '分类', url: '/pages/goods_cate/goods_cate'") && context.includes("title: '购物车', url: '/pages/order_addcart/order_addcart'"), 'customer navigation must remain the fixed four-tab contract');

assertNotContains('pages/yfth/workbench/index.vue', "/pages/admin/yfth_writeoff/index", 'user-token workbench must not link to admin writeoff page');
assertNotContains('pages/yfth/workbench/index.vue', "/pages/admin/orderList/index", 'user-token workbench must not link to admin order page');
assertContains('pages/yfth/workbench/index.vue', 'getYfthStoreWorkbenchOverview', 'workbench must use the user-token store adapter overview API');
assertContains('pages/yfth/workbench/index.vue', 'precheckYfthStoreWorkbenchWriteoff', 'workbench writeoff must use the user-token store adapter API');
assertContains('pages/yfth/workbench/index.vue', 'getYfthStoreWorkbenchOrders', 'workbench orders must use the user-token store adapter API');
assertContains('pages/yfth/workbench/index.vue', 'store_staff', 'workbench must keep store staff as a server-validated store role');
assertNotContains('pages/yfth/workbench/index.vue', "from '@/api/yfth_admin.js'", 'formal workbench must not import admin-token APIs');
assertContains('pages/yfth/workbench/index.vue', 'resolveDominantYfthContext', 'workbench must always resolve the highest server identity');
assertContains('pages/yfth/workbench/index.vue', "item.action === 'mall'", 'workbench mall entry must opt into headquarters mall browsing');
assertContains('pages/yfth/workbench/index.vue', 'const cachedContext = currentContext()', 'workbench must keep the cached operating navigation stable during server refresh');
assertContains('pages/yfth/workbench/index.vue', 'v-if="navItems.length" class="nav"', 'workbench must hide navigation instead of flashing customer tabs without an operating context');
assertContains('pages/yfth/workbench/index.vue', 'if (!this.context || !isBusinessRole(this.context.role_code)) return [];', 'workbench navigation must never fall back to customer tabs during route transitions');
assertContains('pages/yfth/workbench/index.vue', "canPurchaseInventory() {\n\t\t\treturn this.context.role_code === 'store_manager';", 'only store managers may see the purchase entry');
assertContains('pages/yfth/workbench/purchase/index.vue', "return this.context.role_code === 'store_manager';", 'direct purchase-page access must require a server-validated store manager');
assertContains('pages/yfth/workbench/purchase/index.vue', 'v-if="accessGranted"', 'purchase content must stay hidden until the manager context is verified');
assertContains('pages/yfth/workbench/purchase/index.vue', 'window.location.replace(target)', 'H5 direct access must have a workbench redirect fallback');
assertNotContains('pages/yfth/workbench/index.vue', 'backCustomer', 'a higher operating identity must not fall back to the customer surface');
assertNotContains('pages/yfth/workbench/role_switch.vue', 'chooseCustomer', 'role selection must not expose customer fallback to a higher identity');
assertContains('pages/yfth/workbench/role_switch.vue', 'dominantYfthIdentities', 'role selection must show only the highest role');
assertContains('pages/yfth/workbench/store_switch.vue', 'dominantYfthIdentities', 'store selection must stay within the highest role');
assertContains('pages/yfth/workbench/index.vue', '/pages/yfth/workbench/customer/index', 'workbench must link to customer relation page');
assertContains('pages/yfth/workbench/index.vue', 'role_code=${role}&store_id=${storeId}', 'customer navigation must carry the last server-verified role and store selection');

assertContains('api/yfth.js', 'yfth/customer/list', 'user API helper must expose customer list');
assertContains('api/yfth.js', 'yfth/customer/relation', 'user API helper must expose customer relation binding');
assertContains('api/yfth.js', "yfth/customer/' + id + '/follow", 'user API helper must expose customer follow record');
assertContains('pages/yfth/workbench/customer/index.vue', 'currentContext', 'customer page must use current user-token context');
assertContains('pages/yfth/workbench/customer/index.vue', 'resolveYfthContext(roleCode, storeId)', 'customer page must revalidate role and store server-side');
assertContains('pages/yfth/workbench/customer/index.vue', 'resolveDominantYfthContext(identities)', 'customer page must recover a missing context from server identities');
assertNotContains('pages/yfth/workbench/customer/index.vue', "uni.reLaunch({ url: '/pages/yfth/workbench/index' })", 'customer context failure must render an explicit error instead of silently bouncing');
assertContains('pages/yfth/workbench/customer/index.vue', 'this.listError = String', 'customer API failures must remain visible instead of looking like an empty list');
assertContains('pages/yfth/workbench/customer/index.vue', 'business-pane="customers"', 'customer list must retain the shared operating footer');
assertContains('pages/yfth/workbench/customer/detail.vue', 'business-pane="customers"', 'customer detail must retain the shared operating footer');
assertContains('pages/yfth/workbench/customer/follow.vue', 'business-pane="customers"', 'customer follow must retain the shared operating footer');
assertContains('components/pageFooter/index.vue', 'businessActivePane', 'shared footer must support active operating panes outside the workbench route');
assertContains('components/pageFooter/index.vue', 'isBusinessNavActive(item)', 'shared footer must use one active-state resolver for actions and panes');
assertContains('pages/yfth/workbench/customer/index.vue', 'phone_masked', 'customer list must render masked phone only');
assertNotContains('pages/yfth/workbench/customer/index.vue', 'phone }}</', 'customer list must not render raw phone');

assertNotContains('api/yfth_admin.js', 'store.state.app.token', 'admin API helper must not fall back to the customer token');
assertContains('api/yfth_admin.js', 'admin_token_required', 'admin API helper must fail closed without admin token');
assertContains('api/yfth.js', 'yfth/store_workbench/overview', 'user API helper must expose store workbench overview');
assertContains('api/yfth.js', 'yfth/store_workbench/writeoff/precheck', 'user API helper must expose store writeoff precheck');
assertContains('api/yfth.js', 'yfth/store_workbench/orders', 'user API helper must expose read-only store orders');

const requestLayer = read('utils/request.js');
assertContains('utils/request.js', 'shouldUseH5DevFallback', 'request layer must use guarded H5 fallback classifier');
assertContains('utils/request.js', 'isHtmlResponse', 'request layer must explicitly reject html responses outside guarded fallback');
assertContains('utils/request.js', 'Number(res.statusCode) !== 200', 'request layer must reject non-200 HTTP responses');
assertNotContains('utils/request.js', 'if (isHtmlFallback(res.data))', 'request layer must not convert arbitrary HTML to success');

const fallbackHelper = read('utils/yfthH5Fallback.js');
assertContains('utils/yfthH5Fallback.js', "nodeEnv === 'development'", 'fallback helper must be development-only');
assertContains('utils/yfthH5Fallback.js', 'isWhitelistedFallbackApi', 'fallback helper must require an explicit whitelist');
assertContains('utils/yfthH5Fallback.js', "host === 'localhost' || host === '127.0.0.1' || host === '::1'", 'fallback helper must require local dev server host');

const pageFooter = read('components/pageFooter/index.vue');
assert(pageFooter.includes('pageLifetimes') && pageFooter.includes('this.refreshBusinessNavigation()'), 'reused tab pages must refresh role navigation whenever they become visible');
assertContains('components/pageFooter/index.vue', 'keepCurrentNavigation', 'footer request failure must preserve valid navigation');
assertContains('components/pageFooter/index.vue', 'this.getNavigationInfo(footerNavigation)', 'footer refresh failure must retain cached navigation');
assertNotContains('components/pageFooter/index.vue', '.catch(() => {\n\t\t\t\t\tthis.setNavigationInfo({});', 'footer catch must not unconditionally clear navigation');

assertContains('pages/index/index.vue', 'homeComb', 'customer home must keep CRMEB decoration components');
assertContains('pages/index/index.vue', 'getDiy', 'customer home must keep CRMEB page-decoration loading');
assertContains('pages/index/index.vue', 'redirectDominantYfthRole', 'customer home must redirect an operating account to its highest workbench');
assertContains('pages/index/index.vue', 'isYfthBusinessMallBrowsing()', 'an explicit mall entry must not bounce straight back to the workbench');
assertContains('pages/index/index.vue', 'this.$nextTick(() => this.redirectDominantYfthRole())', 'cold H5 entry must retry dominant routing after mount');
const homepage = read('pages/index/index.vue');
assert(/if \(newV\) \{[\s\S]{0,120}this\.redirectDominantYfthRole\(\);/.test(homepage), 'restored login state must trigger dominant routing');
assert(userPage.includes('cached.is_business_role && !keepUserCenter'), 'direct customer user-center entry must redirect a higher operating identity');
assert(pageFooter.includes('isYfthBusinessUserCenterBrowsing') && pageFooter.includes("businessActiveAction: ''"), 'intentional business user-center entry must retain the role navigation');

console.log('YFTH multi-role shell contract check passed.');
