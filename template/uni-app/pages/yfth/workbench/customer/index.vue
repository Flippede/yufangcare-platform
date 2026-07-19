<template>
	<view class="page">
		<view class="header">
			<view>
				<view class="eyebrow">御方通和门店经营</view>
				<view class="title">客户管理</view>
				<view class="sub">{{ context.store_name || '当前门店' }}</view>
			</view>
			<button class="light" @click="goWorkbench">工作台</button>
		</view>

		<view v-if="contextReady" class="search-panel">
			<input v-model="where.keyword" placeholder="按客户关系ID查询" @confirm="load(true)" />
			<picker mode="selector" :range="statusOptions" range-key="label" @change="changeStatus">
				<view class="picker">{{ currentStatusLabel }}</view>
			</picker>
			<button @click="load(true)">查询</button>
		</view>

		<view v-if="contextReady" class="bind-panel">
			<view class="panel-title">客户归属绑定</view>
			<view class="bind-row">
				<picker mode="selector" :range="sourceOptions" range-key="label" @change="changeSource">
					<view class="picker">{{ currentSourceLabel }}</view>
				</picker>
				<input v-model="bindForm.reference_id" type="number" placeholder="输入来源记录ID" />
				<button @click="bindCustomer">绑定</button>
			</view>
			<view class="hint">仅允许从本店真实订单、预约或核销记录建立归属；不能直接输入客户 UID。</view>
		</view>

		<view v-if="contextLoading" class="empty">正在校验当前经营身份和门店...</view>
		<view v-else-if="contextError" class="empty error-state">
			<view>{{ contextError }}</view>
			<button @click="ensureContext">重新校验</button>
		</view>
		<view v-else-if="loading" class="empty">正在加载客户...</view>
		<view v-else-if="listError" class="empty error-state">
			<view>{{ listError }}</view>
			<button @click="load(true)">重新加载</button>
		</view>
		<view v-else-if="!list.length" class="empty">当前门店暂无客户关系。</view>
		<view v-else>
			<view v-for="item in list" :key="item.id" class="customer-card" @click="goDetail(item)">
				<view class="card-top">
					<view>
						<view class="name">{{ item.nickname || ('客户关系 #' + item.id) }}</view>
						<view class="muted">{{ item.phone_masked || '未留手机号' }} · {{ item.source_text }}</view>
					</view>
					<view class="status">{{ item.customer_status_text }}</view>
				</view>
				<view class="badges">
					<view :class="['badge', item.has_5980_package ? 'on' : '']">5980套餐</view>
					<view :class="['badge', item.has_appointment ? 'on' : '']">预约记录</view>
					<view class="badge">最近跟进 {{ formatTime(item.latest_follow_time) }}</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import { createYfthCustomerRelation, getYfthCustomerList } from '@/api/yfth.js';
