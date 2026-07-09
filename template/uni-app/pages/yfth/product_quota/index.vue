<template>
	<view class="quota-page">
		<view class="hero">
			<view>
				<view class="eyebrow">产品额度 / 返货额度</view>
				<view class="title">{{ formatQuota(summary.available_cent) }}</view>
				<view class="sub">{{ context.store_name || '当前门店' }}</view>
			</view>
			<button class="light" @click="goLedger">流水</button>
		</view>

		<view class="notice">
			仅总部线下确认，不代表系统付款；不可提取为资金，不自动抵扣采购单。
		</view>

		<view v-if="loading" class="empty">加载中...</view>
		<block v-else>
			<view class="summary">
				<view class="metric">
					<view class="metric-value">{{ formatQuota(summary.total_granted_cent) }}</view>
					<view class="metric-label">累计授予</view>
				</view>
				<view class="metric">
					<view class="metric-value">{{ formatQuota(summary.total_reversed_cent) }}</view>
					<view class="metric-label">已反冲</view>
				</view>
			</view>

			<view class="section-head">
				<view class="section-title">额度账户</view>
				<button class="mini" @click="load">刷新</button>
			</view>
			<view v-if="!accounts.length" class="empty">暂无产品额度账户</view>
			<view v-for="item in accounts" :key="item.id" class="card" @click="goDetail(item.id)">
				<view class="row-main">
					<view>
						<view class="strong">{{ item.account_no }}</view>
						<view class="muted">{{ item.quota_type }} / {{ item.status }}</view>
					</view>
					<view class="quota">{{ formatQuota(item.available_cent) }}</view>
				</view>
			</view>
		</block>
	</view>
</template>

<script>
import { getYfthProductQuotaAccounts, getYfthProductQuotaSummary } from '@/api/yfth.js';
import { currentContext, resolveYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			context: {},
			summary: {},
			accounts: [],
			loading: false
		};
	},
	onShow() {
		const cached = currentContext();
		resolveYfthContext(cached.role_code || 'customer', cached.store_id || 0).then((context) => {
			this.context = context;
			this.load();
		}).catch((err) => {
			uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			uni.navigateBack();
		});
	},
	methods: {
		contextParams(extra) {
			return Object.assign({ role_code: this.context.role_code, store_id: this.context.store_id }, extra || {});
		},
		load() {
			this.loading = true;
			Promise.all([
				getYfthProductQuotaSummary(this.contextParams()),
				getYfthProductQuotaAccounts(this.contextParams({ page: 1, limit: 20 }))
			]).then(([summaryRes, accountRes]) => {
				this.summary = summaryRes.data || {};
				this.accounts = (accountRes.data && accountRes.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		goLedger() {
			uni.navigateTo({ url: '/pages/yfth/product_quota/ledger' });
		},
		goDetail(id) {
			uni.navigateTo({ url: '/pages/yfth/product_quota/detail?id=' + id });
		},
		formatQuota(value) {
			const cents = Number(value || 0);
			const sign = cents < 0 ? '-' : '';
			const abs = Math.abs(cents);
			const main = Math.floor(abs / 100);
			const tail = String(abs % 100).padStart(2, '0');
			return sign + main + '.' + tail + ' 额度单位';
		}
	}
};
</script>

<style scoped>
.quota-page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.hero { background: linear-gradient(135deg, #5a3f2e, #a7763b); color: #fff; border-radius: 18rpx; padding: 28rpx; display: flex; justify-content: space-between; align-items: center; }
.eyebrow { color: #f3dfba; font-size: 23rpx; }
.title { font-size: 44rpx; font-weight: 700; margin-top: 10rpx; }
.sub { margin-top: 8rpx; color: #f7e8d0; font-size: 24rpx; }
.light { background: #fffaf2; color: #6f4c2f; border-radius: 12rpx; font-size: 24rpx; }
.notice { margin: 20rpx 0; background: #fff8e8; color: #8a5a3c; border: 1rpx solid #ead7a8; padding: 18rpx; border-radius: 12rpx; font-size: 24rpx; }
.summary { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; }
.metric, .card, .empty { background: #fff; border-radius: 16rpx; padding: 24rpx; margin-top: 18rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.metric-value { color: #6f4c2f; font-size: 32rpx; font-weight: 700; }
.metric-label, .muted { color: #786b73; font-size: 24rpx; margin-top: 8rpx; }
.section-head, .row-main { display: flex; justify-content: space-between; align-items: center; gap: 18rpx; }
.section-head { margin-top: 24rpx; }
.section-title, .strong { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.mini { background: #fff7e9; color: #6f4c2f; border-radius: 10rpx; font-size: 24rpx; }
.quota { color: #8f4d2c; font-weight: 700; font-size: 28rpx; text-align: right; }
.empty { text-align: center; color: #786b73; }
</style>
