<template>
	<view class="page">
		<view class="panel">
			<view class="state" :class="{ failed: isFailed }">{{ stateTitle }}</view>
			<view class="sub">{{ stateDescription }}</view>
			<view class="order" v-if="status.order_sn">订单号 {{ status.order_sn }}</view>
			<view class="error" v-if="isFailed && status.last_activation_error">{{ status.last_activation_error }}</view>
		</view>
		<button class="btn" :disabled="loading" @click="refresh">{{ loading ? '刷新中' : '刷新状态' }}</button>
		<button class="ghost" @click="goTarget">{{ isSuccess ? '查看套餐详情' : '查看我的套餐' }}</button>
	</view>
</template>

<script>
import { getYfthPurchaseStatus } from '@/api/yfth.js';

export default {
	data() {
		return {
			purchaseNo: '',
			status: {},
			loading: false,
			timer: null,
			pollCount: 0
		};
	},
	computed: {
		isSuccess() {
			return this.status.activation_status === 'succeeded' && Number(this.status.instance_id || 0) > 0;
		},
		isFailed() {
			return this.status.activation_status === 'failed';
		},
		stateTitle() {
			if (this.isSuccess) return '套餐已激活';
			if (this.isFailed) return '激活失败';
			return '支付结果确认中';
		},
		stateDescription() {
			if (this.isSuccess) return '十个月权益计划已生成';
			if (this.isFailed) return '系统已记录失败原因，可稍后刷新或联系客服处理';
			return '正在确认支付结果并激活套餐';
		}
	},
	onLoad(options) {
		this.purchaseNo = options.purchase_no || '';
		this.refresh();
	},
	onUnload() {
		this.clearTimer();
	},
	methods: {
		refresh() {
			if (!this.purchaseNo || this.loading) return;
			this.loading = true;
			getYfthPurchaseStatus(this.purchaseNo)
				.then((res) => {
					this.status = res.data || {};
					this.schedulePoll();
				})
				.finally(() => {
					this.loading = false;
				});
		},
		schedulePoll() {
			this.clearTimer();
			if (this.isSuccess || this.isFailed || this.pollCount >= 10) return;
			this.pollCount += 1;
			this.timer = setTimeout(() => {
				this.refresh();
			}, 3000);
		},
		clearTimer() {
			if (this.timer) {
				clearTimeout(this.timer);
				this.timer = null;
			}
		},
		goTarget() {
			if (this.isSuccess) {
				uni.navigateTo({ url: '/pages/yfth/package/package_detail?id=' + this.status.instance_id });
				return;
			}
			uni.navigateTo({ url: '/pages/yfth/package/my_packages' });
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	padding: 32rpx;
	background: #f5f7f8;
}
.panel {
	text-align: center;
	background: #fff;
	border-radius: 12rpx;
	padding: 60rpx 24rpx;
}
.state {
	font-size: 44rpx;
	font-weight: 700;
	color: #2f7668;
}
.state.failed {
	color: #b9413c;
}
.sub,
.order,
.error {
	margin-top: 16rpx;
	color: #65717c;
	line-height: 1.6;
}
.error {
	color: #b9413c;
	word-break: break-all;
}
.btn,
.ghost {
	margin-top: 24rpx;
	height: 84rpx;
	line-height: 84rpx;
	border-radius: 10rpx;
}
.btn {
	color: #fff;
	background: #2f7668;
}
.btn[disabled] {
	background: #9fb9b4;
	color: #fff;
}
.ghost {
	color: #2f7668;
	background: #eef7f5;
}
</style>
