<template>
	<view class="closure-page" :style="colorStyle">
		<view class="closure-hero">
			<view class="closure-icon">!</view>
			<view class="closure-title">账号注销</view>
			<view class="closure-copy">注销后原账号永久失效。再次注册会生成全新账号，不继承旧订单、会员、归属、推荐、奖励或经营身份。</view>
		</view>

		<view class="closure-card">
			<view class="card-title">注销前须知</view>
			<view class="notice-item">1. 商城余额、未完成订单、退款、履约、奖励和经营责任必须先处理。</view>
			<view class="notice-item">2. 积分、优惠券等无现金价值权益将在确认后放弃并清除。</view>
			<view class="notice-item">3. 必要交易、财务和履约历史会依法使用随机主体编号匿名保存。</view>
			<view class="notice-item">4. 原手机号、账号或微信身份可重新注册，但新 UID 不会关联旧历史。</view>
		</view>

		<view class="closure-card" v-if="agreementData.content">
			<view class="card-title">账号注销协议</view>
			<view class="agreement-content" v-html="agreementData.content"></view>
		</view>

		<view class="closure-card preflight-card">
			<view class="card-head">
				<view class="card-title">注销预检</view>
				<view class="refresh" @tap="loadPreflight">重新检查</view>
			</view>
			<view v-if="preflightLoading" class="state-copy">正在检查订单、资金、履约与经营责任...</view>
			<template v-else-if="preflight">
				<view class="state-line" :class="preflight.can_close ? 'ready' : 'blocked'">
					<text>{{ preflight.can_close ? '当前可以申请注销' : '当前存在阻塞事项' }}</text>
				</view>
				<view class="state-copy">{{ preflight.safety_note }}</view>
				<view v-if="preflight.blockers && preflight.blockers.length" class="blockers">
					<view v-for="item in preflight.blockers" :key="item.code" class="blocker-item">
						{{ item.label }}（{{ item.count }}）
					</view>
				</view>
				<view v-if="preflight.forfeitures && preflight.forfeitures.length" class="forfeit-box">
					<view class="forfeit-title">注销时将主动放弃</view>
					<view v-for="item in preflight.forfeitures" :key="item.code" class="forfeit-item">{{ item.label }}：{{ item.amount }}</view>
				</view>
			</template>
		</view>

		<view class="closure-action">
			<view class="action-tip">需完成安全验证、勾选协议并输入“确认注销”</view>
			<button class="close-button" :disabled="!preflight || !preflight.can_close" @tap="openConfirmation">继续注销</button>
		</view>

		<view v-if="confirmationVisible" class="dialog-mask" @tap="closeConfirmation">
			<view class="closure-dialog" @tap.stop>
				<view class="dialog-warning">!</view>
				<view class="dialog-title">安全验证与最后确认</view>
				<view class="dialog-copy">注销无法恢复，重新注册是全新账号。</view>

				<view v-if="hasMultipleMethods" class="verify-tabs">
					<view :class="{ active: verificationType === 'password' }" @tap="verificationType = 'password'">账号密码</view>
					<view :class="{ active: verificationType === 'sms' }" @tap="verificationType = 'sms'">短信验证</view>
				</view>
				<input v-if="verificationType === 'password'" v-model="password" password class="confirmation-input" placeholder="请输入当前账号密码" />
				<template v-else>
					<input v-model.trim="smsPhone" type="number" maxlength="11" class="confirmation-input" :placeholder="'请输入当前手机号 ' + (preflight.phone_masked || '')" />
					<view class="sms-row">
						<input v-model.trim="smsCode" type="number" maxlength="6" class="confirmation-input" placeholder="短信验证码" />
						<button class="sms-button" :disabled="countdown > 0" @tap="requestSmsChallenge">{{ countdown > 0 ? countdown + '秒' : '获取验证码' }}</button>
					</view>
				</template>

				<view class="agreement-check" @tap="agreementChecked = !agreementChecked">
					<view class="check-circle" :class="{ checked: agreementChecked }">{{ agreementChecked ? '✓' : '' }}</view>
					<text>我已阅读并同意账号注销协议，自愿放弃所列权益</text>
				</view>
				<input v-model.trim="confirmation" class="confirmation-input" placeholder="请输入：确认注销" />
				<view class="dialog-actions">
					<button class="dialog-button secondary" @tap="closeConfirmation">取消</button>
					<button class="dialog-button danger" :disabled="!canSubmit || submitting" @tap="submitClosure">
						{{ submitting ? '正在注销...' : '确认永久注销' }}
					</button>
				</view>
			</view>
		</view>
		<Verify @success="sendSmsCode" :captchaType="captchaType" :imgSize="{ width: '330px', height: '155px' }" ref="verify"></Verify>
	</view>
</template>

