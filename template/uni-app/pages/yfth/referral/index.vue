<template>
	<view class="page">
		<view class="hero">
			<view class="title">推荐中心</view>
			<view class="desc">查看推荐进度与总部确认的只读奖励台账</view>
		</view>
		<view class="grid">
			<view class="tile" @click="go('/pages/yfth/referral/code?scene=package_5980')">
				<view class="tile-title">5980套餐推荐</view>
				<view class="tile-desc">生成与查看C端推荐码</view>
			</view>
			<view class="tile" @click="go('/pages/yfth/referral/code?scene=franchise_opening')">
				<view class="tile-title">加盟开店推荐</view>
				<view class="tile-desc">加盟商门店推荐码</view>
			</view>
			<view class="tile" @click="go('/pages/yfth/referral/candidates')">
				<view class="tile-title">推荐进度</view>
				<view class="tile-desc">候选关系与归因状态</view>
			</view>
			<view class="tile" @click="go('/pages/yfth/referral/ledger')">
				<view class="tile-title">奖励台账</view>
				<view class="tile-desc">只读记录与线下结算状态</view>
			</view>
		</view>
	</view>
</template>

<script>
export default {
	methods: {
		go(url) {
			uni.navigateTo({ url });
		},
	},
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f7f4ee; padding: 28rpx; }
.hero { background: #8b6b3e; color: #fff; border-radius: 16rpx; padding: 32rpx; }
.title { font-size: 40rpx; font-weight: 600; }
.desc { margin-top: 12rpx; font-size: 26rpx; opacity: .86; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20rpx; margin-top: 24rpx; }
.tile { background: #fff; border-radius: 12rpx; padding: 28rpx; box-shadow: 0 8rpx 24rpx rgba(110, 86, 48, .08); }
.tile-title { color: #3a2b18; font-size: 30rpx; font-weight: 600; }
.tile-desc { color: #8b7a65; font-size: 24rpx; margin-top: 12rpx; line-height: 1.5; }
</style>
