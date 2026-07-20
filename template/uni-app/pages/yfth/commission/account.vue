<template>
	<view class="page">
		<view class="balance-panel">
			<view class="eyebrow">我的推荐佣金</view>
			<view class="balance">¥ {{ account.available || '0.00' }}</view>
			<view class="hint">应结算佣金，由永久归属门店在线下完成结算</view>
		</view>
		<view class="metrics">
			<view><text>{{ account.available || '0.00' }}</text><text>应结算佣金</text></view>
			<view><text>{{ account.frozen || '0.00' }}</text><text>已申请结算</text></view>
			<view><text>{{ account.withdrawn || '0.00' }}</text><text>已完成结算</text></view>
			<view><text>{{ summary.observing || '0.00' }}</text><text>观察期中</text></view>
		</view>
		<view class="panel">
			<view class="panel-title">申请门店结算</view>
			<view class="amount-row"><text>¥</text><input v-model="amount" type="digit" placeholder="输入申请结算金额" /></view>
			<button class="primary" :disabled="submitting" @click="submitSettlement">{{ submitting ? '提交中' : '向归属门店申请结算' }}</button>
			<view class="notice">申请自动提交至永久归属 B1，由本店店长或店员线下完成后在系统记录。总部不参与此流程。</view>
		</view>
		<view class="tabs">
			<view :class="{ active: tab === 'ledger' }" @click="tab = 'ledger'">佣金明细</view>
			<view :class="{ active: tab === 'settlement' }" @click="tab = 'settlement'">结算记录</view>
		</view>
		<view v-if="tab === 'ledger'" class="list">
			<view v-for="item in ledger" :key="item.id" class="row-card">
				<view><text class="row-title">{{ sourceLabel(item.source_type) }}</text><text class="row-sub">{{ timeText(item.add_time) }}</text></view>
				<view :class="['row-amount', item.direction === 'credit' ? 'plus' : 'minus']">{{ item.direction === 'credit' ? '+' : '-' }}{{ item.amount || '0.00' }}</view>
			</view>
			<view v-if="!ledger.length" class="empty">暂无佣金明细</view>
		</view>
		<view v-else class="list">
			<view v-for="item in settlements" :key="item.id" class="row-card">
				<view><text class="row-title">{{ item.settlement_no }}</text><text class="row-sub">{{ timeText(item.add_time) }}</text></view>
				<view class="right"><text class="row-amount">¥ {{ item.amount || '0.00' }}</text><text class="status">{{ settlementLabel(item.status) }}</text></view>
			</view>
			<view v-if="!settlements.length" class="empty">暂无结算记录</view>
		</view>
	</view>
</template>

<script>
import { getYfthCommissionSummary, getYfthCommissionLedger, getYfthCommissionSettlements, requestYfthCommissionSettlement } from '@/api/yfth.js';

