<template>
	<view class="page">
		<view class="panel">
			<view class="title">付款凭证</view>
			<view class="line">状态：{{ payment.status_text || '-' }}</view>
			<view class="line">金额：{{ payment.amount_snapshot || '0.00' }}</view>
			<input v-model="attachmentIds" placeholder="附件ID，多个用英文逗号分隔" />
			<input v-model="amount" placeholder="付款金额" />
			<button @click="submit">上传凭证</button>
			<view v-if="payment.reject_reason" class="warn">驳回原因：{{ payment.reject_reason }}</view>
		</view>
	</view>
</template>

<script>
import { getYfthFranchiseOpening, uploadYfthFranchisePaymentProof } from '@/api/yfth.js';

export default {
	data() { return { id: 0, payment: {}, attachmentIds: '', amount: '' }; },
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	methods: {
		load() {
			getYfthFranchiseOpening().then((res) => {
				this.payment = (res.data && res.data.payment) || {};
				if (!this.id) this.id = this.payment.id || 0;
				this.amount = this.payment.amount_snapshot || '';
			});
		},
		submit() {
			if (!this.id) return;
			uploadYfthFranchisePaymentProof(this.id, { attachment_ids: this.attachmentIds, amount_snapshot: this.amount }).then(() => {
				uni.showToast({ title: '已上传', icon: 'success' });
				this.load();
			});
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.panel { background: #fff; border-radius: 18rpx; padding: 26rpx; box-shadow: 0 10rpx 26rpx rgba(70,45,30,.06); }
.title { font-size: 34rpx; font-weight: 700; color: #2d2434; margin-bottom: 20rpx; }
.line, .warn { color: #5f5148; font-size: 26rpx; margin-top: 14rpx; }
.warn { color: #b45a2c; }
input { margin-top: 18rpx; background: #fff7e9; border-radius: 12rpx; padding: 18rpx; font-size: 26rpx; }
button { margin-top: 26rpx; background: #7b4e25; color: #fff; border-radius: 12rpx; }
</style>
