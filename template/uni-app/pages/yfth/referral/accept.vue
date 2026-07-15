<template>
	<view class="page">
		<view class="card">
			<view class="mark">御</view>
			<view class="title">会员邀请</view>
			<view v-if="state === 'loading'" class="message">正在验证邀请并确认归属...</view>
			<view v-else-if="state === 'login'" class="message">登录后将自动继续处理本次邀请</view>
			<view v-else-if="state === 'success'" class="message success">邀请已接受，你已与邀请人归属同一门店。</view>
			<view v-else class="message error">{{ error || '邀请暂时无法处理' }}</view>
			<button v-if="state === 'login'" class="primary" @click="login">去登录</button>
			<button v-if="state === 'error'" class="primary" @click="accept">重新尝试</button>
			<button v-if="state === 'success'" class="secondary" @click="goMembership">查看套餐会员</button>
		</view>
	</view>
</template>

<script>
import { mapGetters } from 'vuex';
import { toLogin } from '@/libs/login.js';
import { acceptYfthDirectReferralInvite } from '@/api/yfth.js';
const PENDING_KEY = 'yfth_pending_referral_invite';

export default {
	data() { return { token: '', state: 'loading', error: '', redirecting: false, submitting: false }; },
	computed: { ...mapGetters(['isLogin']) },
	onLoad(options) {
		this.token = String((options && options.invite_token) || uni.getStorageSync(PENDING_KEY) || '').trim().toLowerCase();
		if (!/^[a-f0-9]{64}$/.test(this.token)) { this.state = 'error'; this.error = '推广码无效或已损坏'; return; }
		uni.setStorageSync(PENDING_KEY, this.token);
	},
	onShow() {
		if (!/^[a-f0-9]{64}$/.test(this.token)) return;
		if (!this.isLogin) { this.state = 'login'; if (!this.redirecting) this.login(); return; }
		this.accept();
	},
	methods: {
		login() { this.redirecting = true; this.state = 'login'; toLogin(); setTimeout(() => { this.redirecting = false; }, 1200); },
		accept() {
			if (this.submitting || !this.isLogin) return;
			this.submitting = true; this.state = 'loading'; this.error = '';
			const uid = Number(this.$store.state.app.uid || 0);
			const operation = `scan-invite-${this.token.slice(0, 16)}-${uid}`;
			acceptYfthDirectReferralInvite({ invite_token: this.token, idempotency_key: operation, request_id: operation })
				.then(() => { uni.removeStorageSync(PENDING_KEY); this.state = 'success'; })
				.catch((err) => { this.state = 'error'; this.error = (err && (err.msg || err.message)) || String(err || '邀请无法接受'); })
				.finally(() => { this.submitting = false; });
		},
		goMembership() { uni.redirectTo({ url: '/pages/yfth/package_membership/index' }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; display: flex; align-items: center; padding: 36rpx; box-sizing: border-box; background: #f5f2ec; }.card { width: 100%; padding: 54rpx 38rpx; box-sizing: border-box; border-radius: 14rpx; text-align: center; background: #fff; box-shadow: 0 14rpx 40rpx rgba(92,66,34,.08); }.mark { width: 92rpx; height: 92rpx; margin: 0 auto; border-radius: 50%; color: #fff; background: #9b713b; font-size: 48rpx; line-height: 92rpx; }.title { margin-top: 26rpx; font-size: 38rpx; font-weight: 700; }.message { margin: 22rpx 0 34rpx; color: #756c62; font-size: 26rpx; line-height: 1.6; }.success { color: #3f7657; }.error { color: #a64b42; }.primary,.secondary { width: 100%; color: #fff; background: #9b713b; }.secondary { color: #7b572c; background: #f7efe2; }
</style>
