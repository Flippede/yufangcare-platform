<template>
	<view class="ledger-page">
		<view class="top">
			<view>
				<view class="title">产品额度流水</view>
				<view class="sub">{{ context.store_name || '当前门店' }}</view>
			</view>
			<button class="light" @click="load">刷新</button>
		</view>
		<view class="notice">流水仅用于查看总部确认的产品等价额度变化，不代表系统付款。</view>

		<view v-if="loading" class="empty">加载中...</view>
		<view v-else-if="!list.length" class="empty">暂无额度流水</view>
		<view v-else>
			<view v-for="item in list" :key="item.id" class="card">
				<view class="row-main">
					<view>
						<view class="strong">{{ item.ledger_no }}</view>
						<view class="muted">{{ item.action_type }} / {{ item.direction }}</view>
					</view>
					<view :class="['quota', item.direction === 'out' ? 'out' : '']">{{ signText(item) }}{{ formatQuota(item.amount_cent) }}</view>
				</view>
				<view class="line">变动后：{{ formatQuota(item.balance_after_cent) }}</view>
				<view class="line">来源：{{ item.source_type || '-' }}</view>
				<view class="line">时间：{{ formatTime(item.create_time) }}</view>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthProductQuotaLedger } from '@/api/yfth.js';
import { currentContext, resolveYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return { context: {}, list: [], loading: false };
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
			getYfthProductQuotaLedger(this.contextParams({ page: 1, limit: 50 })).then((res) => {
				this.list = (res.data && res.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		signText(item) {
			return item.direction === 'out' ? '-' : '+';
		},
		formatQuota(value) {
			const cents = Number(value || 0);
			const abs = Math.abs(cents);
			const main = Math.floor(abs / 100);
			const tail = String(abs % 100).padStart(2, '0');
			return main + '.' + tail + ' 额度单位';
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
.ledger-page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.top { background: #5a3f2e; color: #fff; border-radius: 16rpx; padding: 26rpx; display: flex; justify-content: space-between; align-items: center; }
.title { font-size: 38rpx; font-weight: 700; }
.sub { margin-top: 8rpx; color: #f4dfc0; font-size: 24rpx; }
.light { background: #fffaf2; color: #6f4c2f; border-radius: 12rpx; font-size: 24rpx; }
.notice { margin-top: 18rpx; background: #fff8e8; color: #8a5a3c; border: 1rpx solid #ead7a8; padding: 18rpx; border-radius: 12rpx; font-size: 24rpx; }
.card, .empty { background: #fff; border-radius: 16rpx; padding: 24rpx; margin-top: 18rpx; }
.row-main { display: flex; justify-content: space-between; gap: 18rpx; }
.strong { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.muted, .line { color: #786b73; font-size: 24rpx; margin-top: 8rpx; }
.quota { color: #6f4c2f; font-weight: 700; font-size: 28rpx; text-align: right; }
.quota.out { color: #9a4f2f; }
.empty { text-align: center; color: #786b73; }
</style>
