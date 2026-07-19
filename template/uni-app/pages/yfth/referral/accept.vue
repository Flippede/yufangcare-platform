<template>
	<view class="page">
		<view class="card">
			<view class="mark">御</view>
			<view class="title">会员邀请</view>
			<view v-if="state === 'loading'" class="message">正在验证邀请并确认归属...</view>
			<view v-else-if="state === 'login'" class="message">登录后将自动继续处理本次邀请</view>
			<view v-else-if="state === 'success'" class="message success">
				<view>邀请已接受，你已与邀请人归属同一门店。</view>
				<view class="result-line">推荐人：{{ result.referrer_nickname || '御方通和永久会员' }}</view>
				<view class="result-line">归属门店：{{ result.store_name || ('门店 ' + result.store_id) }}</view>
			</view>
			<view v-else class="message error">{{ error || '邀请暂时无法处理' }}</view>
			<button v-if="state === 'login'" class="primary" @click="login">去登录</button>
			<view v-if="state === 'success'" class="customer-state">当前为普通顾客，购买套餐并成功激活后才可获得永久会员资格。</view>
			<view v-if="state === 'error'" class="error-actions">
				<button class="primary" @click="accept">重新尝试</button>
				<button class="secondary" @click="goHome">返回商城首页</button>
			</view>
			<button v-if="state === 'success'" class="secondary" @click="goHome">进入商城首页</button>
		</view>
	</view>
</template>

<script>
import { mapGetters } from 'vuex';
import { toLogin } from '@/libs/login.js';
import { acceptYfthDirectReferralInvite } from '@/api/yfth.js';
import { goYfthHeadquartersHome } from '@/libs/yfthReferralNavigation.js';
const PENDING_KEY = 'yfth_pending_referral_invite';

export default {
	data() { return { token: '', state: 'loading', error: '', result: {}, redirecting: false, submitting: false, homeTimer: 0 }; },
	computed: { ...mapGetters(['isLogin']) },
	onLoad(options) {
		this.token = String((options && options.invite_token) || uni.getStorageSync(PENDING_KEY) || '').trim().toLowerCase();
		if (!/^[a-f0-9]{64}$/.test(this.token)) { this.state = 'error'; this.error = '推广码无效或已损坏'; return; }
		uni.setStorageSync(PENDING_KEY, this.token);
	},
	onShow() {
		if (!/^[a-f0-9]{64}$/.test(this.token)) return;
		if (this.state === 'success') return;
		if (!this.isLogin) { this.state = 'login'; if (!this.redirecting) this.login(); return; }
		this.accept();
	},
	onUnload() { this.clearHomeTimer(); },
	methods: {
		login() { this.redirecting = true; this.state = 'login'; toLogin(); setTimeout(() => { this.redirecting = false; }, 1200); },
		accept() {
			if (this.submitting || !this.isLogin) return;
			this.submitting = true; this.state = 'loading'; this.error = '';
			const uid = Number(this.$store.state.app.uid || 0);
			const operation = `scan-invite-${this.token.slice(0, 16)}-${uid}`;
			acceptYfthDirectReferralInvite({ invite_token: this.token, idempotency_key: operation, request_id: operation })
				.then((res) => {
					this.result = res.data || {};
					uni.removeStorageSync(PENDING_KEY);
					this.state = 'success';
					this.scheduleHomeRedirect();
				})
				.catch((err) => { this.state = 'error'; this.error = this.errorText(err); })
				.finally(() => { this.submitting = false; });
		},
		scheduleHomeRedirect() {
			this.clearHomeTimer();
			this.homeTimer = setTimeout(() => this.goHome(), 2500);
		},
		clearHomeTimer() {
			if (this.homeTimer) clearTimeout(this.homeTimer);
			this.homeTimer = 0;
		},
		goHome() {
			this.clearHomeTimer();
			goYfthHeadquartersHome();
		},
		errorText(err) {
			const value = String((err && (err.msg || err.message)) || err || '');
			const messages = {
				direct_referral_invite_invalid: '推广码无效或已损坏',
				direct_referral_invite_unavailable: '推广码已过期、已使用或已停用',
				direct_referral_referred_user_must_be_non_member: '永久会员不能接受新的推荐邀请',
				direct_referral_active_relation_exists: '你已有有效推荐关系，不能重复绑定',
				referrer_attribution_store_mismatch: '推荐人的门店归属暂不可用'
			};
			return messages[value] || value || '邀请暂时无法处理';
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; display: flex; align-items: center; padding: 36rpx; box-sizing: border-box; background: #f5f2ec; }.card { width: 100%; padding: 54rpx 38rpx; box-sizing: border-box; border-radius: 14rpx; text-align: center; background: #fff; box-shadow: 0 14rpx 40rpx rgba(92,66,34,.08); }.mark { width: 92rpx; height: 92rpx; margin: 0 auto; border-radius: 50%; color: #fff; background: #9b713b; font-size: 48rpx; line-height: 92rpx; }.title { margin-top: 26rpx; font-size: 38rpx; font-weight: 700; }.message { margin: 22rpx 0 34rpx; color: #756c62; font-size: 26rpx; line-height: 1.6; }.success { color: #3f7657; }.error { color: #a64b42; }.primary,.secondary { width: 100%; color: #fff; background: #9b713b; }.secondary { color: #7b572c; background: #f7efe2; }
.result-line { margin-top: 10rpx; color: #6c5f51; font-size: 24rpx; }
.customer-state { margin: -18rpx 0 28rpx; color: #7b6b59; font-size: 24rpx; line-height: 1.6; }
.error-actions { display: flex; gap: 16rpx; }
.error-actions button { flex: 1; }
</style>
