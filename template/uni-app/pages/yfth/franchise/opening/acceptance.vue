<template>
	<view class="page">
		<view class="panel">
			<view class="title">开店验收</view>
			<view class="line">状态：{{ acceptance.status_text || '-' }}</view>
			<view class="line">门店ID：{{ acceptance.system_store_id || '-' }}</view>
			<view v-for="item in acceptance.items" :key="item.id" class="item">
				<text>{{ item.item_name }}</text>
				<text>{{ item.result }}</text>
			</view>
			<button @click="submit">提交验收申请</button>
			<view v-if="acceptance.reject_reason" class="warn">驳回原因：{{ acceptance.reject_reason }}</view>
		</view>
	</view>
</template>

<script>
import { getYfthFranchiseOpeningAcceptance, submitYfthFranchiseOpeningAcceptance } from '@/api/yfth.js';

export default {
	data() { return { acceptance: {} }; },
	onShow() { this.load(); },
	methods: {
		load() {
			getYfthFranchiseOpeningAcceptance().then((res) => {
				this.acceptance = (res.data && res.data.acceptance) || {};
			});
		},
		submit() {
			submitYfthFranchiseOpeningAcceptance({ reason: 'user_submit_acceptance' }).then(() => {
				uni.showToast({ title: '已提交', icon: 'success' });
				this.load();
			});
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.panel { background: #fff; border-radius: 18rpx; padding: 26rpx; box-shadow: 0 10rpx 26rpx rgba(70,45,30,.06); }
.title { font-size: 34rpx; font-weight: 700; color: #2d2434; margin-bottom: 20rpx; }
.line, .warn { color: #5f5148; font-size: 26rpx; margin-top: 14rpx; }
.warn { color: #b45a2c; }
.item { display: flex; justify-content: space-between; padding: 16rpx 0; border-bottom: 1px solid #f0e3d6; color: #3a3029; font-size: 24rpx; }
button { margin-top: 26rpx; background: #7b4e25; color: #fff; border-radius: 12rpx; }
</style>
