<template>
	<view class="page">
		<view class="hero">
			<view class="scan-icon">扫</view>
			<view><view class="title">扫一扫推广码</view><view class="subtitle">仅识别御方通和一级会员邀请</view></view>
		</view>
		<view class="panel">
			<button class="primary" @click="scan">打开摄像头扫码</button>
			<!-- #ifdef H5 -->
			<video id="yfth-referral-camera" v-show="cameraActive" class="camera" autoplay muted playsinline></video>
			<view v-if="cameraActive" class="camera-tip">将推广二维码放入画面中央，识别成功后会自动进入邀请确认</view>
			<button class="secondary" @click="chooseQrImage">上传二维码图片</button>
			<!-- #endif -->
			<view class="divider"><text>或</text></view>
			<textarea v-model.trim="input" maxlength="1024" placeholder="粘贴邀请链接或 64 位推广码" />
			<button class="secondary" @click="submitInput">识别邀请</button>
		</view>
		<view class="notice">只接受本站推广链接和御方通和推广码。登录状态不会写入二维码，未登录用户会先完成登录，再自动继续邀请确认。</view>
	</view>
</template>

<script>
// #ifdef H5
import jsQR from 'jsqr';
// #endif

export default {
	data() {
		return { input: '', cameraActive: false, stream: null, detector: null, detecting: false, animationFrame: 0, scanCanvas: null, lastScanAt: 0 };
	},
	onUnload() { this.stopCamera(); },
	onHide() { this.stopCamera(); },
	methods: {
		scan() {
			// #ifdef MP-WEIXIN
			uni.scanCode({ onlyFromCamera: true, scanType: ['qrCode'], success: (res) => this.consume(res.result), fail: (err) => {
				if (!String((err && err.errMsg) || '').includes('cancel')) this.toast('扫码失败，请重试');
			} });
			return;
			// #endif
			// #ifdef H5
			this.startH5Camera();
			return;
			// #endif
			this.toast('当前端不支持摄像头扫码，请粘贴邀请链接');
		},
		startH5Camera() {
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
				this.toast('当前浏览器无法调用摄像头，请检查 HTTPS 和摄像头权限，或上传二维码图片');
				return;
			}
			this.stopCamera();
			this.detector = window.BarcodeDetector ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;
			navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then((stream) => {
				this.stream = stream;
				this.cameraActive = true;
				this.$nextTick(() => {
					const root = document.getElementById('yfth-referral-camera');
					const video = root && root.tagName === 'VIDEO' ? root : (root && root.querySelector ? root.querySelector('video') : null);
					if (!video) throw new Error('camera_element_unavailable');
					video.srcObject = stream;
					video.play().then(() => this.detectLoop(video));
				});
			}).catch(() => {
				this.stopCamera();
				this.toast('无法打开摄像头，请检查权限或改用图片/链接');
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
			uni.chooseImage({ count: 1, sourceType: ['album'], success: (res) => {
				const path = res.tempFilePaths && res.tempFilePaths[0];
				if (!path) return this.toast('未选择二维码图片');
				this.loadImage(path).then((image) => this.decodeQrSource(image)).then((value) => {
						if (!value) throw new Error('qr_not_found');
						this.consume(value);
					}).catch(() => this.toast('图片中未识别到有效推广二维码'));
			} });
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
		submitInput() { this.consume(this.input); },
		consume(value) {
			const token = this.extractToken(value);
			if (!token) return this.toast('不是有效的御方通和推广码或邀请链接');
			uni.navigateTo({ url: `/pages/yfth/referral/accept?invite_token=${token}` });
		},
		extractToken(value) {
			let text = String(value || '').trim();
			try { text = decodeURIComponent(text); } catch (e) {}
			if (/^[a-f0-9]{64}$/i.test(text)) return text.toLowerCase();
			if (!/^https?:\/\//i.test(text) && text.indexOf('/pages/yfth/referral/accept') !== 0) return '';
			const match = text.match(/[?&]invite_token=([a-f0-9]{64})(?:&|$)/i);
			return match ? match[1].toLowerCase() : '';
		},
		stopCamera() {
			this.cameraActive = false;
			if (this.animationFrame && typeof window !== 'undefined') window.cancelAnimationFrame(this.animationFrame);
			this.animationFrame = 0;
			if (this.stream && this.stream.getTracks) this.stream.getTracks().forEach((track) => track.stop());
			this.stream = null;
			this.detecting = false;
			this.lastScanAt = 0;
		},
		toast(title) { uni.showToast({ title, icon: 'none', duration: 2400 }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 28rpx; box-sizing: border-box; color: #34291e; background: #f5f2ec; }
.hero { display: flex; align-items: center; gap: 22rpx; padding: 36rpx 30rpx; border-radius: 14rpx; color: #fff; background: #9b713b; }
.scan-icon { width: 78rpx; height: 78rpx; border: 1rpx solid rgba(255,255,255,.55); border-radius: 22rpx; font-size: 34rpx; line-height: 78rpx; text-align: center; }
.title { font-size: 36rpx; font-weight: 700; }.subtitle { margin-top: 7rpx; font-size: 23rpx; opacity: .82; }
.panel { margin-top: 22rpx; padding: 28rpx; border-radius: 14rpx; background: #fff; }
button { width: 100%; margin: 0; border-radius: 10rpx; font-size: 27rpx; }.primary { color: #fff; background: #9b713b; }.secondary { margin-top: 18rpx; color: #765126; background: #f6eddf; }
.camera { width: 100%; height: 420rpx; margin-top: 20rpx; border-radius: 10rpx; background: #191919; }
.camera-tip { margin-top: 12rpx; color: #8b8276; font-size: 22rpx; line-height: 1.5; text-align: center; }
.divider { position: relative; margin: 28rpx 0; color: #a1988c; font-size: 22rpx; text-align: center; }.divider::before { position: absolute; top: 50%; left: 0; width: 100%; height: 1px; background: #eee8df; content: ''; }.divider text { position: relative; padding: 0 18rpx; background: #fff; }
textarea { width: 100%; height: 150rpx; padding: 18rpx; box-sizing: border-box; border: 1px solid #e5ded4; border-radius: 10rpx; font-size: 24rpx; background: #fffdfa; }
.notice { margin-top: 20rpx; padding: 22rpx; border: 1px solid #eadbc3; border-radius: 10rpx; color: #7b603d; background: #fbf6ed; font-size: 22rpx; line-height: 1.6; }
</style>