import { currentContext, isBusinessRole, loadYfthIdentities, resolveDominantYfthContext, resolveYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			loading: false,
			listError: '',
			contextLoading: true,
			contextError: '',
			context: {},
			requestedContext: { role_code: '', store_id: 0 },
			where: { keyword: '', customer_status: '', page: 1, limit: 20 },
			list: [],
			bindForm: { source: 'order', reference_id: '' },
			sourceOptions: [
				{ label: '订单', value: 'order' },
				{ label: '预约', value: 'appointment' },
				{ label: '核销', value: 'writeoff' }
			],
			statusOptions: [
				{ label: '全部状态', value: '' },
				{ label: '潜在客户', value: 'potential' },
				{ label: '线索客户', value: 'leads' },
				{ label: '已注册', value: 'registered' },
				{ label: '已购买', value: 'purchased' },
				{ label: '服务中', value: 'serving' },
				{ label: '复购客户', value: 'repeat' },
				{ label: '流失客户', value: 'lost' }
			]
		};
	},
	computed: {
		contextReady() {
			return Boolean(this.context && isBusinessRole(this.context.role_code) && Number(this.context.store_id) > 0);
		},
		currentStatusLabel() {
			const found = this.statusOptions.find((item) => item.value === this.where.customer_status);
			return found ? found.label : '全部状态';
		},
		currentSourceLabel() {
			const found = this.sourceOptions.find((item) => item.value === this.bindForm.source);
			return found ? found.label : '订单';
		}
	},
	onLoad(options) {
		options = options || {};
		this.requestedContext = {
			role_code: String(options.role_code || ''),
			store_id: Number(options.store_id || 0)
		};
	},
	onShow() {
		this.ensureContext();
	},
	methods: {
		ensureContext() {
			const cached = currentContext();
			const roleCode = this.requestedContext.role_code || cached.role_code || '';
			const storeId = Number(this.requestedContext.store_id || cached.store_id || 0);
			this.contextLoading = true;
			this.contextError = '';
			const resolver = roleCode && storeId
				? resolveYfthContext(roleCode, storeId)
				: loadYfthIdentities().then((identities) => resolveDominantYfthContext(identities));
			return resolver.then((context) => {
				if (!context || !isBusinessRole(context.role_code) || Number(context.store_id || 0) < 1) {
					throw new Error('当前账号没有可用的门店客户权限');
				}
				this.context = context;
				this.requestedContext = {
					role_code: context.role_code,
					store_id: Number(context.store_id)
				};
				return this.load(true);
			}).catch((err) => {
				this.context = {};
				this.contextError = String((err && err.msg) || (err && err.message) || err || '经营身份校验失败');
			}).finally(() => {
				this.contextLoading = false;
			});
		},
		contextParams(extra) {
			return Object.assign({
				role_code: this.context.role_code,
				store_id: this.context.store_id
			}, extra || {});
		},
		load(reset) {
			if (reset) {
				this.where.page = 1;
			}
			this.loading = true;
			this.listError = '';
			getYfthCustomerList(this.contextParams(this.where)).then((res) => {
				this.list = (res.data && res.data.list) || [];
			}).catch((err) => {
				this.list = [];
				this.listError = String((err && err.msg) || (err && err.message) || err || '客户列表加载失败');
				uni.showToast({ title: this.listError, icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		changeStatus(event) {
			const index = Number(event.detail.value || 0);
			this.where.customer_status = this.statusOptions[index].value;
			this.load(true);
		},
		changeSource(event) {
			const index = Number(event.detail.value || 0);
			this.bindForm.source = this.sourceOptions[index].value;
		},
		bindCustomer() {
			const referenceId = Number(this.bindForm.reference_id || 0);
			if (!referenceId) {
				uni.showToast({ title: '请输入来源记录ID', icon: 'none' });
				return;
			}
			createYfthCustomerRelation(this.contextParams({
				source: this.bindForm.source,
				reference_id: referenceId,
				customer_status: 'potential'
			})).then(() => {
				this.bindForm.reference_id = '';
				uni.showToast({ title: '绑定成功', icon: 'success' });
				this.load(true);
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		goDetail(item) {
			uni.navigateTo({ url: '/pages/yfth/workbench/customer/detail?id=' + item.id });
		},
		goWorkbench() {
			uni.navigateBack();
		},
		formatTime(value) {
			const ts = Number(value || 0);
			if (!ts) return '-';
			const date = new Date(ts * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.header { border-radius: 18rpx; background: linear-gradient(135deg, #4f3424, #a5763b); color: #fff; padding: 28rpx; display: flex; justify-content: space-between; gap: 18rpx; }
.eyebrow { color: #f2dfb5; font-size: 22rpx; }
.title { font-size: 38rpx; font-weight: 700; margin-top: 8rpx; }
.sub { margin-top: 8rpx; color: #f7e8d0; font-size: 24rpx; }
.light { background: #fffaf2; color: #6d4b31; border-radius: 12rpx; height: 64rpx; line-height: 64rpx; padding: 0 20rpx; font-size: 26rpx; }
.search-panel, .bind-panel, .customer-card, .empty { margin-top: 20rpx; background: #fff; border-radius: 16rpx; padding: 24rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.search-panel, .bind-row { display: flex; gap: 14rpx; align-items: center; }
input { background: #fffaf2; border-radius: 10rpx; padding: 0 20rpx; height: 64rpx; line-height: 64rpx; font-size: 26rpx; flex: 1; }
button { background: #6f4c2f; color: #fff; border-radius: 10rpx; height: 64rpx; line-height: 64rpx; font-size: 26rpx; padding: 0 20rpx; margin: 0; }
.picker { background: #fff7e9; color: #6f4c2f; border-radius: 10rpx; padding: 16rpx 18rpx; font-size: 24rpx; }
.panel-title, .name { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.hint, .muted { color: #786b73; font-size: 24rpx; margin-top: 8rpx; }
.card-top { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; }
.status { color: #6f4c2f; font-weight: 700; font-size: 24rpx; }
.badges { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 18rpx; }
.badge { background: #f5efe4; color: #8a725c; border-radius: 999rpx; padding: 10rpx 18rpx; font-size: 22rpx; }
.badge.on { background: #e8d6ad; color: #6f4c2f; font-weight: 700; }
.empty { text-align: center; color: #786b73; }
.error-state button { margin: 20rpx auto 0; }
</style>
