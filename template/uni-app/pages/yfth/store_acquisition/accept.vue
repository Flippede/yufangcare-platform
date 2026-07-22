<template>
	<view class="page">
		<view class="card">
			<view class="mark">店</view>
			<view class="title">确认门店归属</view>
			<view v-if="state === 'loading'" class="message">正在核验门店专属码...</view>
			<view v-else-if="state === 'login'" class="message">登录后将自动继续本次门店绑定</view>
			<view v-else-if="state === 'binding'" class="message">正在建立门店归属，请稍候...</view>
			<view v-else-if="state === 'confirm'" class="message">
				<view class="store">{{ preview.store_name }}</view>
				<view>来源：{{ preview.issuer_role_name }} {{ preview.issuer_name || '门店员工' }}</view>
				<view class="muted">确认后将建立永久门店归属，但不会建立会员推荐奖励关系。</view>
			</view>
			<view v-else-if="state === 'success'" class="message success">
				<view>门店绑定成功</view><view class="store">{{ result.store_name }}</view>
				<view>来源：{{ result.source_role_name }} {{ result.source_employee_name }}</view>
				<view class="muted">即将返回御方通和商城首页</view>
			</view>
			<view v-else class="message error">{{ error || '该门店码暂时无法使用' }}</view>
			<button v-if="state === 'login'" class="primary" @click="login">去登录</button>
			<button v-if="state === 'confirm'" class="primary" @click="accept">确认绑定</button>
			<button v-if="state === 'error'" class="secondary" @click="leaveFailure">返回主页</button>
			<button v-if="state === 'success'" class="secondary" @click="goHome">返回商城首页</button>
		</view>
	</view>
</template>

<script>
import { mapGetters } from 'vuex';
import { toLogin, checkLogin } from '@/libs/login.js';
import { resolveYfthStoreAcquisitionCode, acceptYfthStoreAcquisitionCode } from '@/api/yfth.js';
const PENDING_KEY = 'yfth_pending_store_acquisition';

export default {
	data() { return { token: '', state: 'loading', error: '', preview: {}, result: {}, resolving: false, submitting: false, redirecting: false, successTimer: null }; },
	computed: { ...mapGetters(['isLogin']) },
	onLoad(options) {
		this.token = String((options && options.acquisition_token) || uni.getStorageSync(PENDING_KEY) || '').trim().toLowerCase();
		if (!/^[a-f0-9]{64}$/.test(this.token)) { this.state = 'error'; this.error = '门店专属码无效或已损坏'; return; }
		uni.setStorageSync(PENDING_KEY, this.token);
	},
	onShow() { if (/^[a-f0-9]{64}$/.test(this.token) && !this.resolving && !this.submitting && !this.redirecting && !['success', 'error'].includes(this.state)) this.resolve(); },
	onUnload() { if (this.successTimer) clearTimeout(this.successTimer); },
	methods: {
		resolve() {
			if (this.resolving || this.submitting || this.redirecting || this.state === 'success') return;
			this.resolving = true;
			this.state = 'loading'; this.error = '';
			resolveYfthStoreAcquisitionCode(this.token).then((res) => {
				this.preview = res.data || {};
				if (!this.isLogin) checkLogin();
				if (!this.isLogin) { this.state = 'login'; if (!this.redirecting) this.login(); return; }
				this.state = 'confirm';
				this.$nextTick(() => this.accept());
			}).catch((err) => { this.state = 'error'; this.error = (err && (err.msg || err.message)) || '门店专属码不可用'; })
				.finally(() => { this.resolving = false; });
		},
		login() { this.redirecting = true; this.state = 'login'; toLogin(); setTimeout(() => { this.redirecting = false; }, 1200); },
		accept() {
			if (this.submitting || !this.isLogin) return;
			this.submitting = true; this.state = 'binding';
			const uid = Number(this.$store.state.app.uid || 0);
			const operation = `store-acquisition-${this.token.slice(0, 16)}-${uid}`;
			acceptYfthStoreAcquisitionCode({ acquisition_token: this.token, request_id: operation, idempotency_key: operation })
				.then((res) => {
					this.result = res.data || {};
					if (!this.result.accepted || this.result.attribution_status !== 'active') throw new Error('门店归属结果校验失败');
					uni.removeStorageSync(PENDING_KEY);
					this.state = 'success';
					this.successTimer = setTimeout(() => this.goHome(), 1600);
				})
				.catch((err) => { this.state = 'error'; this.error = (err && (err.msg || err.message)) || '门店绑定失败'; })
				.finally(() => { this.submitting = false; });
		},
		leaveFailure() {
			uni.removeStorageSync(PENDING_KEY);
			this.goHome();
		},
		goHome() { uni.reLaunch({ url: '/pages/index/index' }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; display: flex; align-items: center; padding: 36rpx; box-sizing: border-box; background: #f5f1ea; }.card { width: 100%; padding: 50rpx 38rpx; box-sizing: border-box; border-radius: 14rpx; text-align: center; background: #fff; box-shadow: 0 14rpx 40rpx rgba(90,62,28,.08); }.mark { width: 90rpx; height: 90rpx; margin: 0 auto; border-radius: 50%; color: #fff; background: #9b713b; font-size: 42rpx; line-height: 90rpx; }.title { margin-top: 24rpx; font-size: 38rpx; font-weight: 700; }.message { margin: 22rpx 0 32rpx; color: #71675b; font-size: 26rpx; line-height: 1.65; }.store { margin: 12rpx 0; color: #765127; font-size: 31rpx; font-weight: 700; }.muted { margin-top: 16rpx; color: #918678; font-size: 23rpx; }.success { color: #397553; }.error { color: #a34b42; }.primary,.secondary { width: 100%; color: #fff; background: #9b713b; }.secondary { color: #765127; background: #f7efe2; }
</style>