<script>
	import colors from '@/mixins/color.js';
	import Verify from '../components/verify/index.vue';
	import { cancelUser, getCodeApi, getUserAgreement, getUserCancelPreflight, registerVerify } from '@/api/user.js';
	const app = getApp();
	export default {
		components: { Verify },
		mixins: [colors],
		data() {
			return {
				agreementData: {}, preflight: null, preflightLoading: false,
				confirmationVisible: false, confirmation: '', agreementChecked: false,
				verificationType: 'password', password: '', smsPhone: '', smsCode: '',
				captchaType: 'clickWord', countdown: 0, countdownTimer: null, submitting: false,
			};
		},
		computed: {
			confirmationPhrase() { return (this.preflight && this.preflight.confirmation_phrase) || '确认注销'; },
			verificationMethods() { return (this.preflight && this.preflight.verification_methods) || []; },
			hasMultipleMethods() { return this.verificationMethods.length > 1; },
			canSubmit() {
				const verified = this.verificationType === 'password' ? Boolean(this.password) : Boolean(this.smsPhone && this.smsCode);
				return verified && this.agreementChecked && this.confirmation === this.confirmationPhrase;
			},
		},
		onLoad() { this.loadAgreement(); this.loadPreflight(); },
		onUnload() { if (this.countdownTimer) clearInterval(this.countdownTimer); },
		methods: {
			loadAgreement() { getUserAgreement(5).then((res) => { this.agreementData = res.data || {}; }); },
			loadPreflight() {
				this.preflightLoading = true;
				getUserCancelPreflight().then((res) => {
					this.preflight = res.data || null;
					const methods = (this.preflight && this.preflight.verification_methods) || [];
					this.verificationType = methods.indexOf('password') !== -1 ? 'password' : 'sms';
				}).catch((message) => this.$util.Tips({ title: message }))
					.finally(() => { this.preflightLoading = false; });
			},
			openConfirmation() {
				if (!this.preflight || !this.preflight.can_close) return;
				this.confirmation = ''; this.password = ''; this.smsPhone = ''; this.smsCode = ''; this.agreementChecked = false;
				this.confirmationVisible = true;
			},
			closeConfirmation() { if (!this.submitting) this.confirmationVisible = false; },
			requestSmsChallenge() {
				if (!/^1\d{10}$/.test(this.smsPhone)) return this.$util.Tips({ title: '请输入当前账号绑定的手机号' });
				this.$refs.verify.show();
			},
			async sendSmsCode(data) {
				this.$refs.verify.hide();
				try {
					const keyRes = await getCodeApi();
					await registerVerify({ phone: this.smsPhone, type: 'user_cancel', key: keyRes.data.key, captchaType: this.captchaType, captchaVerification: data.captchaVerification });
					this.countdown = 60;
					this.countdownTimer = setInterval(() => { if (--this.countdown <= 0) clearInterval(this.countdownTimer); }, 1000);
					this.$util.Tips({ title: '验证码已发送' });
				} catch (message) { this.$util.Tips({ title: message }); }
			},
			submitClosure() {
				if (!this.canSubmit || this.submitting) return;
				this.submitting = true;
				cancelUser({
					confirmation: this.confirmation, agreement: true, verification_type: this.verificationType,
					password: this.verificationType === 'password' ? this.password : '',
					sms_phone: this.verificationType === 'sms' ? this.smsPhone : '',
					sms_code: this.verificationType === 'sms' ? this.smsCode : '',
				}).then(() => {
					app.globalData.spid = ''; app.globalData.pid = ''; this.$store.commit('LOGOUT');
					uni.showToast({ title: '账号已注销', icon: 'success' });
					setTimeout(() => uni.reLaunch({ url: '/pages/index/index' }), 500);
				}).catch((message) => this.$util.Tips({ title: message }))
					.finally(() => { this.submitting = false; });
			},
		},
	};
</script>

