<template>
	<view class="page">
		<view class="panel">
			<view class="row"><text>套餐</text><text>{{ detail.package_name }}</text></view>
			<view class="row"><text>价格</text><text>¥{{ price }}</text></view>
			<view class="row"><text>权益周期</text><text>{{ monthCount }}个月</text></view>
			<view class="row"><text>服务门店</text><text>{{ storeId }}</text></view>
		</view>
		<view class="panel">
			<view class="label">CRMEB订单号</view>
			<input v-model="orderSn" class="input" placeholder="请先通过商城订单流程创建订单" />
			<view class="label">商品ID</view>
			<input v-model="productId" class="input" placeholder="绑定的套餐商品ID" />
			<view class="label">SKU unique</view>
			<input v-model="skuUnique" class="input" placeholder="绑定的SKU unique" />
		</view>
		<button class="btn" @click="createPurchase">确认并绑定订单</button>
	</view>
</template>

<script>
import { createYfthPackagePurchase, getYfthPackageDetail, getYfthPackageRulePreview } from '@/api/yfth.js';

export default {
	data() {
		return {
			id: 0,
			storeId: 0,
			detail: {},
			preview: { rule: {} },
			orderSn: '',
			productId: '',
			skuUnique: ''
		};
	},
	computed: {
		price() {
			return this.preview.rule.package_price || '0.00';
		},
		monthCount() {
			return this.preview.rule.month_count || 0;
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.storeId = Number(options.store_id || 0);
		getYfthPackageDetail(this.id).then((res) => {
			this.detail = res.data || {};
			const binding = (this.detail.bindings || [])[0] || {};
			this.productId = binding.product_id || '';
			this.skuUnique = binding.product_attr_unique || '';
		});
		getYfthPackageRulePreview(this.id).then((res) => {
			this.preview = res.data || { rule: {} };
		});
	},
	methods: {
		createPurchase() {
			createYfthPackagePurchase({
				template_id: this.id,
				store_id: this.storeId,
				product_id: this.productId,
				product_attr_unique: this.skuUnique,
				rule_version_id: this.preview.rule.id,
				client_price: this.price,
				client_month_count: this.monthCount,
				client_benefit_hash: this.preview.benefit_hash,
				order_sn: this.orderSn,
				agreement_accepted: 1,
				source: 'mobile'
			}).then((res) => {
				uni.navigateTo({
					url: '/pages/yfth/package/payment_result?purchase_no=' + res.data.purchase_no
				});
			});
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	padding: 24rpx;
	background: #f5f7f8;
}
.panel {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 20rpx;
}
.row {
	display: flex;
	justify-content: space-between;
	padding: 16rpx 0;
	border-bottom: 1px solid #edf0f2;
}
.label {
	font-size: 26rpx;
	color: #58636f;
	margin: 18rpx 0 10rpx;
}
.input {
	height: 76rpx;
	background: #f4f6f7;
	border-radius: 8rpx;
	padding: 0 18rpx;
}
.btn {
	margin-top: 16rpx;
	height: 86rpx;
	line-height: 86rpx;
	background: #2f7668;
	color: #fff;
	border-radius: 10rpx;
}
</style>
