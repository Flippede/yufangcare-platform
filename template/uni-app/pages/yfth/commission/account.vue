<template>
	<view class="page">
		<view class="balance-panel">
			<view class="eyebrow">御方通和账户余额</view>
			<view class="balance">¥ {{ totalBalance }}</view>
			<view class="hint">统一展示账户资产，支付和提现用途仍按来源隔离</view>
		</view>
		<view class="metrics">
			<view><text>{{ mallBalance }}</text><text>商城可用</text></view>
			<view><text>{{ account.available || '0.00' }}</text><text>佣金可提现</text></view>
			<view><text>{{ summary.observing || '0.00' }}</text><text>观察期中</text></view>
			<view><text>{{ account.frozen || '0.00' }}</text><text>提现处理中</text></view>
			<view><text>{{ account.withdrawn || '0.00' }}</text><text>累计已提现</text></view>
		</view>
		<view class="panel withdraw-panel">
			<view class="panel-title">申请提现</view>
			<view class="amount-row"><text>¥</text><input v-model="amount" type="digit" placeholder="输入提现金额" /></view>
			<button class="primary" :disabled="submitting" @click="submitWithdrawal">{{ submitting ? '提交中' : '向归属门店申请提现' }}</button>
			<view class="notice">申请将自动绑定永久归属门店，由本店店长或店员在线下付款后标记完成。总部不参与此流程。</view>
		</view>
		<view class="tabs">
			<view :class="{ active: tab === 'ledger' }" @click="tab = 'ledger'">佣金明细</view>
			<view :class="{ active: tab === 'withdrawal' }" @click="tab = 'withdrawal'">提现记录</view>
		</view>
		<view v-if="tab === 'ledger'" class="list">
			<view v-for="item in ledger" :key="item.id" class="row-card">
				<view><text class="row-title">{{ sourceLabel(item.source_type) }}</text><text class="row-sub">{{ timeText(item.add_time) }}</text></view>
				<view :class="['row-amount', item.direction === 'credit' ? 'plus' : 'minus']">{{ item.direction === 'credit' ? '+' : '-' }}{{ item.amount || '0.00' }}</view>
			</view>
			<view v-if="!ledger.length" class="empty">暂无佣金明细</view>
		</view>
		<view v-else class="list">
			<view v-for="item in withdrawals" :key="item.id" class="row-card">
				<view><text class="row-title">{{ item.withdrawal_no }}</text><text class="row-sub">{{ timeText(item.add_time) }}</text></view>
				<view class="right"><text class="row-amount">¥ {{ item.amount || '0.00' }}</text><text class="status">{{ withdrawalLabel(item.status) }}</text></view>
			</view>
			<view v-if="!withdrawals.length" class="empty">暂无提现记录</view>
		</view>
	</view>
</template>

<script>
import { getUserInfo } from '@/api/user.js';
import { getYfthCommissionSummary, getYfthCommissionLedger, getYfthCommissionWithdrawals, requestYfthCommissionWithdrawal } from '@/api/yfth.js';

