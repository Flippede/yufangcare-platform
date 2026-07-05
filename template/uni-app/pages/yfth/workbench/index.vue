<template>
	<view class="yfth-shell">
		<view class="header">
			<view>
				<view class="eyebrow">御方通和多身份小程序</view>
				<view class="title">{{ context.role_name_cn || '顾客' }}工作台</view>
				<view class="sub">{{ context.store_name || '顾客端 / 未选择门店' }}</view>
			</view>
			<button class="light" @click="backCustomer">返回顾客端</button>
		</view>

		<view v-if="loading" class="empty">正在读取真实身份上下文...</view>
		<view v-else-if="error" class="empty error">
			<view>{{ error }}</view>
			<button @click="load">重新加载</button>
		</view>
		<block v-else>
			<view class="switch-row">
				<button @click="goRoleSwitch">切换身份</button>
				<button v-if="context.requires_store" @click="goStoreSwitch">切换门店</button>
			</view>

			<view class="notice">
				当前身份和门店由服务端 yfth/context 校验；本地缓存只保存选择结果，不能作为权限依据。
			</view>

			<view v-if="pane === 'dashboard'" class="grid">
				<view v-for="item in dashboardCards" :key="item.title" class="card" @click="goCard(item)">
					<view class="card-title">{{ item.title }}</view>
					<view class="card-desc">{{ item.desc }}</view>
					<view class="card-link">{{ item.linkText }}</view>
				</view>
			</view>

			<view v-else-if="pane === 'stores'" class="panel">
				<view class="panel-title">门店范围</view>
				<view v-if="storeIdentities.length">
					<view v-for="item in storeIdentities" :key="item.role_code + '_' + item.store_id" class="row">
						<view>
							<view>{{ item.store_name || ('门店ID ' + item.store_id) }}</view>
							<text>{{ item.role_name_cn }}</text>
						</view>
						<button @click="switchStore(item.store_id)">进入</button>
					</view>
				</view>
				<view v-else class="empty small">暂无服务端返回的门店范围。</view>
			</view>

			<view v-else class="panel">
				<view class="panel-title">{{ paneTitle }}</view>
				<view class="empty small">{{ paneEmptyText }}</view>
			</view>
		</block>

		<view class="nav">
			<view
				v-for="item in navItems"
				:key="item.title"
				:class="['nav-item', activeNav(item) ? 'active' : '']"
				@click="tapNav(item)"
			>
				<text>{{ item.title }}</text>
			</view>
		</view>
	</view>
</template>

