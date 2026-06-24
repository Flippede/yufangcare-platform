<template>
	<view class="page">
		<view v-for="period in timeline" :key="period.id" class="period">
			<view class="period-head">
				<text>第{{ period.month_no }}月</text>
				<text>{{ period.status }}</text>
			</view>
			<view v-for="item in period.items" :key="item.id" class="item">
				<text>{{ item.benefit_name }}</text>
				<text>{{ item.status }}</text>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthTimeline } from '@/api/yfth.js';

export default {
	data() {
		return { id: 0, timeline: [] };
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		getYfthTimeline(this.id).then((res) => {
			this.timeline = res.data || [];
		});
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	background: #f5f7f8;
	padding: 24rpx;
}
.period {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 18rpx;
}
.period-head,
.item {
	display: flex;
	justify-content: space-between;
}
.period-head {
	font-size: 30rpx;
	font-weight: 700;
	margin-bottom: 14rpx;
}
.item {
	padding: 14rpx 0;
	border-top: 1px solid #edf0f2;
	color: #58636f;
}
</style>