<style lang="scss" scoped>
	.closure-page { min-height: 100vh; padding: 28rpx 24rpx 220rpx; box-sizing: border-box; background: #f5f2ec; color: #241d18; }
	.closure-hero { padding: 44rpx 34rpx; border-radius: 12rpx; color: #fff; background: #9a7642; text-align: center; }
	.closure-icon, .dialog-warning { display: flex; align-items: center; justify-content: center; width: 72rpx; height: 72rpx; margin: 0 auto 20rpx; border: 4rpx solid currentColor; border-radius: 50%; font-size: 48rpx; font-weight: 700; }
	.closure-title { font-size: 40rpx; font-weight: 700; }
	.closure-copy { margin-top: 18rpx; font-size: 25rpx; line-height: 1.7; color: rgba(255,255,255,.9); }
	.closure-card { margin-top: 22rpx; padding: 30rpx; border-radius: 12rpx; background: #fff; }
	.card-head { display: flex; align-items: center; justify-content: space-between; }
	.card-title { margin-bottom: 22rpx; font-size: 30rpx; font-weight: 700; }
	.card-head .card-title { margin-bottom: 0; }
	.refresh { padding: 8rpx 0 8rpx 20rpx; color: #9a7642; font-size: 24rpx; }
	.notice-item, .state-copy { margin-top: 12rpx; color: #756b61; font-size: 25rpx; line-height: 1.7; }
	.agreement-content { max-height: 320rpx; overflow-y: auto; color: #756b61; font-size: 24rpx; line-height: 1.7; }
	.preflight-card { border: 1rpx solid #e8ddca; }
	.state-line { display: inline-flex; margin-top: 24rpx; padding: 10rpx 18rpx; border-radius: 6rpx; font-size: 25rpx; font-weight: 600; }
	.state-line.ready { color: #38714c; background: #edf7f0; }
	.state-line.blocked { color: #b33c32; background: #fff0ee; }
	.blockers, .forfeit-box { margin-top: 18rpx; }
	.blocker-item { margin-top: 10rpx; padding: 14rpx 18rpx; border-radius: 6rpx; color: #8b3d35; background: #fff5f3; font-size: 24rpx; }
	.forfeit-box { padding: 18rpx; border-radius: 8rpx; background: #fff9ed; color: #765a2f; font-size: 24rpx; }
	.forfeit-title { margin-bottom: 8rpx; font-weight: 700; }
	.forfeit-item { margin-top: 6rpx; }
	.closure-action { position: fixed; right: 0; bottom: 0; left: 0; z-index: 20; padding: 20rpx 30rpx calc(20rpx + env(safe-area-inset-bottom)); background: #fff; border-top: 1rpx solid #eee7dc; }
	.action-tip { margin-bottom: 12rpx; color: #9a9087; font-size: 22rpx; text-align: center; }
	.close-button { height: 86rpx; line-height: 86rpx; border: 0; border-radius: 8rpx; color: #fff; background: #b84639; font-size: 29rpx; }
	.close-button[disabled] { color: #aaa39b; background: #e8e4df; }
	.dialog-mask { position: fixed; inset: 0; z-index: 99; display: flex; align-items: center; justify-content: center; padding: 34rpx; background: rgba(20,16,12,.55); }
	.closure-dialog { width: 100%; max-width: 620rpx; max-height: 88vh; overflow-y: auto; padding: 36rpx 32rpx 30rpx; box-sizing: border-box; border-radius: 12rpx; background: #fff; text-align: center; }
	.dialog-warning { width: 64rpx; height: 64rpx; color: #c54438; font-size: 40rpx; }
	.dialog-title { font-size: 34rpx; font-weight: 700; }
	.dialog-copy { margin: 14rpx 0 22rpx; color: #746960; font-size: 24rpx; line-height: 1.65; }
	.verify-tabs { display: grid; grid-template-columns: 1fr 1fr; margin-bottom: 16rpx; padding: 6rpx; border-radius: 8rpx; background: #f3efe9; }
	.verify-tabs view { padding: 14rpx; border-radius: 6rpx; color: #84786c; font-size: 25rpx; }
	.verify-tabs .active { color: #704f25; background: #fff; font-weight: 700; }
	.confirmation-input { width: 100%; height: 82rpx; margin-top: 14rpx; padding: 0 22rpx; box-sizing: border-box; border: 1rpx solid #d8cbb9; border-radius: 8rpx; background: #faf8f4; font-size: 27rpx; text-align: left; }
	.sms-row { display: grid; grid-template-columns: 1fr 190rpx; gap: 12rpx; }
	.sms-button { height: 82rpx; margin-top: 14rpx; line-height: 82rpx; border: 0; border-radius: 8rpx; color: #fff; background: #9a7642; font-size: 24rpx; }
	.agreement-check { display: flex; align-items: flex-start; gap: 14rpx; margin-top: 22rpx; color: #5f564e; font-size: 24rpx; line-height: 1.55; text-align: left; }
	.check-circle { flex: 0 0 32rpx; width: 32rpx; height: 32rpx; line-height: 30rpx; border: 2rpx solid #a99e93; border-radius: 50%; color: transparent; text-align: center; }
	.check-circle.checked { border-color: #9a7642; color: #fff; background: #9a7642; }
	.dialog-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; margin-top: 28rpx; }
	.dialog-button { height: 78rpx; line-height: 78rpx; border: 0; border-radius: 8rpx; font-size: 27rpx; }
	.dialog-button.secondary { color: #5d554d; background: #f2eee8; }
	.dialog-button.danger { color: #fff; background: #b84639; }
	.dialog-button.danger[disabled] { color: #aaa39b; background: #e8e4df; }
	@media (min-width: 760px) { .closure-page, .closure-action { width: 430px; margin-right: auto; margin-left: auto; } }
</style>
