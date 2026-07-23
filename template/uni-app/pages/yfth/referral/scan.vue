<template>
	<view class="scanner-page">
		<!-- #ifdef H5 -->
		<video
			id="yfth-referral-camera"
			class="camera-view"
			autoplay
			muted
			playsinline
			:controls="false"
			:show-center-play-btn="false"
			:show-progress="false"
			:enable-progress-gesture="false"
			object-fit="cover"
		></video>
		<!-- #endif -->
		<view class="camera-shade"></view>
		<view class="scanner-header" :style="{ paddingTop: safeTop + 'px' }">
			<view class="round-action back-action" aria-label="返回" @click="goBack">
				<text class="iconfont icon-fanhui"></text>
			</view>
			<text class="scanner-title">扫一扫</text>
			<view class="header-spacer"></view>
		</view>

		<view class="scan-stage">
			<view class="scan-frame" :class="{ inactive: !cameraActive }">
				<view class="corner corner-tl"></view>
				<view class="corner corner-tr"></view>
				<view class="corner corner-bl"></view>
				<view class="corner corner-br"></view>
				<view v-if="cameraActive" class="scan-line"></view>
			</view>
			<text v-if="cameraActive" class="scan-hint">{{ scanHint }}</text>
			<view v-else class="camera-state">
				<text class="state-title">{{ cameraStateTitle }}</text>
				<text class="state-copy">{{ cameraStateCopy }}</text>
				<text v-if="canRetryCamera" class="retry-link" @click="scan">重新打开摄像头</text>
			</view>
		</view>

		<view class="scanner-footer" :style="{ paddingBottom: safeBottom + 18 + 'px' }">
			<view class="input-action" @click="toggleInput">
				<text class="input-icon">⌨</text>
				<text>{{ inputActionLabel }}</text>
			</view>
			<!-- #ifdef H5 -->
			<view class="album-action" aria-label="从相册选择二维码" @click="chooseQrImage">
				<text class="iconfont icon-tupian"></text>
				<text>相册</text>
			</view>
			<!-- #endif -->
			<!-- #ifndef H5 -->
			<view class="album-action" aria-label="从相册选择二维码" @click="scan">
				<text class="iconfont icon-tupian"></text>
				<text>相册</text>
			</view>
			<!-- #endif -->
		</view>

		<view v-if="inputVisible" class="input-mask" @click="toggleInput">
			<view class="input-sheet" @click.stop>
				<view class="sheet-handle"></view>
				<view class="sheet-title">{{ inputSheetTitle }}</view>
				<textarea v-model.trim="input" maxlength="1024" :placeholder="inputPlaceholder" />
				<button class="submit-button" @click="submitInput">{{ inputSubmitLabel }}</button>
				<button class="cancel-button" @click="toggleInput">取消</button>
			</view>
		</view>

		<view v-if="activationState" class="activation-mask">
			<view class="activation-card">
				<view class="activation-icon" :class="'is-' + activationState">
					<text v-if="activationState === 'success'">✓</text>
					<text v-else-if="activationState === 'error'">!</text>
					<text v-else>会</text>
				</view>
				<text class="activation-title">{{ activationTitle }}</text>
				<text class="activation-copy">{{ activationMessage }}</text>
				<view v-if="activationState === 'confirm'" class="activation-actions">
					<button class="activation-secondary" @click="cancelIdentityActivation">取消</button>
					<button class="activation-primary" @click="submitIdentityActivation">确认开通</button>
				</view>
				<view v-else-if="activationState === 'success'" class="activation-actions single">
					<button class="activation-primary" @click="goUserCenter">返回我的</button>
				</view>
				<view v-else-if="activationState === 'error'" class="activation-actions">
					<button class="activation-secondary" @click="goUserCenter">返回我的</button>
					<button class="activation-primary" @click="retryIdentityActivation">重新扫码</button>
				</view>
				<view v-else class="activation-loading">
					<view class="loading-dot"></view>
					<text>正在开通，请勿关闭页面</text>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
