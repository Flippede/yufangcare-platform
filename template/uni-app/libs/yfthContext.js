import Cache from '@/utils/cache';
import { UID } from '@/config/cache';
import { getYfthContext, getYfthIdentities } from '@/api/yfth.js';

export const YFTH_ROLE_LABELS = {
	customer: '顾客',
	member_5980: '5980会员',
	franchisee: '加盟商',
	store_manager: '店长',
	store_staff: '店员',
	service_mentor: '服务导师'
};

export const YFTH_ROLE_NAVS = {
	customer: [
		{ title: '首页', url: '/pages/index/index', type: 'switchTab' },
		{ title: '康养', url: '/pages/yfth/appointment/create' },
		{ title: '商城', url: '/pages/goods/goods_list/index' },
		{ title: '合作中心', url: '/pages/annex/settled/index' },
		{ title: '我的', url: '/pages/user/index', type: 'switchTab' }
	],
	franchisee: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '门店', pane: 'stores' },
		{ title: '客户', pane: 'customers' },
		{ title: '订单', pane: 'orders' },
		{ title: '我的', pane: 'mine' }
	],
	store_manager: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '客户', pane: 'customers' },
		{ title: '预约', pane: 'appointments' },
		{ title: '核销', pane: 'writeoff' },
		{ title: '我的', pane: 'mine' }
	],
	store_staff: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '客户', pane: 'customers' },
		{ title: '核销', pane: 'writeoff' },
		{ title: '订单', pane: 'orders' },
		{ title: '我的', pane: 'mine' }
	],
	service_mentor: [
		{ title: '工作台', pane: 'dashboard' },
		{ title: '线索', pane: 'leads' },
		{ title: '活动', pane: 'activities' },
		{ title: '资料', pane: 'materials' },
		{ title: '我的', pane: 'mine' }
	]
};

const CONTEXT_KEY = 'YFTH_CURRENT_CONTEXT';
const ROLE_KEY = 'YFTH_CURRENT_ROLE';
const STORE_KEY = 'YFTH_CURRENT_STORE';

export function roleLabel(roleCode) {
	return YFTH_ROLE_LABELS[roleCode] || roleCode || '顾客';
}

export function roleNav(roleCode) {
	return YFTH_ROLE_NAVS[roleCode] || YFTH_ROLE_NAVS.customer;
}

export function isBusinessRole(roleCode) {
	return ['franchisee', 'store_manager', 'store_staff', 'service_mentor'].indexOf(roleCode) !== -1;
}

export function roleRequiresStore(roleCode) {
	return ['franchisee', 'store_manager', 'store_staff'].indexOf(roleCode) !== -1;
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
}

export function loadYfthIdentities() {
	return getYfthIdentities().then((res) => normalizeIdentityRows(res.data || []));
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
		role_name_cn: roleLabel(roleCode),
		is_business_role: isBusinessRole(roleCode),
		requires_store: roleRequiresStore(roleCode),
		store_id: Number(context.store_id || 0)
	});
}

function currentUid() {
	return Number(Cache.get(UID) || 0);
}
