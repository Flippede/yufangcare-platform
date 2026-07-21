<template>
	<view class="page">
		<view class="hero">
			<view class="title">{{ detail.package_name || '御方通和康养会员套餐' }}</view>
			<view class="price">¥ {{ detail.rule ? detail.rule.package_price : detail.base_price || '0.00' }}</view>
			<view class="meta">{{ detail.benefit_months || 0 }}个月套餐权益</view>
			<view v-if="detail.rule && detail.rule.grants_permanent_membership" class="membership-badge">购买后激活永久会员资格</view>
		</view>
		<view class="section">
			<view class="section-title">套餐说明</view>
			<view class="summary">{{ detail.service_summary || '购买后生成独立套餐权益，已是会员也可以再次购买。' }}</view>
			<view class="notice">每次购买都会生成独立订单和权益记录；永久会员资格保持有效，不会阻止再次购买。</view>
		</view>
		<view class="section">
			<view class="section-title">套餐权益</view>
			<view v-for="item in benefits" :key="item.id" class="benefit">
				<text>{{ item.benefit_name }}</text><text>第{{ item.month_no }}月 · {{ item.quantity }}份</text>
			</view>
			<view v-if="!benefits.length" class="empty">套餐权益以当前发布规则为准</view>
		</view>
		<view class="footer">
			<button class="ghost" @click="goMine">我的套餐</button>
			<button class="btn" @click="goStores">选择门店并购买</button>
		</view>
	</view>
</template>

<script>
import { getYfthPackageDetail } from '@/api/yfth.js';

export default {
	data() { return { id: 0, detail: {}, benefits: [] }; },
	onLoad(options) {
		this.id = Number(options.id || 0);
		getYfthPackageDetail(this.id).then((res) => {
			this.detail = res.data || {};
			this.benefits = this.detail.benefits || [];
		}).catch((err) => this.$util.Tips({ title: (err && (err.msg || err.message)) || '套餐加载失败' }));
	},
	methods: {
		goStores() { uni.navigateTo({ url: '/pages/yfth/package/store_select?id=' + this.id }); },
		goMine() { uni.navigateTo({ url: '/pages/yfth/package/my_packages' }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f2ed; padding-bottom: 140rpx; color: #312a22; }
.hero { padding: 56rpx 32rpx 44rpx; background: #b88b4f; color: #fff; }
.title { font-size: 42rpx; font-weight: 700; }.price { margin-top: 24rpx; font-size: 56rpx; font-weight: 700; }
.meta { margin-top: 12rpx; font-size: 26rpx; opacity: .9; }.membership-badge { display: inline-block; margin-top: 18rpx; padding: 10rpx 18rpx; border: 1px solid rgba(255,255,255,.62); border-radius: 8rpx; font-size: 24rpx; }
.section { margin: 24rpx; padding: 24rpx; background: #fff; border-radius: 12rpx; }.section-title { margin-bottom: 18rpx; font-size: 32rpx; font-weight: 600; }
.summary,.notice,.empty { color: #756b60; font-size: 26rpx; line-height: 1.65; }.notice { margin-top: 18rpx; padding: 18rpx; border: 1px solid #ead6b5; border-radius: 8rpx; background: #fff8ea; }
.benefit { display: flex; justify-content: space-between; padding: 18rpx 0; border-top: 1px solid #edf0f2; font-size: 26rpx; }
.footer { position: fixed; left: 0; right: 0; bottom: 0; display: flex; gap: 16rpx; padding: 18rpx 24rpx calc(28rpx + env(safe-area-inset-bottom)); background: #fff; box-shadow: 0 -8rpx 24rpx rgba(60,45,30,.08); }
.btn,.ghost { flex: 1; height: 84rpx; line-height: 84rpx; border-radius: 10rpx; font-size: 28rpx; }.btn { color: #fff; background: #9a7342; }.ghost { color: #79562f; background: #f4ecdf; }
</style>