// #ifdef H5
import jsQR from 'jsqr';
// #endif
import { activateYfthStorePermanentMembershipIdentity } from '@/api/yfth.js';
import { currentContext, loadYfthIdentities, resolveYfthContext } from '@/libs/yfthContext.js';
import { yfthReferralAcceptRoute } from '@/libs/yfthReferralNavigation.js';

export default {
	data() {
		return {
			input: '',
			mode: 'referral',
			inputVisible: false,
			cameraActive: false,
			cameraPending: true,
			cameraError: '',
			stream: null,
			detector: null,
			detecting: false,
			animationFrame: 0,
			scanCanvas: null,
			lastScanAt: 0,
			nativeScannerOpened: false,
			h5FilePicker: null,
			operatorContext: null,
			pendingIdentityActivation: null,
			activationState: '',
			activationMessage: '',
			activationReturnTimer: 0,
			safeTop: 20,
			safeBottom: 0
		};
	},
	computed: {
		cameraStateTitle() {
			return this.cameraPending ? '正在打开摄像头' : '摄像头未能启动';
		},
		cameraStateCopy() {
			if (this.cameraPending) return '首次使用时请允许摄像头权限';
			return this.cameraError || '请检查摄像头权限，或从相册选择二维码';
		},
		canRetryCamera() {
			return !this.cameraPending && !this.cameraActive;
		},
		isStoreMembershipOperator() {
			return this.isMembershipOperatorContext(this.operatorContext || currentContext());
		},
		membershipScanPreferred() {
			return this.mode === 'membership_activation' || this.isStoreMembershipOperator;
		},
		scanHint() {
			return this.membershipScanPreferred
				? '将顾客的御方通和身份码放入框内'
				: '将御方通和推广二维码放入框内';
		},
		inputActionLabel() {
			return this.membershipScanPreferred ? '输入身份码' : '输入邀请码';
		},
		inputSheetTitle() {
			return this.membershipScanPreferred ? '输入顾客身份码' : '输入邀请链接或邀请码';
		},
		inputPlaceholder() {
			return this.membershipScanPreferred ? '粘贴顾客身份码或身份码链接' : '粘贴邀请链接或 64 位推广码';
		},
		inputSubmitLabel() {
			return this.membershipScanPreferred ? '核验身份' : '识别邀请';
		},
		activationTitle() {
			const titles = {
				confirm: '确认开通会员',
				submitting: '正在开通会员',
				success: '会员开通成功',
				error: '会员开通未完成'
			};
			return titles[this.activationState] || '';
		}
	},
	onLoad(options) {
		this.mode = options && options.mode === 'membership_activation' ? 'membership_activation' : 'referral';
		const system = uni.getSystemInfoSync ? uni.getSystemInfoSync() : {};
		this.safeTop = Number((system.safeAreaInsets && system.safeAreaInsets.top) || system.statusBarHeight || 20);
		this.safeBottom = Number((system.safeAreaInsets && system.safeAreaInsets.bottom) || 0);
		if (this.mode === 'membership_activation') {
			this.resolveMembershipOperatorContext().catch(() => {});
		}
	},
	onReady() {
		this.$nextTick(() => this.scan());
	},
	onUnload() {
		this.stopCamera();
		this.cleanupH5FilePicker();
		this.clearActivationReturnTimer();
	},
		onHide() { this.stopCamera(); },
	methods: {
		scan() {
			// #ifdef MP-WEIXIN
			if (this.nativeScannerOpened) return;
			this.nativeScannerOpened = true;
			uni.scanCode({ onlyFromCamera: false, scanType: ['qrCode'], success: (res) => this.consume(res.result), fail: (err) => {
				this.nativeScannerOpened = false;
				if (String((err && err.errMsg) || '').includes('cancel')) return this.goBack();
				this.cameraPending = false;
				this.cameraError = '扫码未完成，请重试或从相册选择二维码';
			} });
			return;
			// #endif
			// #ifdef H5
			this.startH5Camera();
			return;
			// #endif
			this.cameraPending = false;
			this.cameraError = '当前设备无法调用扫码能力，请输入邀请链接';
		},
		startH5Camera() {
			this.cameraPending = true;
			this.cameraError = '';
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
				this.cameraPending = false;
				this.cameraError = '当前浏览器无法调用摄像头，请改用相册或邀请码';
				return;
			}
			this.stopCamera(false);
			this.detector = window.BarcodeDetector ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;
			navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then((stream) => {
				this.stream = stream;
				this.cameraActive = true;
				this.cameraPending = false;
				this.$nextTick(() => {
					const root = document.getElementById('yfth-referral-camera');
					const video = root && root.tagName === 'VIDEO' ? root : (root && root.querySelector ? root.querySelector('video') : null);
					if (!video) throw new Error('camera_element_unavailable');
					video.srcObject = stream;
					video.setAttribute('playsinline', 'true');
					video.play().then(() => this.detectLoop(video));
				});
			}).catch(() => {
				this.stopCamera(false);
				this.cameraPending = false;
				this.cameraError = '无法打开摄像头，请检查浏览器权限或改用相册';
			});
		},
		detectLoop(video) {
			if (!this.cameraActive) return;
			const now = Date.now();
			if (!this.detecting && video.readyState >= 2 && now - this.lastScanAt >= 140) {
				this.lastScanAt = now;
				this.detecting = true;
				this.decodeQrSource(video).then((value) => {
					if (value) {
						this.stopCamera();
						this.consume(value);
					}
				}).catch(() => {}).finally(() => { this.detecting = false; });
			}
			if (this.cameraActive) this.animationFrame = window.requestAnimationFrame(() => this.detectLoop(video));
		},
		chooseQrImage() {
			// #ifdef H5
			this.chooseH5QrImage();
			return;
			// #endif
			uni.chooseImage({ count: 1, sourceType: ['album'], success: (res) => {
				const path = res.tempFilePaths && res.tempFilePaths[0];
				if (!path) return this.toast('未选择二维码图片');
				this.loadImage(path).then((image) => this.decodeQrSource(image)).then((value) => {
					if (!value) throw new Error('qr_not_found');
					this.consume(value);
				}).catch(() => this.toast('图片中未识别到有效推广二维码'));
			} });
		},
		chooseH5QrImage() {
			if (typeof document === 'undefined') return this.toast('当前浏览器无法读取相册');
			this.cleanupH5FilePicker();
			const picker = document.createElement('input');
			picker.type = 'file';
			picker.accept = 'image/png,image/jpeg,image/webp,image/gif';
			picker.multiple = false;
			picker.setAttribute('aria-label', '选择二维码图片');
			picker.style.position = 'fixed';
			picker.style.left = '-9999px';
			picker.style.bottom = '0';
			picker.style.width = '1px';
			picker.style.height = '1px';
			picker.style.opacity = '0';
			this.h5FilePicker = picker;
			picker.addEventListener('change', () => {
				const file = picker.files && picker.files[0];
				this.cleanupH5FilePicker();
				if (!file) return;
				if (!/^image\//i.test(String(file.type || ''))) return this.toast('请选择二维码图片');
				const objectUrl = window.URL.createObjectURL(file);
				this.loadImage(objectUrl).then((image) => this.decodeQrSource(image)).then((value) => {
					if (!value) throw new Error('qr_not_found');
					this.consume(value);
				}).catch(() => this.toast('图片中未识别到有效推广二维码')).finally(() => {
					window.URL.revokeObjectURL(objectUrl);
				});
			});
			document.body.appendChild(picker);
			picker.click();
		},
		cleanupH5FilePicker() {
			if (this.h5FilePicker && this.h5FilePicker.parentNode) this.h5FilePicker.parentNode.removeChild(this.h5FilePicker);
			this.h5FilePicker = null;
		},
		decodeQrSource(source) {
			if (this.detector && this.detector.detect) {
				return this.detector.detect(source).then((codes) => (codes && codes[0] && codes[0].rawValue) || '')
					.catch(() => this.decodeQrWithCanvas(source));
			}
			return Promise.resolve(this.decodeQrWithCanvas(source));
		},
		decodeQrWithCanvas(source) {
			const sourceWidth = Number(source.videoWidth || source.naturalWidth || source.width || 0);
			const sourceHeight = Number(source.videoHeight || source.naturalHeight || source.height || 0);
			if (!sourceWidth || !sourceHeight) return '';
			if (!this.scanCanvas) this.scanCanvas = document.createElement('canvas');
			const maxSide = 900;
			const scale = Math.min(1, maxSide / Math.max(sourceWidth, sourceHeight));
			this.scanCanvas.width = Math.max(1, Math.round(sourceWidth * scale));
			this.scanCanvas.height = Math.max(1, Math.round(sourceHeight * scale));
			const context = this.scanCanvas.getContext('2d', { willReadFrequently: true });
			context.drawImage(source, 0, 0, this.scanCanvas.width, this.scanCanvas.height);
			const imageData = context.getImageData(0, 0, this.scanCanvas.width, this.scanCanvas.height);
			const result = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'attemptBoth' });
			return result && result.data ? result.data : '';
		},
		loadImage(path) {
			return new Promise((resolve, reject) => {
				const image = new Image();
				image.onload = () => resolve(image);
				image.onerror = reject;
				image.src = path;
			});
		},
		toggleInput() {
			this.inputVisible = !this.inputVisible;
		},
		submitInput() { this.consume(this.input); },
		consume(value) {
			const identityToken = this.extractIdentityToken(value);
			if (identityToken) {
				this.stopCamera();
				this.nativeScannerOpened = false;
				return this.resolveMembershipOperatorContext()
					.then((context) => this.confirmIdentityActivation(identityToken, context))
					.catch(() => this.toast('该码为用户身份码，仅限当前门店的店长或店员核验'));
			}
			if (this.mode === 'membership_activation') return this.toast('不是有效的御方通和用户身份码');
			const target = this.extractTarget(value);
			if (!target.token) return this.toast('不是有效的御方通和推广码、门店码或邀请链接');
			this.stopCamera();
			const url = target.type === 'store_acquisition'
				? `/pages/yfth/store_acquisition/accept?acquisition_token=${target.token}`
				: yfthReferralAcceptRoute(target.token);
			uni.navigateTo({ url });
		},
		isMembershipOperatorContext(context) {
			return ['store_manager', 'store_staff'].indexOf(String((context && context.role_code) || '')) !== -1
				&& Number((context && context.store_id) || 0) > 0;
		},
		resolveMembershipOperatorContext() {
			const cached = currentContext();
			if (this.isMembershipOperatorContext(cached)) {
				this.operatorContext = cached;
				return Promise.resolve(cached);
			}
			return loadYfthIdentities().then((identities) => {
				const operators = (identities || []).filter((item) => this.isMembershipOperatorContext(item));
				const selected = operators.find((item) => {
					return item.role_code === cached.role_code
						&& Number(item.store_id || 0) === Number(cached.store_id || 0);
				}) || operators.sort((left, right) => {
					if (left.role_code !== right.role_code) return left.role_code === 'store_manager' ? -1 : 1;
					return Number(left.store_id || 0) - Number(right.store_id || 0);
				})[0];
				if (!selected) {
					throw new Error('membership_store_operator_forbidden');
				}
				return resolveYfthContext(selected.role_code, selected.store_id);
			}).then((context) => {
				this.operatorContext = context;
				return context;
			});
		},
		confirmIdentityActivation(token, context) {
			this.stopCamera();
			this.nativeScannerOpened = false;
			this.pendingIdentityActivation = { token, context };
			this.activationMessage = '确认该顾客属于当前门店并已完成线下购买，为其开通永久会员？';
			this.activationState = 'confirm';
		},
		cancelIdentityActivation() {
			this.pendingIdentityActivation = null;
			this.activationState = '';
			this.activationMessage = '';
			this.scan();
		},
		submitIdentityActivation() {
			if (this.activationState !== 'confirm' || !this.pendingIdentityActivation) return;
			const pending = this.pendingIdentityActivation;
			this.activationState = 'submitting';
			this.activationMessage = '正在核验顾客归属并开通会员资格。';
			this.activateIdentityMembership(pending.token, pending.context);
		},
		activateIdentityMembership(token, context) {
			activateYfthStorePermanentMembershipIdentity({
				role_code: context.role_code,
				store_id: context.store_id,
				identity_token: token,
				idempotency_key: 'store_identity_activation_' + token
			}).then((response) => {
				this.pendingIdentityActivation = null;
				this.activationState = 'success';
				this.activationMessage = '该顾客已开通永久会员，已有上级会员时奖励将按现有阶梯规则处理。';
				this.clearActivationReturnTimer();
				this.activationReturnTimer = setTimeout(() => this.goUserCenter(), 1800);
				return response;
			}).catch((error) => {
				this.nativeScannerOpened = false;
				this.pendingIdentityActivation = null;
				this.activationState = 'error';
				this.activationMessage = this.identityActivationError(error);
			});
		},
		retryIdentityActivation() {
			this.activationState = '';
			this.activationMessage = '';
			this.scan();
		},
		clearActivationReturnTimer() {
			if (this.activationReturnTimer) clearTimeout(this.activationReturnTimer);
			this.activationReturnTimer = 0;
		},
		goUserCenter() {
			this.clearActivationReturnTimer();
			this.stopCamera();
			this.activationState = '';
			uni.switchTab({
				url: '/pages/user/index',
				fail: () => uni.reLaunch({ url: '/pages/user/index' })
			});
		},
		identityActivationError(error) {
			const raw = String((error && (error.msg || error.message)) || error || '');
			const messages = {
				permanent_membership_already_exists: '该用户已经是永久会员，无需重复开通。',
				offline_membership_store_attribution_mismatch: '该用户未绑定当前门店，不能由本店开通会员。',
				membership_store_operator_forbidden: '当前账号不是店长或店员，无权开通会员。',
				customer_identity_code_invalid: '该身份码已失效，请让用户刷新身份码后重试。',
				customer_identity_code_required: '未读取到有效身份码，请重新扫码。',
				membership_user_not_found: '该用户不存在或账号已注销。'
			};
			const key = Object.keys(messages).find((item) => raw.indexOf(item) !== -1);
			return key ? messages[key] : (raw || '核验失败，请稍后重试。');
		},
		extractIdentityToken(value) {
			let text = String(value || '').trim();
			try { text = decodeURIComponent(text); } catch (e) {}
			if (/^yfthpm_[A-Za-z0-9_-]{24,128}$/.test(text)) return text;
			const match = text.match(/[?&](?:identity_token|token)=((?:yfthpm_)?[A-Za-z0-9_-]{24,128})(?:&|$)/);
			return match ? match[1] : '';
		},
		extractTarget(value) {
			let text = String(value || '').trim();
			try { text = decodeURIComponent(text); } catch (e) {}
			if (/^[a-f0-9]{64}$/i.test(text)) return { type: 'referral', token: text.toLowerCase() };
			const acquisition = /\/pages\/yfth\/store_acquisition\/accept/i.test(text)
				? text.match(/[?&]acquisition_token=([a-f0-9]{64})(?:&|$)/i) : null;
			if (acquisition) return { type: 'store_acquisition', token: acquisition[1].toLowerCase() };
			const referral = /\/pages\/yfth\/referral\/accept/i.test(text)
				? text.match(/[?&]invite_token=([a-f0-9]{64})(?:&|$)/i) : null;
			if (referral) return { type: 'referral', token: referral[1].toLowerCase() };
			const legacyReferral = /\/pages\/yfth\/package_membership\/index/i.test(text)
				? text.match(/[?&]invite_token=([a-f0-9]{64})(?:&|$)/i) : null;
			return legacyReferral ? { type: 'referral', token: legacyReferral[1].toLowerCase() } : { type: '', token: '' };
		},
		goBack() {
			this.stopCamera();
			uni.navigateBack({ delta: 1 });
		},
		stopCamera(resetState = true) {
			this.cameraActive = false;
			if (this.animationFrame && typeof window !== 'undefined') window.cancelAnimationFrame(this.animationFrame);
			this.animationFrame = 0;
			if (this.stream && this.stream.getTracks) this.stream.getTracks().forEach((track) => track.stop());
			this.stream = null;
			this.detecting = false;
			this.lastScanAt = 0;
			if (resetState) this.cameraPending = false;
		},
		toast(title) { uni.showToast({ title, icon: 'none', duration: 2400 }); }
	}
};
</script>

