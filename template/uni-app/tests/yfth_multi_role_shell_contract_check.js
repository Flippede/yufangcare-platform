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
assert(pagesJson.includes('"root": "pages/yfth"'), 'YFTH subpackage must be registered');

const userPage = read('pages/user/index.vue');
assert(userPage.includes('hasYfthBusinessIdentity'), 'user center entry must be gated by business identity');
assert(userPage.includes('loadYfthIdentities'), 'business entry must read server-side identities');
assert(userPage.includes('isBusinessRole'), 'business entry must use the role whitelist helper');

const context = read('libs/yfthContext.js');
assert(context.includes("['franchisee', 'store_manager', 'store_staff', 'service_mentor']"), 'business role whitelist changed unexpectedly');
assert(context.includes('getYfthContext(data)'), 'role/store context must be verified by the backend');
assert(context.includes('uid: Number(context.uid || currentUid() || 0)'), 'cached context must carry the current uid');
assert(context.includes('Number(context.uid) !== Number(uid)'), 'cached context must be rejected after user switch');

assertNotContains('pages/yfth/workbench/index.vue', "/pages/admin/yfth_writeoff/index", 'user-token workbench must not link to admin writeoff page');
assertNotContains('pages/yfth/workbench/index.vue', "/pages/admin/orderList/index", 'user-token workbench must not link to admin order page');
assertContains('pages/yfth/workbench/index.vue', '认证适配中', 'closed backend entries must explain the auth adapter status');
assertContains('pages/yfth/workbench/index.vue', 'clearYfthContext', 'returning to customer side must clear business context');

assertNotContains('api/yfth_admin.js', 'store.state.app.token', 'admin API helper must not fall back to the customer token');
assertContains('api/yfth_admin.js', 'admin_token_required', 'admin API helper must fail closed without admin token');

assertContains('pages/index/index.vue', 'homeComb', 'customer home must keep CRMEB decoration components');
assertContains('pages/index/index.vue', 'getDiy', 'customer home must keep CRMEB page-decoration loading');
assertNotContains('pages/index/index.vue', 'yfthContext', 'customer home must not become a business workbench');

console.log('YFTH multi-role shell contract check passed.');
