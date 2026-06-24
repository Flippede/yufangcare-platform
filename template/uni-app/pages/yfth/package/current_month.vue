<template>
	<view class="page">
		<view v-for="item in benefits" :key="item.id" class="benefit">
			<view class="name">{{ item.benefit_name }}</view>
			<view class="row"><text>可用</text><text>{{ item.quantity_available }}</text></view>
			<view class="row"><text>状态</text><text>{{ item.status }}</text></view>
		</view>
		<view v-if="!benefits.length" class="empty">当前暂无可用权益</view>
	</view>
</template>

<script>
import { getYfthCurrentBenefits } from '@/api/yfth.js';

export default {
	data() {
		return { id: 0, benefits: [] };
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		getYfthCurrentBenefits({ instance_id: this.id }).then((res) => {
			this.benefits = res.data || [];
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
.benefit {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 18rpx;
}
.name {
	font-size: 32rpx;
	font-weight: 700;
}
.row {
	display: flex;
	justify-content: space-between;
	margin-top: 14rpx;
	color: #60707c;
}
.empty {
	text-align: center;
	margin-top: 120rpx;
	color: #7a858f;
}
</style>
