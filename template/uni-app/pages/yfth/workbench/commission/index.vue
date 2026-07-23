<template>
	<view class="page">
		<view class="header">
			<view><text class="eyebrow">C1 线下结算</text><text class="title">¥ {{ c1Account.unsettled || '0.00' }}</text><text class="sub">待完成结算金额</text></view>
			<view class="store-name">{{ context.store_name || ('门店 ' + context.store_id) }}</view>
		</view>
		<view class="metrics">
			<view><text>{{ c1Account.unsettled || '0.00' }}</text><text>待完成结算金额</text></view>
			<view><text>{{ c1Account.settled || '0.00' }}</text><text>已完成结算金额</text></view>
		</view>
		<view class="tabs">
			<view v-for="item in tabs" :key="item.key" :class="{ active: tab === item.key }" @click="tab = item.key">{{ item.label }}</view>
		</view>
		<view v-if="tab === 'batches'" class="section">
			<view v-for="item in batches" :key="item.id" class="record">
				<view class="record-head"><text>{{ item.batch_no }}</text><text>¥ {{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">{{ timeText(item.period_start) }} - {{ timeText(item.period_end) }}</view>
				<view class="status">{{ batchStatus(item.status) }}</view>
			</view>
			<view v-if="!batches.length" class="empty">暂无结算批次</view>
		</view>
		<view v-else-if="tab === 'c1'" class="section">
			<view v-for="item in c1Settlements" :key="item.id" class="record">
				<view class="record-head"><text>{{ item.user && item.user.nickname || 'C1用户' }}</text><text>¥ {{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">{{ item.user && item.user.phone_masked }} · {{ item.status === 'paid' ? '已完成结算' : '已申请结算' }}</view>
				<button v-if="item.status === 'pending'" class="outline" @click="completeC1(item)">线下完成后标记结算完成</button>
			</view>
			<view v-if="!c1Settlements.length" class="empty">暂无 C1 结算申请</view>
		</view>
		<view v-else class="section">
			<view v-for="item in ledger" :key="item.id" class="record">
				<view class="record-head"><text>{{ sourceLabel(item.source_type) }}</text><text :class="item.direction === 'credit' ? 'plus' : 'minus'">{{ item.direction === 'credit' ? '+' : '-' }}{{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">余额 {{ item.balance_after || '0.00' }} · {{ timeText(item.add_time) }}</view>
			</view>
			<view v-if="!ledger.length" class="empty">暂无佣金明细</view>
		</view>
		<view class="footer-note">门店佣金按总部结算周期处理，不提供门店余额或提现入口；微信分账能力当前仅预留。</view>
	</view>
</template>

<script>
import { getYfthStoreCommissionSummary, getYfthStoreCommissionLedger, getYfthStoreC1Settlements, completeYfthStoreC1Settlement, getYfthStoreCommissionSettlementBatches } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() { return { context: currentContext(), account: {}, c1Account: {}, ledger: [], c1Settlements: [], batches: [], tab: 'batches' }; },
	computed: { tabs() { return [{ key: 'batches', label: '结算明细' }, { key: 'c1', label: 'C1结算' }, { key: 'ledger', label: '佣金明细' }]; } },
	onShow() { this.context = currentContext(); this.load(); },
	methods: {
		params() { return { role_code: this.context.role_code, store_id: this.context.store_id, page: 1, limit: 50 }; },
		load() {
			const params = this.params();
			return Promise.all([getYfthStoreCommissionSummary(params), getYfthStoreCommissionLedger(params), getYfthStoreC1Settlements(params), getYfthStoreCommissionSettlementBatches(params)])
				.then(([summary, ledger, c1, batches]) => {
					this.account = (summary.data && summary.data.account) || {};
					this.c1Account = (summary.data && summary.data.c1_account) || {};
					this.ledger = (ledger.data && ledger.data.list) || [];
					this.c1Settlements = (c1.data && c1.data.list) || [];
					this.batches = (batches.data && batches.data.list) || [];
				}).catch((err) => uni.showToast({ title: String((err && err.msg) || err || '门店结算加载失败'), icon: 'none' }));
		},
		completeC1(item) {
			uni.showModal({ title: '确认线下结算', content: '仅在线下款项已支付给 C1 后操作。', success: (res) => {
				if (!res.confirm) return;
				completeYfthStoreC1Settlement(item.id, Object.assign(this.params(), { request_id: 'c1-settled-' + item.id, remark: '门店确认线下结算完成' }))
					.then(() => { uni.showToast({ title: '已完成', icon: 'success' }); this.load(); })
					.catch((err) => uni.showToast({ title: String((err && err.msg) || err || '操作失败'), icon: 'none' }));
			} });
		},
		batchStatus(value) { return ({ pending: '待结算', processing: '结算中', settled: '已结算', exception: '异常' })[value] || value; },
		sourceLabel(value) { return ({ commission_c1_responsibility_credit: 'C1佣金责任额', commission_b1_credit: 'B1佣金', manual_adjustment: '总部台账调整' })[value] || value || '佣金变动'; },
		timeText(value) { return value ? new Date(Number(value) * 1000).toLocaleDateString() : '-'; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 60rpx; background: #f5f0e8; color: #302820; }.header { display: flex; justify-content: space-between; gap: 20rpx; padding: 30rpx; border-radius: 16rpx; background: #826038; color: #fff; }.header > view:first-child { display: flex; flex-direction: column; }.eyebrow,.sub { color: #f1e1cc; font-size: 22rpx; }.title { margin: 10rpx 0; font-size: 48rpx; font-weight: 700; }.store-name { max-width: 250rpx; text-align: right; font-size: 23rpx; }.metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 14rpx; margin-top: 18rpx; }.metrics view { display: flex; flex-direction: column; gap: 8rpx; padding: 22rpx; border-radius: 14rpx; background: #fff; }.metrics text:first-child { color: #74532f; font-size: 31rpx; font-weight: 700; }.metrics text:last-child { color: #85796d; font-size: 21rpx; }.tabs { display: flex; gap: 8rpx; margin-top: 20rpx; padding: 6rpx; border-radius: 12rpx; background: #fff; }.tabs view { flex: 1; padding: 16rpx 8rpx; text-align: center; color: #827465; font-size: 23rpx; }.tabs .active { border-radius: 10rpx; background: #f1e6d5; color: #674625; font-weight: 700; }.record { margin-top: 14rpx; padding: 24rpx; border-radius: 14rpx; background: #fff; }.record-head { display: flex; justify-content: space-between; gap: 14rpx; font-size: 26rpx; font-weight: 700; }.record-sub { margin-top: 9rpx; color: #887b6d; font-size: 22rpx; line-height: 1.55; }.status { margin-top: 8rpx; color: #7a5a35; font-size: 22rpx; }.outline { margin-top: 16rpx; border: 1rpx solid #b89061; border-radius: 12rpx; background: #fff9f0; color: #76512b; font-size: 25rpx; }.plus { color: #56745c; }.minus { color: #a14f45; }.empty { padding: 60rpx 0; color: #998e82; text-align: center; }.footer-note { margin-top: 24rpx; color: #94887a; font-size: 20rpx; text-align: center; line-height: 1.55; }
</style>
