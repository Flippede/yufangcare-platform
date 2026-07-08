<template>
	<view class="page">
		<view class="panel">
			<view class="title">合同确认</view>
			<view class="line">合同号：{{ contract.contract_no || '-' }}</view>
			<view class="line">金额：{{ contract.amount_snapshot || '0.00' }}</view>
			<view class="line">状态：{{ contract.status_text || '-' }}</view>
			<button v-if="contract.status === 'pending_user_confirm'" @click="confirm">确认合同</button>
		</view>
	</view>
</template>

<script>
import { confirmYfthFranchiseOpeningContract, getYfthFranchiseOpeningContract } from '@/api/yfth.js';

export default {
	data() { return { id: 0, contract: {} }; },
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	methods: {
		load() {
			if (!this.id) return;
			getYfthFranchiseOpeningContract(this.id).then((res) => { this.contract = (res.data && res.data.contract) || {}; });
		},
		confirm() {
			confirmYfthFranchiseOpeningContract(this.id).then(() => {
				uni.showToast({ title: '已确认', icon: 'success' });
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
.line { color: #5f5148; font-size: 26rpx; margin-top: 14rpx; }
button { margin-top: 26rpx; background: #7b4e25; color: #fff; border-radius: 12rpx; }
</style>
