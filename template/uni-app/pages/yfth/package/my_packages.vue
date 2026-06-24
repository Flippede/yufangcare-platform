<template>
	<view class="page">
		<view v-for="item in list" :key="item.id" class="package" @click="openDetail(item)">
			<view class="name">{{ item.instance_no }}</view>
			<view class="row"><text>{{ item.status }}</text><text>{{ item.refund_status }}</text></view>
		</view>
		<view v-if="!list.length" class="empty">暂无套餐</view>
	</view>
</template>

<script>
import { getYfthMyPackages } from '@/api/yfth.js';

export default {
	data() {
		return { list: [] };
	},
	onShow() {
		getYfthMyPackages().then((res) => {
			this.list = (res.data && res.data.list) || [];
		});
	},
	methods: {
		openDetail(item) {
			uni.navigateTo({ url: '/pages/yfth/package/package_detail?id=' + item.id });
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
.package {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 18rpx;
}
.name {
	font-size: 30rpx;
	font-weight: 700;
}
.row {
	display: flex;
	justify-content: space-between;
	margin-top: 14rpx;
	color: #62707a;
}
.empty {
	text-align: center;
	margin-top: 120rpx;
	color: #7a858f;
}
</style>
