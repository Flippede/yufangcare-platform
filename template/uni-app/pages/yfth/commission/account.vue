<template>
	<view class="page">
		<view class="points-panel">
			<view class="eyebrow">我的推荐积分</view>
			<view class="points">{{ summary.points || 0 }}</view>
			<view class="hint">1积分可抵扣1元，积分不能提现</view>
		</view>
		<view class="metrics">
			<view><text>{{ summary.reward_points || 0 }}</text><text>累计推荐积分</text></view>
			<view><text>{{ summary.observing_points || 0 }}</text><text>观察期积分</text></view>
		</view>
		<view class="notice">普通商城直推奖励和会员套餐15% / 25% / 60%奖励均统一发放为积分。积分仅用于符合条件的普通商城商品，订单仍需至少支付0.1元。</view>
		<view class="section-title">积分明细</view>
		<view v-for="item in ledger" :key="item.id" class="row-card">
			<view><text class="row-title">{{ sourceLabel(item.source_type) }}</text><text class="row-sub">{{ timeText(item.add_time) }}</text></view>
			<view :class="['row-points', item.direction === 'credit' ? 'plus' : 'minus']">{{ item.direction === 'credit' ? '+' : '-' }}{{ item.points || 0 }}积分</view>
		</view>
		<view v-if="!ledger.length" class="empty">暂无推荐积分明细</view>
	</view>
</template>

<script>
import { getYfthCommissionSummary, getYfthCommissionLedger } from '@/api/yfth.js';

export default {
	data() { return { summary: {}, ledger: [] }; },
	onShow() { this.load(); },
	methods: {
		load() {
			return Promise.all([getYfthCommissionSummary(), getYfthCommissionLedger({ page: 1, limit: 50 })])
				.then(([summary, ledger]) => {
					this.summary = summary.data || {};
					this.ledger = (ledger.data && ledger.data.list) || [];
				}).catch((err) => uni.showToast({ title: String((err && err.msg) || err || '积分加载失败'), icon: 'none' }));
		},
		sourceLabel(type) {
			const labels = {
				automatic_reward: '推荐奖励积分',
				legacy_c1_balance_conversion: '历史奖励转积分',
				mall_order_refund: '商城退款冲正',
				package_invalidated: '套餐奖励冲正'
			};
			return labels[type] || '推荐积分变动';
		},
		timeText(value) { return value ? new Date(Number(value) * 1000).toLocaleString() : ''; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 60rpx; background: #f5f2ed; color: #312a22; }
.points-panel { padding: 32rpx; border-radius: 16rpx; background: #9a7342; color: #fff; }
.eyebrow { font-size: 24rpx; color: #f4e7d3; }
.points { margin-top: 18rpx; font-size: 54rpx; font-weight: 700; }
.hint { margin-top: 12rpx; color: #f3e6d2; font-size: 22rpx; }
.metrics { display: grid; grid-template-columns: repeat(2, 1fr); margin: 20rpx 0; padding: 24rpx 8rpx; border-radius: 16rpx; background: #fff; }
.metrics view { display: flex; flex-direction: column; align-items: center; gap: 8rpx; border-right: 1rpx solid #eee5da; }
.metrics view:last-child { border-right: 0; }
.metrics text:first-child { color: #70502e; font-size: 30rpx; font-weight: 700; }
.metrics text:last-child { color: #83786d; font-size: 21rpx; }
.notice { padding: 24rpx; border-radius: 14rpx; background: #fff8eb; color: #806c55; font-size: 22rpx; line-height: 1.6; }
.section-title { margin: 28rpx 4rpx 10rpx; font-size: 29rpx; font-weight: 700; }
.row-card { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; margin-top: 14rpx; padding: 24rpx; border-radius: 14rpx; background: #fff; }
.row-card > view:first-child { display: flex; flex-direction: column; gap: 8rpx; }
.row-title { font-size: 26rpx; font-weight: 650; }
.row-sub { color: #999087; font-size: 20rpx; }
.row-points { font-size: 27rpx; font-weight: 700; }
.plus { color: #54785e; }.minus { color: #a34f45; }
.empty { padding: 60rpx 0; color: #9a9187; text-align: center; }
</style>
