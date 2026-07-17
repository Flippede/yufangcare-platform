<template>
	<view class="closure-page" :style="colorStyle">
		<view class="closure-hero">
			<view class="closure-icon">!</view>
			<view class="closure-title">账号正式销户</view>
			<view class="closure-copy">销户成功后账号、会员、经营身份、推荐关系、永久归属及门店客户记录将被永久删除。</view>
		</view>

		<view class="closure-card">
			<view class="card-title">销户前须知</view>
			<view class="notice-item">1. 销户是不可恢复操作，原账号不能找回。</view>
			<view class="notice-item">2. 成功销户后，同一手机号或微信身份可按新用户重新注册。</view>
			<view class="notice-item">3. 若存在订单、支付、退款、履约或已结算奖励等必须保留的事实，系统会拒绝完整销户。</view>
			<view class="notice-item">4. 系统会在事务结束前再次检查全部 UID 引用，存在残留时自动回滚。</view>
		</view>

		<view class="closure-card" v-if="agreementData.content">
			<view class="card-title">用户注销协议</view>
			<view class="agreement-content" v-html="agreementData.content"></view>
		</view>

		<view class="closure-card preflight-card">
			<view class="card-head">
				<view class="card-title">销户检查</view>
				<view class="refresh" @tap="loadPreflight">重新检查</view>
			</view>
			<view v-if="preflightLoading" class="state-copy">正在检查账号关联数据...</view>
			<template v-else-if="preflight">
				<view class="state-line" :class="preflight.can_close ? 'ready' : 'blocked'">
					<text>{{ preflight.can_close ? '当前可以完整销户' : '当前不能完整销户' }}</text>
				</view>
				<view class="state-copy">{{ preflight.safety_note }}</view>
				<view v-if="preflight.blocking_categories && preflight.blocking_categories.length" class="blockers">
					<view v-for="item in preflight.blocking_categories" :key="item" class="blocker-item">{{ item }}</view>
				</view>
			</template>
		</view>

		<view class="closure-action">
			<view class="action-tip">点击后仍需手动输入“确认注销”</view>
			<button class="close-button" :disabled="!preflight || !preflight.can_close" @tap="openConfirmation">申请销户</button>
		</view>

		<view v-if="confirmationVisible" class="dialog-mask" @tap="closeConfirmation">
			<view class="closure-dialog" @tap.stop>
				<view class="dialog-warning">!</view>
				<view class="dialog-title">最后确认</view>
				<view class="dialog-copy">该操作会永久删除账号及可删除的全部关联数据，无法撤销。</view>
				<input v-model.trim="confirmation" class="confirmation-input" placeholder="请输入：确认注销" />
				<view class="dialog-actions">
					<button class="dialog-button secondary" @tap="closeConfirmation">取消</button>
					<button class="dialog-button danger" :disabled="confirmation !== confirmationPhrase || submitting" @tap="submitClosure">
						{{ submitting ? '正在销户...' : '确认永久销户' }}
					</button>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import colors from '@/mixins/color.js';
	import { cancelUser, getUserAgreement, getUserCancelPreflight } from '@/api/user.js';
	const app = getApp();
	export default {
		mixins: [colors],
		data() {
			return {
				agreementData: {},
				preflight: null,
				preflightLoading: false,
				confirmationVisible: false,
				confirmation: '',
				submitting: false,
			};
		},
		computed: {
			confirmationPhrase() {
				return (this.preflight && this.preflight.confirmation_phrase) || '确认注销';
			},
		},
		onLoad() {
			this.loadAgreement();
			this.loadPreflight();
		},
		methods: {
			loadAgreement() {
				getUserAgreement(5).then((res) => { this.agreementData = res.data || {}; });
			},
			loadPreflight() {
				this.preflightLoading = true;
				getUserCancelPreflight().then((res) => { this.preflight = res.data || null; })
					.catch((message) => this.$util.Tips({ title: message }))
					.finally(() => { this.preflightLoading = false; });
			},
			openConfirmation() {
				if (!this.preflight || !this.preflight.can_close) return;
				this.confirmation = '';
				this.confirmationVisible = true;
			},
			closeConfirmation() {
				if (this.submitting) return;
				this.confirmationVisible = false;
				this.confirmation = '';
			},
			submitClosure() {
				if (this.confirmation !== this.confirmationPhrase || this.submitting) return;
				this.submitting = true;
				cancelUser({ confirmation: this.confirmation }).then(() => {
					app.globalData.spid = '';
					app.globalData.pid = '';
					this.$store.commit('LOGOUT');
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
	.closure-copy { margin-top: 18rpx; font-size: 25rpx; line-height: 1.7; color: rgba(255,255,255,.86); }
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
	.blockers { margin-top: 18rpx; }
	.blocker-item { margin-top: 10rpx; padding: 14rpx 18rpx; border-radius: 6rpx; color: #8b3d35; background: #fff5f3; font-size: 24rpx; }
	.closure-action { position: fixed; right: 0; bottom: 0; left: 0; z-index: 20; padding: 20rpx 30rpx calc(20rpx + env(safe-area-inset-bottom)); background: #fff; border-top: 1rpx solid #eee7dc; }
	.action-tip { margin-bottom: 12rpx; color: #9a9087; font-size: 22rpx; text-align: center; }
	.close-button { height: 86rpx; line-height: 86rpx; border: 0; border-radius: 8rpx; color: #fff; background: #b84639; font-size: 29rpx; }
	.close-button[disabled] { color: #aaa39b; background: #e8e4df; }
	.dialog-mask { position: fixed; inset: 0; z-index: 99; display: flex; align-items: center; justify-content: center; padding: 34rpx; background: rgba(20,16,12,.55); }
	.closure-dialog { width: 100%; max-width: 620rpx; padding: 40rpx 32rpx 30rpx; box-sizing: border-box; border-radius: 12rpx; background: #fff; text-align: center; }
	.dialog-warning { width: 64rpx; height: 64rpx; color: #c54438; font-size: 40rpx; }
	.dialog-title { font-size: 34rpx; font-weight: 700; }
	.dialog-copy { margin: 18rpx 0 26rpx; color: #746960; font-size: 24rpx; line-height: 1.65; }
	.confirmation-input { width: 100%; height: 82rpx; padding: 0 22rpx; box-sizing: border-box; border: 1rpx solid #d8cbb9; border-radius: 8rpx; background: #faf8f4; font-size: 28rpx; text-align: left; }
	.dialog-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; margin-top: 28rpx; }
	.dialog-button { height: 78rpx; line-height: 78rpx; border: 0; border-radius: 8rpx; font-size: 27rpx; }
	.dialog-button.secondary { color: #5d554d; background: #f2eee8; }
	.dialog-button.danger { color: #fff; background: #b84639; }
	.dialog-button.danger[disabled] { color: #aaa39b; background: #e8e4df; }
	@media (min-width: 760px) { .closure-page, .closure-action { width: 430px; margin-right: auto; margin-left: auto; } }
</style>