<style scoped>
.scanner-page { position: fixed; inset: 0; width: 100%; max-width: 480px; min-height: 100vh; margin: 0 auto; overflow: hidden; color: #fff; background: #10110f; }
.camera-view { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; background: #10110f; }
.camera-shade { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.42) 0%, rgba(0,0,0,.04) 32%, rgba(0,0,0,.08) 63%, rgba(0,0,0,.65) 100%); pointer-events: none; }
.scanner-header { position: absolute; top: 0; right: 0; left: 0; display: grid; grid-template-columns: 76rpx 1fr 76rpx; align-items: center; padding-right: 28rpx; padding-left: 28rpx; z-index: 3; }
.scanner-title { font-size: 30rpx; font-weight: 600; text-align: center; text-shadow: 0 1px 5px rgba(0,0,0,.45); }
.round-action { display: flex; align-items: center; justify-content: center; width: 70rpx; height: 70rpx; border-radius: 50%; color: #20211f; background: rgba(255,255,255,.94); box-shadow: 0 6rpx 20rpx rgba(0,0,0,.18); }
.round-action .iconfont { font-size: 32rpx; }
.header-spacer { width: 70rpx; height: 70rpx; }
.scan-stage { position: absolute; top: 21%; right: 0; left: 0; display: flex; flex-direction: column; align-items: center; z-index: 2; }
.scan-frame { position: relative; width: 500rpx; max-width: 72vw; aspect-ratio: 1; }
.scan-frame.inactive { opacity: .45; }
.corner { position: absolute; width: 70rpx; height: 70rpx; border-color: #fff; border-style: solid; }
.corner-tl { top: 0; left: 0; border-width: 6rpx 0 0 6rpx; border-radius: 18rpx 0 0; }
.corner-tr { top: 0; right: 0; border-width: 6rpx 6rpx 0 0; border-radius: 0 18rpx 0 0; }
.corner-bl { bottom: 0; left: 0; border-width: 0 0 6rpx 6rpx; border-radius: 0 0 0 18rpx; }
.corner-br { right: 0; bottom: 0; border-width: 0 6rpx 6rpx 0; border-radius: 0 0 18rpx; }
.scan-line { position: absolute; right: 16rpx; left: 16rpx; height: 4rpx; border-radius: 2rpx; background: #36e8a0; box-shadow: 0 0 18rpx 5rpx rgba(54,232,160,.52); animation: scanMove 2.2s ease-in-out infinite; }
.scan-hint { margin-top: 34rpx; font-size: 24rpx; text-shadow: 0 2rpx 8rpx rgba(0,0,0,.6); }
.camera-state { position: absolute; top: 50%; left: 50%; display: flex; width: 430rpx; max-width: 70vw; flex-direction: column; align-items: center; gap: 14rpx; transform: translate(-50%, -50%); text-align: center; }
.state-title { font-size: 29rpx; font-weight: 600; }
.state-copy { color: rgba(255,255,255,.72); font-size: 22rpx; line-height: 1.55; }
.retry-link { margin-top: 8rpx; padding: 14rpx 24rpx; border: 1px solid rgba(255,255,255,.7); border-radius: 32rpx; font-size: 22rpx; }
.scanner-footer { position: absolute; right: 0; bottom: 0; left: 0; display: flex; align-items: flex-end; justify-content: space-between; padding: 28rpx 54rpx; z-index: 3; }
.input-action, .album-action { display: flex; flex-direction: column; align-items: center; gap: 10rpx; color: #fff; font-size: 21rpx; text-shadow: 0 2rpx 7rpx rgba(0,0,0,.55); }
.input-icon, .album-action .iconfont { display: flex; align-items: center; justify-content: center; width: 92rpx; height: 92rpx; border: 1px solid rgba(255,255,255,.48); border-radius: 50%; background: rgba(28,28,27,.5); font-size: 38rpx; backdrop-filter: blur(8px); }
.album-action .iconfont { font-size: 40rpx; }
.input-mask { position: absolute; inset: 0; display: flex; align-items: flex-end; background: rgba(0,0,0,.54); z-index: 8; }
.input-sheet { width: 100%; padding: 20rpx 30rpx calc(28rpx + env(safe-area-inset-bottom)); border-radius: 24rpx 24rpx 0 0; box-sizing: border-box; color: #31291f; background: #fff; }
.sheet-handle { width: 72rpx; height: 7rpx; margin: 0 auto 24rpx; border-radius: 4rpx; background: #d8d3cc; }
.sheet-title { margin-bottom: 18rpx; font-size: 29rpx; font-weight: 650; }
.input-sheet textarea { width: 100%; height: 150rpx; padding: 18rpx; border: 1px solid #e3dbcf; border-radius: 10rpx; box-sizing: border-box; font-size: 24rpx; background: #faf8f4; }
.input-sheet button { width: 100%; height: 78rpx; margin: 18rpx 0 0; border-radius: 10rpx; font-size: 26rpx; line-height: 78rpx; }
.submit-button { color: #fff; background: #9b713b; }
.cancel-button { color: #72685c; background: #f2eee8; }
.activation-mask { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 38rpx; box-sizing: border-box; background: rgba(10,10,9,.72); backdrop-filter: blur(8px); z-index: 12; }
.activation-card { display: flex; width: 100%; max-width: 620rpx; flex-direction: column; align-items: center; padding: 46rpx 34rpx 34rpx; border-radius: 22rpx; box-sizing: border-box; color: #2d261e; background: #fff; box-shadow: 0 24rpx 70rpx rgba(0,0,0,.3); }
.activation-icon { display: flex; align-items: center; justify-content: center; width: 94rpx; height: 94rpx; margin-bottom: 24rpx; border-radius: 50%; color: #fff; background: #a67c45; font-size: 44rpx; font-weight: 700; }
.activation-icon.is-success { background: #4f8b62; }
.activation-icon.is-error { background: #b65348; }
.activation-title { font-size: 34rpx; font-weight: 700; }
.activation-copy { margin-top: 18rpx; color: #74685a; font-size: 24rpx; line-height: 1.7; text-align: center; }
.activation-actions { display: grid; width: 100%; grid-template-columns: 1fr 1fr; gap: 18rpx; margin-top: 34rpx; }
.activation-actions.single { grid-template-columns: 1fr; }
.activation-actions button { height: 82rpx; margin: 0; border-radius: 10rpx; font-size: 26rpx; line-height: 82rpx; }
.activation-primary { color: #fff; background: #9b713b; }
.activation-secondary { color: #6e6254; background: #f2eee8; }
.activation-loading { display: flex; align-items: center; gap: 14rpx; margin-top: 34rpx; color: #7c7062; font-size: 23rpx; }
.loading-dot { width: 20rpx; height: 20rpx; border-radius: 50%; background: #a67c45; animation: loadingPulse 1s ease-in-out infinite; }
@keyframes scanMove { 0%, 100% { top: 10%; opacity: .6; } 50% { top: 88%; opacity: 1; } }
@keyframes loadingPulse { 0%, 100% { opacity: .35; transform: scale(.82); } 50% { opacity: 1; transform: scale(1); } }
/* #ifdef H5 */
@media screen and (min-width: 768px) {
	.scanner-page { right: auto; left: 50%; transform: translateX(-50%); box-shadow: 0 0 45px rgba(0,0,0,.2); }
}
/* #endif */
</style>
