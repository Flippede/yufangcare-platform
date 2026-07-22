import Cache from '@/utils/cache';
import { UID } from '@/config/cache';
import { getYfthContext, getYfthIdentities } from '@/api/yfth.js';

export const YFTH_ROLE_LABELS = {
	customer: '顾客',
	member_5980: '5980会员',
	store_manager: '店长',
	store_staff: '店员',
	service_mentor: '服务导师',
	county_partner: '县级招商合伙人',
	prefecture_partner: '地市级招商合伙人',
	province_partner: '省级招商合伙人',
	regional_director: '大区总监',
	platform_director: '平台董事'
};

const PARTNER_ROLES = ['county_partner', 'prefecture_partner', 'province_partner', 'regional_director', 'platform_director'];

export const YFTH_ROLE_NAVS = {
	customer: [
		{ title: '首页', url: '/pages/index/index', type: 'switchTab' },
		{ title: '分类', url: '/pages/goods_cate/goods_cate', type: 'switchTab' },
		{ title: '购物车', url: '/pages/order_addcart/order_addcart', type: 'switchTab' },
		{ title: '我的', url: '/pages/user/index', type: 'switchTab' }
	],
	partner_workbench: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '门店', pane: 'stores' },
		{ title: '客户', pane: 'customers' },
		{ title: '订单', pane: 'orders' },
		{ title: '商城', url: '/pages/index/index', type: 'switchTab', action: 'mall' },
		{ title: '我的', url: '/pages/user/index', type: 'switchTab', action: 'user_center' }
	],
	store_manager: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '客户', pane: 'customers' },
		{ title: '预约', pane: 'appointments' },
		{ title: '核销', pane: 'writeoff' },
		{ title: '商城', url: '/pages/index/index', type: 'switchTab', action: 'mall' },
		{ title: '我的', url: '/pages/user/index', type: 'switchTab', action: 'user_center' }
	],
	store_staff: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '客户', pane: 'customers' },
		{ title: '核销', pane: 'writeoff' },
		{ title: '订单', pane: 'orders' },
		{ title: '商城', url: '/pages/index/index', type: 'switchTab', action: 'mall' },
		{ title: '我的', url: '/pages/user/index', type: 'switchTab', action: 'user_center' }
	],
	service_mentor: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '线索', pane: 'leads' },
		{ title: '活动', pane: 'activities' },
		{ title: '资料', pane: 'materials' },
		{ title: '商城', url: '/pages/index/index', type: 'switchTab', action: 'mall' },
		{ title: '我的', url: '/pages/user/index', type: 'switchTab', action: 'user_center' }
	]
};

PARTNER_ROLES.forEach((role) => { YFTH_ROLE_NAVS[role] = YFTH_ROLE_NAVS.partner_workbench; });

const CONTEXT_KEY = 'YFTH_CURRENT_CONTEXT';
const ROLE_KEY = 'YFTH_CURRENT_ROLE';
const STORE_KEY = 'YFTH_CURRENT_STORE';
const BUSINESS_SURFACE_KEY = 'YFTH_BUSINESS_SURFACE';
const BUSINESS_SURFACE_TTL = 2 * 60 * 60;

export const YFTH_ROLE_PRIORITY = {
	county_partner: 450,
	prefecture_partner: 460,
	province_partner: 470,
	regional_director: 480,
	platform_director: 490,
	store_manager: 300,
	store_staff: 200,
	service_mentor: 100,
	customer: 0
};

export function roleLabel(roleCode) {
	return YFTH_ROLE_LABELS[roleCode] || roleCode || '顾客';
}

export function roleNav(roleCode) {
	return YFTH_ROLE_NAVS[roleCode] || YFTH_ROLE_NAVS.customer;
}

export function isBusinessRole(roleCode) {
	return ['store_manager', 'store_staff', 'service_mentor'].concat(PARTNER_ROLES).indexOf(roleCode) !== -1;
}

export function roleRequiresStore(roleCode) {
	return ['store_manager', 'store_staff'].concat(PARTNER_ROLES).indexOf(roleCode) !== -1;
}

export function currentContext() {
	const context = Cache.get(CONTEXT_KEY, true);
	if (!context || typeof context !== 'object') {
		Cache.clear(CONTEXT_KEY);
		return {};
	}
	const uid = currentUid();
	if (context.uid && uid && Number(context.uid) !== Number(uid)) {
		clearYfthContext();
		return {};
	}
	return context;
}

export function clearYfthContext() {
	Cache.clear(CONTEXT_KEY);
	Cache.clear(ROLE_KEY);
	Cache.clear(STORE_KEY);
	Cache.clear(BUSINESS_SURFACE_KEY);
}

export function loadYfthIdentities() {
	return getYfthIdentities().then((res) => normalizeIdentityRows(res.data || []));
}

