<template>
	<view class="page">
		<view class="section">
			<view class="title">{{ preview.rule.agreement_title || '套餐服务协议' }}</view>
			<view class="summary">{{ preview.rule.agreement_content_summary }}</view>
			<view v-if="simulation" class="simulation-box">
				<view class="simulation-title">0.1元模拟购买</view>
				<view>上级商家：{{ simulationContext.store ? simulationContext.store.store_name : '读取中' }}</view>
				<view>{{ simulationContext.notice || '本流程不会发起真实支付。' }}</view>
			</view>
		</view>
		<label class="agree">
			<checkbox :checked="accepted" @click="accepted = !accepted" />
			<text>我已阅读并同意套餐服务协议</text>
		</label>
		<button class="btn" :disabled="!accepted || (simulation && !simulationContext.can_simulate)" @click="next">继续</button>
	</view>
</template>

<script>
import { getYfthPackageRulePreview, getYfthPackageSimulationContext } from '@/api/yfth.js';

export default {
	data() {
		return { id: 0, storeId: 0, simulation: false, accepted: false, preview: { rule: {} }, simulationContext: {} };
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.storeId = Number(options.store_id || 0);
		this.simulation = String(options.simulation || '') === '1';
		getYfthPackageRulePreview(this.id).then((res) => {
			this.preview = res.data || { rule: {} };
		});
		if (this.simulation) {
			getYfthPackageSimulationContext(this.id).then((res) => {
				this.simulationContext = res.data || {};
				if (this.simulationContext.store) this.storeId = Number(this.simulationContext.store.store_id || 0);
			}).catch((err) => {
				this.$util.Tips({ title: (err && (err.msg || err.message)) || String(err || '无法确认上级商家') });
			});
		}
	},
	methods: {
		next() {
			uni.navigateTo({
					url: '/pages/yfth/package/payment_confirm?id=' + this.id + '&store_id=' + this.storeId
						+ '&accepted=1' + (this.simulation ? '&simulation=1' : '')
			});
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	padding: 28rpx;
	background: #f5f7f8;
}
.section {
	background: #fff;
	border-radius: 12rpx;
	padding: 28rpx;
}
.title {
	font-size: 34rpx;
	font-weight: 700;
	margin-bottom: 18rpx;
}
.summary {
	font-size: 28rpx;
	color: #4d5963;
	line-height: 1.7;
}
.simulation-box {
	margin-top: 24rpx;
	padding: 22rpx;
	border: 1px solid #ead6b5;
	border-radius: 10rpx;
	color: #78552d;
	background: #fff8ea;
	font-size: 25rpx;
	line-height: 1.7;
}
.simulation-title {
	font-size: 30rpx;
	font-weight: 700;
}
.agree {
	display: flex;
	align-items: center;
	gap: 12rpx;
	margin: 28rpx 0;
	color: #1f2933;
}
.btn {
	height: 86rpx;
	line-height: 86rpx;
	background: #2f7668;
	color: #fff;
	border-radius: 10rpx;
}
</style>
