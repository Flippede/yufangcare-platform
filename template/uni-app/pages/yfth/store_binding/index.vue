<template>
	<view class="page">
		<view class="card">
			<view class="title">绑定门店</view>
			<view v-if="storeId" class="store">门店 ID：{{ storeId }}</view>
			<view v-else class="error">门店码无效，请重新扫描。</view>
			<view class="notice">绑定后，该门店将成为你当前的归属门店。如已绑定其他门店，系统会拒绝覆盖。</view>
			<button class="primary" :disabled="!storeId || submitting" @click="bindStore">
				{{ submitting ? '正在绑定...' : '确认绑定' }}
			</button>
		</view>
	</view>
</template>

<script>
import { bindYfthStoreFromQr } from '@/api/yfth.js';

export default {
	data() {
		return { storeId: 0, submitting: false };
	},
	onLoad(options) {
		const value = String((options && options.store_id) || '');
		this.storeId = /^[1-9][0-9]*$/.test(value) ? Number(value) : 0;
	},
	methods: {
		bindStore() {
			if (!this.storeId || this.submitting) return;
			const operation = `store-qr-bind-${this.storeId}-${Date.now()}`;
			this.submitting = true;
			bindYfthStoreFromQr({
				store_id: this.storeId,
				idempotency_key: operation,
				request_id: operation
			}).then(() => {
				uni.showToast({ title: '门店绑定成功', icon: 'success' });
				setTimeout(() => {
					uni.redirectTo({ url: '/pages/yfth/package_membership/index' });
				}, 800);
			}).finally(() => { this.submitting = false; });
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 36rpx 24rpx; box-sizing: border-box; background: #f5f4f1; }
.card { padding: 42rpx 32rpx; border-radius: 14rpx; background: #fff; }
.title { color: #28584f; font-size: 40rpx; font-weight: 700; }
.store { margin-top: 28rpx; font-size: 30rpx; font-weight: 600; }
.notice { margin-top: 24rpx; color: #77746e; font-size: 25rpx; line-height: 1.65; }
.error { margin-top: 28rpx; color: #a23d34; }
.primary { width: 100%; margin-top: 38rpx; color: #fff; background: #28584f; }
.primary[disabled] { opacity: .45; }
</style>