export function enterYfthBusinessMall() {
	writeBusinessSurface('mall');
}

export function leaveYfthBusinessMall() {
	clearBusinessSurface('mall');
}

export function isYfthBusinessMallBrowsing() {
	return readBusinessSurface().action === 'mall';
}

export function enterYfthBusinessUserCenter() {
	writeBusinessSurface('user_center');
}

export function leaveYfthBusinessUserCenter() {
	clearBusinessSurface('user_center');
}

export function isYfthBusinessUserCenterBrowsing() {
	return readBusinessSurface().action === 'user_center';
}

export function dominantYfthIdentities(identities) {
	const businessRows = normalizeIdentityRows(identities || [])
		.filter((item) => isBusinessRole(item.role_code));
	if (!businessRows.length) return [];
	const highestPriority = businessRows.reduce((highest, item) => {
		return Math.max(highest, Number(YFTH_ROLE_PRIORITY[item.role_code] || 0));
	}, 0);
	return businessRows.filter((item) => Number(YFTH_ROLE_PRIORITY[item.role_code] || 0) === highestPriority);
}

export function resolveDominantYfthContext(identities) {
	const resolveFromRows = (rows) => {
		const dominantRows = dominantYfthIdentities(rows);
		if (!dominantRows.length) return resolveYfthContext('customer', 0);
		const cached = currentContext();
		const selected = dominantRows.find((item) => {
			return item.role_code === cached.role_code && Number(item.store_id || 0) === Number(cached.store_id || 0);
		}) || dominantRows.slice().sort((left, right) => Number(left.store_id || 0) - Number(right.store_id || 0))[0];
		return resolveYfthContext(selected.role_code, selected.store_id || 0);
	};
	return identities ? resolveFromRows(identities) : loadYfthIdentities().then(resolveFromRows);
}

export function resolveYfthContext(roleCode, storeId) {
	const data = {
		role_code: roleCode || Cache.get(ROLE_KEY) || 'customer'
	};
	if (storeId || Cache.get(STORE_KEY)) {
		data.store_id = storeId || Cache.get(STORE_KEY);
	}
	return getYfthContext(data).then((res) => {
		const context = normalizeContext(res.data || {});
		Cache.set(CONTEXT_KEY, context);
		Cache.set(ROLE_KEY, context.role_code || 'customer');
		if (context.store_id) {
			Cache.set(STORE_KEY, context.store_id);
		} else {
			Cache.clear(STORE_KEY);
		}
		return context;
	});
}

export function switchYfthRole(roleCode, storeId) {
	return resolveYfthContext(roleCode || 'customer', storeId || 0);
}

export function switchYfthStore(storeId) {
	const roleCode = Cache.get(ROLE_KEY) || 'customer';
	return resolveYfthContext(roleCode, storeId);
}

export function normalizeIdentityRows(rows) {
	return (rows || []).map((item) => {
		const roleCode = item.role_code || 'customer';
		return Object.assign({}, item, {
			role_code: roleCode,
			role_name_cn: roleLabel(roleCode),
			requires_store: roleRequiresStore(roleCode),
			is_business_role: isBusinessRole(roleCode),
			store_id: Number(item.store_id || 0),
			identity_key: roleCode + '_' + Number(item.store_id || 0)
		});
	});
}

function normalizeContext(context) {
	const roleCode = context.role_code || 'customer';
	return Object.assign({}, context, {
		uid: Number(context.uid || currentUid() || 0),
		role_code: roleCode,
		role_name_cn: context.role_name_cn || roleLabel(roleCode),
		is_business_role: isBusinessRole(roleCode),
		requires_store: roleRequiresStore(roleCode),
		store_id: Number(context.store_id || 0)
	});
}

function currentUid() {
	return Number(Cache.get(UID) || 0);
}

function readBusinessSurface() {
	if (!Cache.has(BUSINESS_SURFACE_KEY)) return {};
	const surface = Cache.get(BUSINESS_SURFACE_KEY, true);
	const uid = currentUid();
	if (!surface || typeof surface !== 'object' || !uid || Number(surface.uid) !== uid) {
		Cache.clear(BUSINESS_SURFACE_KEY);
		return {};
	}
	return surface;
}

function writeBusinessSurface(action) {
	const uid = currentUid();
	if (!uid || ['mall', 'user_center'].indexOf(action) === -1) {
		Cache.clear(BUSINESS_SURFACE_KEY);
		return;
	}
	// This is navigation state only. Every operating API still revalidates role and store server-side.
	Cache.set(BUSINESS_SURFACE_KEY, { uid, action }, BUSINESS_SURFACE_TTL);
}

function clearBusinessSurface(action) {
	const surface = readBusinessSurface();
	if (!action || surface.action === action) Cache.clear(BUSINESS_SURFACE_KEY);
}
