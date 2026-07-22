<template>
	<view class="page">
		<view class="hero"><view class="eyebrow">总部统一商城</view><view class="title">永久会员</view><view class="sub">线下购买，线上申请或由所属门店扫码开通</view></view>
		<view class="card">
			<view class="card-title">我的会员状态</view>
			<view v-if="info.is_permanent_member" class="active">已开通 · 永久有效</view>
			<view v-else class="muted">尚未开通永久会员</view>
			<view v-if="info.membership" class="line">归属门店：{{ info.membership.store_id }}</view>
			<view class="line">一级推荐资格：{{ info.has_referral_qualification ? '已具备' : '未具备' }}</view>
		</view>
		<view class="card">
			<view class="card-title">身份码 / 推广码</view>
			<view class="muted">普通用户显示身份码，永久会员显示一级推广码。</view>
			<button class="primary" @click="goCode">查看我的码</button>
			<view v-if="info.pending_enrollment" class="pending">申请 {{ info.pending_enrollment.enrollment_no }} · {{ statusLabel(info.pending_enrollment.status) }}</view>
		</view>
	</view>
</template>
<script>
import { getYfthPermanentMembershipMe } from '@/api/yfth.js';
export default {
	data() { return { info: {} }; },
	onShow() { this.load(); },
	methods: {
		load() { getYfthPermanentMembershipMe().then(res => { this.info = res.data || {}; }); },
		goCode() { uni.navigateTo({ url: '/pages/yfth/referral/code' }); },
		statusLabel(status) { return { pending_store_review: '等待所属门店审核', rejected: '已拒绝', activated: '已开通' }[status] || status; }
	}
};
</script>
<style scoped>
.page{min-height:100vh;background:#f7f4ef;padding:24rpx;box-sizing:border-box}.hero{background:#5a3f2c;color:#fff;padding:34rpx;border-radius:12rpx}.eyebrow{color:#ead7b5;font-size:22rpx}.title{font-size:42rpx;font-weight:700;margin-top:8rpx}.sub{color:#eee2d2;font-size:24rpx;margin-top:8rpx}.card{background:#fff;border:1rpx solid #eadfce;border-radius:12rpx;margin-top:20rpx;padding:28rpx}.card-title{font-size:30rpx;font-weight:700;color:#342b24}.muted,.line{font-size:24rpx;color:#7b6c5e;margin-top:14rpx}.active{color:#287342;font-size:32rpx;font-weight:700;margin-top:18rpx}.pending{background:#fff2d9;color:#8c5c15;padding:18rpx;margin-top:16rpx;border-radius:8rpx}.primary{background:#65472f;color:#fff;border-radius:8rpx;margin-top:22rpx}.token,.input{box-sizing:border-box;width:100%;background:#f7f2eb;border:1rpx solid #dfd1be;border-radius:8rpx;padding:18rpx;margin-top:18rpx;font-size:23rpx}.token{height:130rpx}
</style>
