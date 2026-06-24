<template>
	<view class="page">
		<view class="panel">
			<view class="state">{{ status.purchase_status || 'created' }}</view>
			<view class="sub">{{ status.activation_status || 'pending' }}</view>
			<view class="order">{{ status.order_sn }}</view>
		</view>
		<button class="btn" @click="refresh">刷新状态</button>
		<button class="ghost" @click="goMine">查看我的套餐</button>
	</view>
</template>

<script>
import { getYfthPurchaseStatus } from '@/api/yfth.js';

export default {
	data() {
		return { purchaseNo: '', status: {} };
	},
	onLoad(options) {
		this.purchaseNo = options.purchase_no || '';
		this.refresh();
	},
	methods: {
		refresh() {
			getYfthPurchaseStatus(this.purchaseNo).then((res) => {
				this.status = res.data || {};
			});
		},
		goMine() {
			uni.navigateTo({ url: '/pages/yfth/package/my_packages' });
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	padding: 32rpx;
	background: #f5f7f8;
}
.panel {
	text-align: center;
	background: #fff;
	border-radius: 12rpx;
	padding: 60rpx 24rpx;
}
.state {
	font-size: 44rpx;
	font-weight: 700;
	color: #2f7668;
}
.sub,
.order {
	margin-top: 16rpx;
	color: #65717c;
}
.btn,
.ghost {
	margin-top: 24rpx;
	height: 84rpx;
	line-height: 84rpx;
	border-radius: 10rpx;
}
.btn {
	color: #fff;
	background: #2f7668;
}
.ghost {
	color: #2f7668;
	background: #eef7f5;
}
</style>
