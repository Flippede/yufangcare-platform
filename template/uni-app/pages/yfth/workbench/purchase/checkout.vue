<template>
	<view class="page">
		<view class="hero">
			<text>采购结算</text>
			<small>下一步使用商城原生订单、支付、配送与售后流程</small>
		</view>

		<view class="panel">
			<view v-for="item in cart" :key="item.key" class="goods">
				<image :src="item.product_image" mode="aspectFill"></image>
				<view class="goods-info">
					<b>{{ item.product_name }}</b>
					<span>{{ item.sku_name || '默认规格' }}</span>
					<small>¥{{ item.purchase_price }} × {{ item.quantity }}</small>
				</view>
			</view>
			<view v-if="!cart.length" class="empty">采购车为空</view>
		</view>

		<view class="notice">
			采购价格将在创建订单时形成不可修改的快照。收货地址、运费、支付方式、物流和售后均使用商城现有能力。
		</view>

		<view class="bottom">
			<view>
				<small>采购商品合计</small>
				<b>¥{{ goodsTotal }}</b>
			</view>
			<button :disabled="submitting || !cart.length" @click="submit">
				{{ submitting ? '准备中' : '去确认订单' }}
			</button>
		</view>
	</view>
</template>

<script>
import { prepareYfthNativeProcurementCheckout } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			context: {},
			cart: [],
			submitting: false
		};
	},
	computed: {
		goodsTotal() {
			return this.cart.reduce((sum, item) => sum + Number(item.purchase_price) * Number(item.quantity), 0).toFixed(2);
		}
	},
	onShow() {
		this.context = currentContext();
		this.cart = uni.getStorageSync(this.key()) || [];
	},
	methods: {
		key() {
			return 'YFTH_PURCHASE_CART_' + Number(this.context.store_id || 0);
		},
		submit() {
			if (this.submitting || !this.cart.length) return;
			this.submitting = true;
			prepareYfthNativeProcurementCheckout({
				role_code: this.context.role_code,
				store_id: this.context.store_id,
				items: this.cart.map((item) => ({
					product_id: item.product_id,
					sku_unique: item.sku_unique,
					quantity: Number(item.quantity)
				}))
			}).then((res) => {
				const data = (res && res.data) || {};
				if (!data.order_confirm_url) {
					throw new Error('采购结算入口生成失败');
				}
				uni.removeStorageSync(this.key());
				uni.navigateTo({ url: data.order_confirm_url });
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err.message || err), icon: 'none' });
			}).finally(() => {
				this.submitting = false;
			});
		}
	}
};
</script>

<style scoped>
.page{min-height:100vh;background:#f5f1e9;padding:20rpx 20rpx 160rpx}.hero,.panel,.notice{margin-bottom:16rpx;border-radius:8rpx}.hero{padding:28rpx;background:#6f5134;color:#fff}.hero text,.hero small{display:block}.hero text{font-size:36rpx;font-weight:700}.hero small{margin-top:10rpx;opacity:.88;font-size:22rpx}.panel{padding:20rpx;background:#fff}.goods{display:flex;gap:18rpx;padding:16rpx 0;border-bottom:1rpx solid #eee}.goods:last-child{border-bottom:0}.goods image{width:140rpx;height:140rpx;border-radius:6rpx;background:#eee}.goods-info{flex:1}.goods b,.goods span,.goods small{display:block}.goods span{margin-top:8rpx;color:#8c8177;font-size:22rpx}.goods small{margin-top:16rpx;color:#a66d30}.empty{padding:80rpx 0;text-align:center;color:#968b80}.notice{padding:22rpx;color:#765f47;background:#fff9ec;border:1rpx solid #e4ca9f;font-size:22rpx;line-height:1.7}.bottom{position:fixed;z-index:20;left:0;right:0;bottom:0;display:flex;justify-content:space-between;align-items:center;padding:16rpx 20rpx calc(16rpx + env(safe-area-inset-bottom));background:#fff}.bottom small,.bottom b{display:block}.bottom b{color:#a66d30;font-size:34rpx}.bottom button{margin:0;width:330rpx;background:#7a5733;color:#fff;border-radius:6rpx}.bottom button[disabled]{opacity:.5}
</style>