export default {
	data() { return { summary: {}, account: {}, ledger: [], settlements: [], tab: 'ledger', amount: '', submitting: false }; },
	onShow() { this.load(); },
	methods: {
		load() {
			return Promise.all([
				getYfthCommissionSummary(), getYfthCommissionLedger({ page: 1, limit: 50 }),
				getYfthCommissionSettlements({ page: 1, limit: 50 })
			]).then(([summary, ledger, settlements]) => {
				this.summary = summary.data || {}; this.account = this.summary.account || {};
				this.ledger = (ledger.data && ledger.data.list) || [];
				this.settlements = (settlements.data && settlements.data.list) || [];
			}).catch((err) => uni.showToast({ title: String((err && err.msg) || err || '账户加载失败'), icon: 'none' }));
		},
		toCents(value) {
			const match = String(value || '').trim().match(/^(\d+)(?:\.(\d{1,2}))?$/);
			return match ? Number(match[1]) * 100 + Number(((match[2] || '') + '00').slice(0, 2)) : 0;
		},
		submitSettlement() {
			const amountCent = this.toCents(this.amount);
			if (!amountCent) return uni.showToast({ title: '请输入正确的结算金额', icon: 'none' });
			if (amountCent > Number(this.account.available_cent || 0)) return uni.showToast({ title: '应结算佣金不足', icon: 'none' });
			this.submitting = true;
			requestYfthCommissionSettlement({ amount_cent: amountCent, request_id: 'c1-settlement-' + Date.now() + '-' + Math.random().toString(16).slice(2) })
				.then(() => { this.amount = ''; this.tab = 'settlement'; uni.showToast({ title: '已提交', icon: 'success' }); return this.load(); })
				.catch((err) => uni.showToast({ title: String((err && err.msg) || err || '提交失败'), icon: 'none' }))
				.finally(() => { this.submitting = false; });
		},
		sourceLabel(type) {
			const labels = { commission_credit: '自动佣金入账', manual_adjustment: '总部台账调整', mall_order_refund: '商城退款冲正', package_invalidated: '套餐奖励冲正', c1_settlement_requested: '申请结算', c1_settlement_paid: '结算完成' };
			return labels[type] || type || '账户变动';
		},
		settlementLabel(status) { return status === 'paid' ? '已完成结算' : '已申请结算'; },
		timeText(value) { return value ? new Date(Number(value) * 1000).toLocaleString() : ''; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 60rpx; background: #f5f2ed; color: #312a22; }
.balance-panel { padding: 32rpx; border-radius: 16rpx; background: #9a7342; color: #fff; }.eyebrow { font-size: 24rpx; color: #f4e7d3; }.balance { margin-top: 18rpx; font-size: 54rpx; font-weight: 700; }.hint { margin-top: 12rpx; color: #f3e6d2; font-size: 22rpx; }
.metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20rpx 0; margin: 20rpx 0; padding: 24rpx 8rpx; border-radius: 16rpx; background: #fff; }.metrics view { display: flex; flex-direction: column; align-items: center; gap: 8rpx; border-right: 1rpx solid #eee5da; }.metrics text:first-child { color: #70502e; font-size: 30rpx; font-weight: 700; }.metrics text:last-child { color: #83786d; font-size: 21rpx; }
.panel { padding: 26rpx; border-radius: 16rpx; background: #fff; }.panel-title { font-size: 29rpx; font-weight: 700; }.amount-row { display: flex; align-items: center; gap: 14rpx; margin-top: 20rpx; padding: 8rpx 18rpx; border: 1rpx solid #e7dac8; border-radius: 12rpx; }.amount-row text { font-size: 34rpx; color: #8a6033; }.amount-row input { flex: 1; height: 72rpx; font-size: 30rpx; }.primary { margin-top: 18rpx; border-radius: 12rpx; background: #8a6234; color: #fff; font-size: 27rpx; }.primary[disabled] { opacity: .55; }.notice { margin-top: 16rpx; color: #8b7a68; font-size: 21rpx; line-height: 1.55; }
.tabs { display: flex; margin-top: 22rpx; padding: 6rpx; border-radius: 12rpx; background: #fff; }.tabs view { flex: 1; padding: 18rpx; text-align: center; color: #84786c; }.tabs .active { border-radius: 10rpx; background: #f3eadc; color: #6d4c2c; font-weight: 700; }.row-card { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; margin-top: 14rpx; padding: 24rpx; border-radius: 14rpx; background: #fff; }.row-card > view:first-child,.right { display: flex; flex-direction: column; gap: 8rpx; }.right { align-items: flex-end; }.row-title { font-size: 26rpx; font-weight: 650; }.row-sub { color: #999087; font-size: 20rpx; }.row-amount { font-size: 28rpx; font-weight: 700; }.plus { color: #54785e; }.minus { color: #a34f45; }.status { color: #8d6a42; font-size: 21rpx; }.empty { padding: 60rpx 0; color: #9a9187; text-align: center; }
</style>
