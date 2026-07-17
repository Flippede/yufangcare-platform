<template>
	<view class="page">
		<view class="hero">
			<view class="eyebrow">{{ context.role_name_cn || '门店员工' }}</view>
			<view class="title">我的门店获客码</view>
			<view class="subtitle">顾客扫码后绑定 {{ context.store_name || '当前门店' }}</view>
		</view>
		<view v-if="loading" class="state">正在生成专属码...</view>
		<view v-else-if="error" class="state error">{{ error }}<button @click="issue">重新生成</button></view>
		<view v-else class="panel">
			<view class="qr-wrap"><zb-code ref="qrcode" cid="yfth-store-acquisition-qr" :val="codeLink" :size="390" :onval="true" :loadMake="true" foreground="#765127" @result="onQrReady" /></view>
			<view class="store-name">{{ code.store_name }}</view>
			<view class="issuer">来源：{{ code.issuer_role_name }} {{ code.issuer_name || '门店员工' }}</view>
			<view class="actions">
				<button @click="copyLink">复制链接</button>
				<button @click="saveQr">保存二维码</button>
				<button class="primary" @click="issue">刷新专属码</button>
			</view>
			<button class="share" open-type="share">分享给顾客</button>
		</view>
		<view class="notice">该码只记录顾客的门店归属和来源员工，不建立会员一级推荐关系，也不会产生推荐奖励。员工身份撤销、门店停用或刷新专属码后，旧码立即失效。</view>
	</view>
</template>

<script>
import zbCode from '@/components/zb-code/zb-code.vue';
import { loadYfthIdentities, resolveDominantYfthContext, resolveYfthContext } from '@/libs/yfthContext.js';
import { getYfthStoreAcquisitionCode, issueYfthStoreAcquisitionCode } from '@/api/yfth.js';

export default {
	components: { zbCode },
	data() { return { context: {}, code: {}, loading: true, error: '', qrImage: '' }; },
	computed: {
		codeLink() {
			if (!this.code.acquisition_token) return '';
			// The public QR must always land on the dedicated acquisition page. Mini Program
			// URL Links may still point at an older published bundle before a WeChat release.
			if (this.code.h5_launch_url) return this.code.h5_launch_url;
			const path = `/pages/yfth/store_acquisition/accept?acquisition_token=${this.code.acquisition_token}`;
			// #ifdef H5
			return `${window.location.origin}${path}`;
			// #endif
			// #ifndef H5
			return path;
			// #endif
		}
	},
	onLoad(options) {
		this.resolveTrustedContext(options || {});
	},
	onShareAppMessage() { return { title: `邀请你绑定${this.code.store_name || '御方通和门店'}`, path: this.code.launch_path || this.codeLink }; },
	methods: {
		resolveTrustedContext(options) {
			const roleCode = String(options.role_code || '');
			const storeId = Number(options.store_id || 0);
			const resolve = ['store_manager', 'store_staff'].indexOf(roleCode) !== -1 && storeId > 0
				? resolveYfthContext(roleCode, storeId)
				: loadYfthIdentities().then((identities) => resolveDominantYfthContext(identities));
			resolve.then((context) => {
				if (['store_manager', 'store_staff'].indexOf(context.role_code) === -1 || !context.store_id) {
					throw new Error('请先切换到店长或店员身份');
				}
				this.context = context;
				this.restoreOrIssue();
			}).catch((err) => {
				this.loading = false;
				this.error = (err && (err.msg || err.message)) || '门店身份核验失败';
			});
		},
		storageKey() {
			return `YFTH_STORE_ACQUISITION_CODE_${this.context.uid || 0}_${this.context.store_id}_${this.context.role_code}`;
		},
		restoreOrIssue() {
			const cached = uni.getStorageSync(this.storageKey());
			const now = Math.floor(Date.now() / 1000);
			const cacheUsable = cached && cached.acquisition_token && cached.h5_launch_url
				&& Number(cached.expires_at || 0) > now;
			getYfthStoreAcquisitionCode({ role_code: this.context.role_code, store_id: this.context.store_id })
				.then((res) => {
					const active = (res.data && res.data.active_code) || null;
					if (cacheUsable && active && active.code_no === cached.code_no) {
						this.code = cached;
						this.loading = false;
						return;
					}
					this.issue();
				})
				.catch(() => this.issue());
		},
		issue() {
			this.loading = true; this.error = '';
			return issueYfthStoreAcquisitionCode({ role_code: this.context.role_code, store_id: this.context.store_id, request_id: `acquisition-code-${Date.now()}` })
				.then((res) => {
					this.code = res.data || {};
					if (this.code.acquisition_token) uni.setStorageSync(this.storageKey(), this.code);
				})
				.catch((err) => { this.error = (err && (err.msg || err.message)) || '专属码生成失败'; })
				.finally(() => { this.loading = false; });
		},
		copyLink() { if (this.codeLink) uni.setClipboardData({ data: this.codeLink }); },
		onQrReady(value) { this.qrImage = String(value || ''); },
		saveQr() {
			if (!this.qrImage) return uni.showToast({ title: '二维码尚未生成', icon: 'none' });
			// #ifdef H5
			const anchor = document.createElement('a'); anchor.href = this.qrImage; anchor.download = `门店获客码-${Date.now()}.png`;
			document.body.appendChild(anchor); anchor.click(); document.body.removeChild(anchor);
			// #endif
			// #ifndef H5
			if (this.$refs.qrcode && this.$refs.qrcode._saveCode) this.$refs.qrcode._saveCode();
			// #endif
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; box-sizing: border-box; color: #342b21; background: #f5f1ea; }
.hero { padding: 40rpx 34rpx; border-radius: 14rpx; color: #fff; background: #9b713b; }.eyebrow { font-size: 23rpx; opacity: .82; }.title { margin-top: 8rpx; font-size: 42rpx; font-weight: 700; }.subtitle { margin-top: 10rpx; font-size: 24rpx; opacity: .9; }
.panel { margin-top: 20rpx; padding: 30rpx; border-radius: 14rpx; text-align: center; background: #fff; }.qr-wrap { display: flex; justify-content: center; padding: 18rpx 0; }.store-name { font-size: 31rpx; font-weight: 700; }.issuer { margin-top: 8rpx; color: #8a7c6b; font-size: 23rpx; }
.actions { display: flex; gap: 12rpx; margin-top: 24rpx; }.actions button { flex: 1; font-size: 23rpx; }.primary { color: #fff; background: #9b713b; }.share { margin-top: 14rpx; color: #765127; background: #f7efe2; }
.notice { margin-top: 20rpx; padding: 22rpx; border: 1px solid #e5d4b8; border-radius: 12rpx; color: #765f42; font-size: 23rpx; line-height: 1.6; background: #fffaf1; }.state { padding: 150rpx 20rpx; text-align: center; color: #766b5e; }.state button { margin-top: 22rpx; }.error { color: #a34b42; }
</style>
