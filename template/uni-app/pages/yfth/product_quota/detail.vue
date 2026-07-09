<template>
	<view class="detail-page">
		<view v-if="loading" class="empty">加载中...</view>
		<block v-else>
			<view class="card hero">
				<view class="eyebrow">额度账户</view>
				<view class="title">{{ account.account_no || '-' }}</view>
				<view class="status">{{ account.status || '-' }}</view>
				<view class="quota">{{ formatQuota(account.available_cent) }}</view>
			</view>

			<view class="notice">仅总部线下确认，不代表系统付款；不可自动抵扣采购单。</view>

			<view class="card">
				<view class="section-title">账户概览</view>
				<view class="line">类型：{{ account.quota_type || '-' }}</view>
				<view class="line">累计授予：{{ formatQuota(account.total_granted_cent) }}</view>
				<view class="line">累计反冲：{{ formatQuota(account.total_reversed_cent) }}</view>
				<view class="line">更新时间：{{ formatTime(account.update_time) }}</view>
			</view>

			<view class="card">
				<view class="section-title">最近流水</view>
				<view v-if="!recentLedgers.length" class="inline-empty">暂无流水</view>
				<view v-for="item in recentLedgers" :key="item.id" class="compact-row">
					<view>
						<view class="strong">{{ item.action_type }}</view>
						<view class="muted">{{ formatTime(item.create_time) }}</view>
					</view>
					<view class="amount">{{ item.direction === 'out' ? '-' : '+' }}{{ formatQuota(item.amount_cent) }}</view>
				</view>
			</view>

			<view class="card">
				<view class="section-title">最近授予单</view>
				<view v-if="!recentGrants.length" class="inline-empty">暂无授予记录</view>
				<view v-for="item in recentGrants" :key="item.id" class="compact-row">
					<view>
						<view class="strong">{{ item.grant_no }}</view>
						<view class="muted">{{ item.status }} / {{ formatTime(item.create_time) }}</view>
					</view>
					<view class="amount">{{ formatQuota(item.amount_cent) }}</view>
				</view>
			</view>
		</block>
	</view>
</template>

<script>
import { getYfthProductQuotaAccountDetail } from '@/api/yfth.js';
import { currentContext, resolveYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			id: 0,
			context: {},
			account: {},
			recentLedgers: [],
			recentGrants: [],
			loading: false
		};
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
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
		load() {
			if (!this.id) {
				uni.showToast({ title: '额度账户不存在', icon: 'none' });
				return;
			}
			this.loading = true;
			getYfthProductQuotaAccountDetail(this.id, {
				role_code: this.context.role_code,
				store_id: this.context.store_id
			}).then((res) => {
				const data = res.data || {};
				this.account = data.account || {};
				this.recentLedgers = data.recent_ledgers || [];
				this.recentGrants = data.recent_grants || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		formatQuota(value) {
			const cents = Number(value || 0);
			const sign = cents < 0 ? '-' : '';
			const abs = Math.abs(cents);
			const main = Math.floor(abs / 100);
			const tail = String(abs % 100).padStart(2, '0');
			return sign + main + '.' + tail + ' 额度单位';
		},
		formatTime(value) {
			const ts = Number(value || 0);
			if (!ts) return '-';
			const date = new Date(ts * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
		}
	}
};
</script>

<style scoped>
.detail-page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.card, .empty { background: #fff; border-radius: 16rpx; padding: 24rpx; margin-top: 18rpx; }
.hero { background: linear-gradient(135deg, #5a3f2e, #a7763b); color: #fff; }
.eyebrow { color: #f3dfba; font-size: 23rpx; }
.title { font-size: 34rpx; font-weight: 700; margin-top: 10rpx; word-break: break-all; }
.status { margin-top: 8rpx; color: #f7e8d0; font-size: 24rpx; }
.quota { margin-top: 18rpx; font-size: 44rpx; font-weight: 700; }
.notice { margin-top: 18rpx; background: #fff8e8; color: #8a5a3c; border: 1rpx solid #ead7a8; padding: 18rpx; border-radius: 12rpx; font-size: 24rpx; }
.section-title, .strong { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.line, .muted { color: #786b73; font-size: 24rpx; margin-top: 10rpx; }
.compact-row { display: flex; justify-content: space-between; gap: 18rpx; padding: 18rpx 0; border-top: 1rpx solid #f1eadf; }
.compact-row:first-of-type { border-top: none; }
.amount { color: #8f4d2c; font-weight: 700; text-align: right; }
.inline-empty, .empty { text-align: center; color: #786b73; }
</style>