export default {
	data() {
		return { summary: {}, account: {}, mallBalance: '0.00', ledger: [], withdrawals: [], tab: 'ledger', amount: '', submitting: false };
	},
	computed: {
		totalBalance() { return (Number(this.mallBalance || 0) + Number(this.account.available || 0)).toFixed(2); }
	},
	onShow() { this.load(); },
	methods: {
		load() {
			return Promise.all([
				getUserInfo(),
				getYfthCommissionSummary(),
				getYfthCommissionLedger({ page: 1, limit: 50 }),
				getYfthCommissionWithdrawals({ page: 1, limit: 50 })
			]).then(([user, summary, ledger, withdrawals]) => {
				this.mallBalance = Number((user.data && user.data.now_money) || 0).toFixed(2);
				this.summary = summary.data || {};
				this.account = this.summary.account || {};
				this.ledger = (ledger.data && ledger.data.list) || [];
				this.withdrawals = (withdrawals.data && withdrawals.data.list) || [];
			}).catch((err) => uni.showToast({ title: String((err && err.msg) || err || '账户加载失败'), icon: 'none' }));
		},
		toCents(value) {
			const text = String(value || '').trim();
			const match = text.match(/^(\d+)(?:\.(\d{1,2}))?$/);
			return match ? Number(match[1]) * 100 + Number(((match[2] || '') + '00').slice(0, 2)) : 0;
		},
		submitWithdrawal() {
			const amountCent = this.toCents(this.amount);
			if (!amountCent) return uni.showToast({ title: '请输入正确的提现金额', icon: 'none' });
			if (amountCent > Number(this.account.available_cent || 0)) return uni.showToast({ title: '可提现余额不足', icon: 'none' });
			this.submitting = true;
			requestYfthCommissionWithdrawal({ amount_cent: amountCent, request_id: 'c1-' + Date.now() + '-' + Math.random().toString(16).slice(2) })
				.then(() => { this.amount = ''; uni.showToast({ title: '已提交', icon: 'success' }); return this.load(); })
				.catch((err) => uni.showToast({ title: String((err && err.msg) || err || '提交失败'), icon: 'none' }))
				.finally(() => { this.submitting = false; });
		},
		sourceLabel(type) {
			const labels = { commission_credit: '自动佣金入账', commission_store_credit: '门店佣金入账', commission_proxy_credit: 'C1代发佣金入账', hq_manual_adjustment: '总部余额调整', mall_order_refund: '商城退款冲正', mall_order_refund_proxy: '商城退款冲正', package_invalidated: '套餐奖励冲正', package_invalidated_proxy: '套餐奖励冲正', c1_withdrawal_request: '提现冻结', c1_withdrawal_paid: '提现完成' };
			return labels[type] || type || '账户变动';
		},
		withdrawalLabel(status) { return status === 'paid' ? '提现完成' : '处理中'; },
		timeText(value) { return value ? new Date(Number(value) * 1000).toLocaleString() : ''; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 60rpx; background: #f5f2ed; color: #312a22; }
.balance-panel { padding: 32rpx; border-radius: 16rpx; background: #9a7342; color: #fff; }
.eyebrow { font-size: 24rpx; color: #f4e7d3; }
.balance { margin-top: 18rpx; font-size: 54rpx; font-weight: 700; }
.hint { margin-top: 12rpx; color: #f3e6d2; font-size: 22rpx; }
.metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20rpx 0; margin: 20rpx 0; padding: 24rpx 8rpx; border-radius: 16rpx; background: #fff; }
.metrics view { display: flex; flex-direction: column; align-items: center; gap: 8rpx; border-right: 1rpx solid #eee5da; }
.metrics view:last-child { border-right: 0; }
.metrics text:first-child { color: #70502e; font-size: 30rpx; font-weight: 700; }
.metrics text:last-child { color: #83786d; font-size: 21rpx; }
.panel { padding: 26rpx; border-radius: 16rpx; background: #fff; }
.panel-title { font-size: 29rpx; font-weight: 700; }
.amount-row { display: flex; align-items: center; gap: 14rpx; margin-top: 20rpx; padding: 8rpx 18rpx; border: 1rpx solid #e7dac8; border-radius: 12rpx; }
.amount-row text { font-size: 34rpx; color: #8a6033; }
.amount-row input { flex: 1; height: 72rpx; font-size: 30rpx; }
.primary { margin-top: 18rpx; border-radius: 12rpx; background: #8a6234; color: #fff; font-size: 27rpx; }
.primary[disabled] { opacity: .55; }
.notice { margin-top: 16rpx; color: #8b7a68; font-size: 21rpx; line-height: 1.55; }
.tabs { display: flex; margin-top: 22rpx; padding: 6rpx; border-radius: 12rpx; background: #fff; }
.tabs view { flex: 1; padding: 18rpx; text-align: center; color: #84786c; }
.tabs .active { border-radius: 10rpx; background: #f3eadc; color: #6d4c2c; font-weight: 700; }
.row-card { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; margin-top: 14rpx; padding: 24rpx; border-radius: 14rpx; background: #fff; }
.row-card > view:first-child, .right { display: flex; flex-direction: column; gap: 8rpx; }
.right { align-items: flex-end; }
.row-title { font-size: 26rpx; font-weight: 650; }
.row-sub { color: #999087; font-size: 20rpx; }
.row-amount { font-size: 28rpx; font-weight: 700; }
.plus { color: #54785e; }.minus { color: #a34f45; }.status { color: #8d6a42; font-size: 21rpx; }
.empty { padding: 60rpx 0; color: #9a9187; text-align: center; }
</style>
