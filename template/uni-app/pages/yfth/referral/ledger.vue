<template>
	<view class="page">
		<view v-for="item in list" :key="item.id" class="card" @click="detail(item.id)">
			<view class="row">
				<view class="title">{{ item.ledger_no }}</view>
				<view class="status">{{ item.status }}</view>
			</view>
			<view class="amount">{{ item.amount_cent }} 分</view>
			<view class="line">{{ item.scene }} / {{ item.business_type }} #{{ item.business_id }}</view>
			<view class="line">线下结算状态：{{ item.status === 'settled' ? '总部已标记' : '未标记' }}</view>
		</view>
		<view v-if="!list.length" class="empty">暂无奖励台账</view>
	</view>
</template>

<script>
import { getYfthRewardLedger } from '@/api/yfth.js';

export default {
	data() {
		return { list: [] };
	},
	onShow() {
		getYfthRewardLedger({}).then((res) => {
			this.list = (res.data && res.data.list) || [];
		});
	},
	methods: {
		detail(id) {
			uni.navigateTo({ url: '/pages/yfth/referral/ledger_detail?id=' + id });
		},
	},
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f7f4ee; padding: 24rpx; }
.card { background: #fff; border-radius: 12rpx; padding: 28rpx; margin-bottom: 18rpx; }
.row { display: flex; justify-content: space-between; align-items: center; }
.title { color: #3a2b18; font-size: 28rpx; font-weight: 600; }
.status { color: #8b6b3e; font-size: 24rpx; }
.amount { margin-top: 18rpx; color: #3a2b18; font-size: 38rpx; font-weight: 700; }
.line { color: #77674f; font-size: 24rpx; margin-top: 10rpx; }
.empty { text-align: center; color: #998b76; padding: 80rpx 0; }
</style>
