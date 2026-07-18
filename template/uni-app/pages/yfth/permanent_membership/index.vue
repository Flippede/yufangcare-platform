<template>
	<view class="page">
		<view class="hero"><view class="eyebrow">总部统一商城</view><view class="title">永久会员</view><view class="sub">固定 9800 元线下办理，需本人最终确认</view></view>
		<view class="card">
			<view class="card-title">我的会员状态</view>
			<view v-if="info.is_permanent_member" class="active">已开通 · 永久有效</view>
			<view v-else class="muted">尚未开通永久会员</view>
			<view v-if="info.membership" class="line">归属门店：{{ info.membership.store_id }}</view>
			<view class="line">一级推荐资格：{{ info.has_referral_qualification ? '已具备' : '未具备' }}</view>
		</view>
		<view class="card">
			<view class="card-title">顾客身份码</view>
			<view class="muted">办理人员扫码后才能绑定当前登录账号。刷新会立即替换旧码。</view>
			<button class="primary" @click="identityCode">生成 / 刷新身份码</button>
			<textarea v-if="identity.token" v-model="identity.token" readonly class="token" />
			<view v-if="identity.expire_time" class="muted">有效至 {{ formatTime(identity.expire_time) }}</view>
		</view>
		<view class="card">
			<view class="card-title">本人确认开通</view>
			<view v-if="info.pending_enrollment" class="pending">办理号 {{ info.pending_enrollment.enrollment_no }} · ￥9800.00</view>
			<input v-model="confirmationToken" class="input" placeholder="扫描或粘贴会员确认码" />
			<button class="primary" :disabled="submitting" @click="confirm">确认开通永久会员</button>
		</view>
	</view>
</template>
<script>
import { confirmYfthPermanentMembership, generateYfthPermanentMembershipIdentityCode, getYfthPermanentMembershipMe } from '@/api/yfth.js';
export default {
	data() { return { info: {}, identity: {}, confirmationToken: '', submitting: false }; },
	onShow() { this.load(); },
	methods: {
		load() { getYfthPermanentMembershipMe().then(res => { this.info = res.data || {}; }); },
		identityCode() { generateYfthPermanentMembershipIdentityCode().then(res => { this.identity = res.data || {}; }); },
		confirm() { const token = String(this.confirmationToken || '').trim(); if (!token) return uni.showToast({ title: '请先扫描确认码', icon: 'none' }); this.submitting = true; confirmYfthPermanentMembership({ confirmation_token: token, idempotency_key: 'pm_confirm_' + Date.now() }).then(() => { uni.showToast({ title: '永久会员已开通' }); this.confirmationToken = ''; this.load(); }).finally(() => { this.submitting = false; }); },
		formatTime(value) { const d = new Date(Number(value || 0) * 1000); return Number(value) ? d.toLocaleString() : '-'; },
	}
};
</script>
<style scoped>
.page{min-height:100vh;background:#f7f4ef;padding:24rpx;box-sizing:border-box}.hero{background:#5a3f2c;color:#fff;padding:34rpx;border-radius:12rpx}.eyebrow{color:#ead7b5;font-size:22rpx}.title{font-size:42rpx;font-weight:700;margin-top:8rpx}.sub{color:#eee2d2;font-size:24rpx;margin-top:8rpx}.card{background:#fff;border:1rpx solid #eadfce;border-radius:12rpx;margin-top:20rpx;padding:28rpx}.card-title{font-size:30rpx;font-weight:700;color:#342b24}.muted,.line{font-size:24rpx;color:#7b6c5e;margin-top:14rpx}.active{color:#287342;font-size:32rpx;font-weight:700;margin-top:18rpx}.pending{background:#fff2d9;color:#8c5c15;padding:18rpx;margin-top:16rpx;border-radius:8rpx}.primary{background:#65472f;color:#fff;border-radius:8rpx;margin-top:22rpx}.token,.input{box-sizing:border-box;width:100%;background:#f7f2eb;border:1rpx solid #dfd1be;border-radius:8rpx;padding:18rpx;margin-top:18rpx;font-size:23rpx}.token{height:130rpx}
</style>
