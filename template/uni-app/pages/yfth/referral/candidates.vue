<template>
	<view class="page">
		<view v-for="item in list" :key="item.id" class="card">
			<view class="title">{{ item.scene }}</view>
			<view class="line">状态：{{ item.status }}</view>
			<view class="line">推荐人：{{ item.referrer_uid }}　被推荐人：{{ item.referred_uid }}</view>
			<view class="line">绑定时间：{{ item.bind_time || '-' }}</view>
		</view>
		<view v-if="!list.length" class="empty">暂无推荐进度</view>
	</view>
</template>

<script>
import { getYfthReferralCandidates } from '@/api/yfth.js';

export default {
	data() {
		return { list: [] };
	},
	onShow() {
		getYfthReferralCandidates({}).then((res) => {
			this.list = (res.data && res.data.list) || [];
		});
	},
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f7f4ee; padding: 24rpx; }
.card { background: #fff; border-radius: 12rpx; padding: 28rpx; margin-bottom: 18rpx; }
.title { font-size: 30rpx; color: #3a2b18; font-weight: 600; }
.line { color: #77674f; font-size: 24rpx; margin-top: 10rpx; }
.empty { text-align: center; color: #998b76; padding: 80rpx 0; }
</style>
