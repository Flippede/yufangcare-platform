<template>
	<view class="page">
		<view class="header">
			<view class="title">{{ detail.instance_no }}</view>
			<view class="status">{{ detail.status }} / {{ detail.refund_status }}</view>
		</view>
		<view class="grid">
			<button @click="goTimeline">十个月时间线</button>
			<button @click="goCurrent">当前月权益</button>
		</view>
		<view class="section">
			<view class="section-title">计划</view>
			<view>计划号：{{ detail.plan && detail.plan.plan_no }}</view>
			<view>月份：{{ detail.plan && detail.plan.month_count }}</view>
		</view>
	</view>
</template>

<script>
import { getYfthMyPackageDetail } from '@/api/yfth.js';

export default {
	data() {
		return { id: 0, detail: {} };
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		getYfthMyPackageDetail(this.id).then((res) => {
			this.detail = res.data || {};
		});
	},
	methods: {
		goTimeline() {
			uni.navigateTo({ url: '/pages/yfth/package/timeline?id=' + this.id });
		},
		goCurrent() {
			uni.navigateTo({ url: '/pages/yfth/package/current_month?id=' + this.id });
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	background: #f5f7f8;
	padding: 24rpx;
}
.header,
.section {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 20rpx;
}
.title {
	font-size: 34rpx;
	font-weight: 700;
}
.status {
	margin-top: 12rpx;
	color: #65717c;
}
.grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16rpx;
	margin-bottom: 20rpx;
}
.grid button {
	height: 78rpx;
	line-height: 78rpx;
	border-radius: 10rpx;
	background: #2f7668;
	color: #fff;
	font-size: 26rpx;
}
.section-title {
	font-weight: 700;
	margin-bottom: 12rpx;
}
</style>