<script>
import {
	clearYfthContext,
	currentContext,
	isBusinessRole,
	loadYfthIdentities,
	resolveYfthContext,
	roleNav,
	switchYfthStore
} from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			loading: true,
			error: '',
			pane: 'dashboard',
			context: {},
			identities: []
		};
	},
	computed: {
		navItems() {
			return roleNav(this.context.role_code || 'customer');
		},
		storeIdentities() {
			const role = this.context.role_code;
			return this.identities.filter((item) => item.role_code === role && item.store_id);
		},
		paneTitle() {
			const titles = {
				customers: '客户入口',
				appointments: '预约管理',
				writeoff: '核销入口',
				orders: '门店订单',
				mine: '我的经营身份',
				leads: '线索',
				activities: '活动',
				materials: '资料'
			};
			return titles[this.pane] || '建设中';
		},
		paneEmptyText() {
			if (['leads', 'activities', 'materials'].indexOf(this.pane) !== -1) {
				return '导师业务域尚未开放，当前仅保留正式导航外壳。';
			}
			if (['appointments', 'writeoff', 'orders'].indexOf(this.pane) !== -1) {
				return '该入口需要正式后台或门店端认证适配。本轮先关闭错误跳转，不使用普通用户 token 访问后台接口。';
			}
			return '当前入口已预留，后续接入真实业务列表；本页不展示假数据。';
		},
		dashboardCards() {
			const role = this.context.role_code;
			const common = [
				{ title: '今日预约', desc: '门店预约列表需接入经营端认证后开放', pane: 'appointments', linkText: '认证适配中', disabled: true },
				{ title: '核销入口', desc: '扫码/数字码核销继续使用后台 token 边界', pane: 'writeoff', linkText: '认证适配中', disabled: true },
				{ title: '门店订单', desc: 'CRMEB 门店订单需正式后台权限，不在用户态壳层直连', pane: 'orders', linkText: '认证适配中', disabled: true }
			];
			if (role === 'franchisee') {
				return common.concat([
					{ title: '名下门店', desc: '按服务端返回门店范围切换', pane: 'stores', linkText: '切换门店' },
					{ title: '采购/奖励/合同', desc: '相关业务尚未开放，不提供假提交', pane: 'mine', linkText: '建设中' }
				]);
			}
			if (role === 'store_manager') {
				return common.concat([
					{ title: '客户与套餐客户', desc: '后续接入真实客户归属和套餐权益', pane: 'customers', linkText: '查看入口' },
					{ title: '切换授权门店', desc: '只允许服务端授权门店', pane: 'stores', linkText: '切换门店' }
				]);
			}
			if (role === 'store_staff') {
				return [
					{ title: '当前门店预约', desc: '门店预约列表需经营端认证适配后开放', pane: 'appointments', linkText: '认证适配中', disabled: true },
					{ title: '核销入口', desc: '核销保持后台 token 边界，不用用户 token 直连', pane: 'writeoff', linkText: '认证适配中', disabled: true },
					{ title: '门店订单', desc: '不展示多门店经营和财务数据', pane: 'orders', linkText: '认证适配中', disabled: true },
					{ title: '我的操作记录', desc: '操作记录入口预留，后续接真实数据', pane: 'mine', linkText: '查看入口' }
				];
			}
			if (role === 'service_mentor') {
				return [
					{ title: '线索', desc: '导师线索业务建设中', pane: 'leads', linkText: '查看外壳' },
					{ title: '活动', desc: '活动计划和签到建设中', pane: 'activities', linkText: '查看外壳' },
					{ title: '资料', desc: '培训资料和常见问题建设中', pane: 'materials', linkText: '查看外壳' }
				];
			}
			return [
				{ title: '顾客首页', desc: '继续使用 CRMEB 页面装修承载', url: '/pages/index/index', type: 'switchTab', linkText: '返回首页' }
			];
		}
	},
	onLoad(options) {
		this.pane = options.pane || 'dashboard';
	},
	onShow() {
		this.load();
	},
	methods: {
		load() {
			this.loading = true;
			this.error = '';
			const cached = currentContext();
			const role = cached.role_code || 'customer';
			const store = cached.store_id || 0;
			Promise.all([loadYfthIdentities(), resolveYfthContext(role, store)])
				.then(([identities, context]) => {
					if (!context.is_business_role || !isBusinessRole(context.role_code)) {
						clearYfthContext();
						uni.reLaunch({ url: '/pages/index/index' });
						return;
					}
					this.identities = identities;
					this.context = context;
				})
				.catch((err) => {
					clearYfthContext();
					this.error = String((err && err.msg) || err || '身份上下文读取失败');
					uni.showToast({ title: this.error, icon: 'none' });
					setTimeout(() => {
						uni.reLaunch({ url: '/pages/index/index' });
					}, 800);
				})
				.finally(() => {
					this.loading = false;
				});
		},
		goRoleSwitch() {
			uni.navigateTo({ url: '/pages/yfth/workbench/role_switch' });
		},
		goStoreSwitch() {
			uni.navigateTo({ url: '/pages/yfth/workbench/store_switch' });
		},
		backCustomer() {
			clearYfthContext();
			uni.reLaunch({ url: '/pages/index/index' });
		},
		activeNav(item) {
			return (item.pane || 'dashboard') === this.pane && !item.url;
		},
		tapNav(item) {
			if (item.url) {
				const fn = item.type === 'switchTab' ? uni.switchTab : uni.navigateTo;
				fn({ url: item.url });
				return;
			}
			this.pane = item.pane || 'dashboard';
		},
		goCard(item) {
			if (item.disabled) {
				uni.showToast({ title: item.linkText || '认证适配中', icon: 'none' });
				if (item.pane) {
					this.pane = item.pane;
				}
				return;
			}
			if (item.url) {
				uni.navigateTo({ url: item.url });
				return;
			}
			this.pane = item.pane || 'dashboard';
		},
		switchStore(storeId) {
			switchYfthStore(storeId).then(() => {
				uni.showToast({ title: '门店已切换', icon: 'success' });
				this.load();
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		}
	}
};
</script>

<style scoped>
.yfth-shell { min-height: 100vh; background: #f6f0e6; padding: 24rpx 24rpx 130rpx; }
.header { border-radius: 18rpx; background: linear-gradient(135deg, #4b315f, #8a5a3c); color: #fff; padding: 28rpx; display: flex; justify-content: space-between; gap: 18rpx; }
.eyebrow { color: #f2dfb5; font-size: 22rpx; }
.title { font-size: 38rpx; font-weight: 700; margin-top: 8rpx; }
.sub { margin-top: 8rpx; color: #f7e8d0; font-size: 24rpx; }
button { font-size: 26rpx; }
.light { background: #fffaf2; color: #6d4b31; border-radius: 12rpx; height: 64rpx; line-height: 64rpx; padding: 0 20rpx; }
.switch-row { display: flex; gap: 18rpx; margin: 22rpx 0; }
.switch-row button { flex: 1; background: #fff; color: #4b315f; border-radius: 12rpx; }
.notice { background: #fff8e8; color: #8a5a3c; border: 1rpx solid #ead7a8; padding: 18rpx; border-radius: 12rpx; font-size: 24rpx; margin-bottom: 20rpx; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; }
.card, .panel { background: #fff; border-radius: 16rpx; padding: 24rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.card-title, .panel-title { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.card-desc { color: #786b73; font-size: 24rpx; min-height: 72rpx; margin-top: 12rpx; }
.card-link { color: #8a5a3c; font-weight: 700; margin-top: 16rpx; font-size: 24rpx; }
.row { display: flex; justify-content: space-between; align-items: center; padding: 20rpx 0; border-bottom: 1rpx solid #f0e5d3; }
.row text { color: #8a7a68; font-size: 24rpx; }
.row button { background: #4b315f; color: #fff; border-radius: 10rpx; margin: 0; }
.empty { margin-top: 80rpx; text-align: center; color: #786b73; background: #fff; border-radius: 16rpx; padding: 34rpx; }
.empty.small { margin-top: 20rpx; box-shadow: none; background: #fffaf2; }
.error { color: #a74e4e; }
.nav { position: fixed; left: 0; right: 0; bottom: 0; height: 106rpx; background: #fffaf4; border-top: 1rpx solid #eadfce; display: flex; z-index: 30; }
.nav-item { flex: 1; display: flex; align-items: center; justify-content: center; color: #786b73; font-size: 24rpx; }
.nav-item.active { color: #4b315f; font-weight: 700; }
</style>
