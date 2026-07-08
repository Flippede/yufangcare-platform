<template>
	<view class="page">
		<view class="card">
			<view class="title">{{ ledger.ledger_no || '台账详情' }}</view>
			<view class="amount">{{ ledger.amount_cent || 0 }} 分</view>
			<view class="line">状态：{{ ledger.status || '-' }}</view>
			<view class="line">业务：{{ ledger.business_type || '-' }} #{{ ledger.business_id || '-' }}</view>
			<view class="line">观察期：{{ ledger.observe_start_time || '-' }} - {{ ledger.observe_end_time || '-' }}</view>
			<view class="line">线下结算状态：{{ ledger.status === 'settled' ? '总部已确认' : '未确认' }}</view>
		</view>
		<view class="notice">本页面仅展示奖励台账，不代表可提现余额或系统付款。</view>
	</view>
</template>

<script>
import { getYfthRewardLedgerDetail } from '@/api/yfth.js';

export default {
	data() {
		return { id: 0, ledger: {} };
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		getYfthRewardLedgerDetail(this.id).then((res) => {
			this.ledger = (res.data && res.data.ledger) || {};
		});
	},
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f7f4ee; padding: 24rpx; }
.card { background: #fff; border-radius: 12rpx; padding: 32rpx; }
.title { color: #3a2b18; font-size: 32rpx; font-weight: 600; }
.amount { margin: 22rpx 0; color: #8b6b3e; font-size: 44rpx; font-weight: 700; }
.line { color: #77674f; font-size: 25rpx; margin-top: 12rpx; }
.notice { color: #998b76; font-size: 24rpx; line-height: 1.6; margin-top: 24rpx; }
</style>
