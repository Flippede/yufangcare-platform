<template>
	<view class="page">
		<view v-if="!cart.length" class="empty">
			<view>采购车还是空的</view>
			<button @click="backMall">去选商品</button>
		</view>
		<view v-for="(item, index) in cart" :key="item.key" class="item">
			<image :src="item.product_image" mode="aspectFill"></image>
			<view class="body">
				<b>{{ item.product_name }}</b>
				<span>{{ item.sku_name || '默认规格' }}</span>
				<view class="line">
					<strong>¥{{ item.purchase_price }}</strong>
					<view class="qty">
						<button @click="change(index, -item.multiple)">-</button>
						<input v-model.number="item.quantity" @blur="normalize(index)" />
						<button @click="change(index, item.multiple)">+</button>
					</view>
				</view>
				<view class="delete" @click="remove(index)">删除</view>
			</view>
		</view>
		<view v-if="cart.length" class="bottom">
			<view><span>合计</span><b>¥{{ total }}</b></view>
			<button @click="checkout">去结算（{{ count }}）</button>
		</view>
	</view>
</template>

<script>
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return { context: {}, cart: [] };
	},
	computed: {
		total() {
			return this.cart.reduce((sum, item) => sum + Number(item.purchase_price) * Number(item.quantity), 0).toFixed(2);
		},
		count() {
			return this.cart.reduce((sum, item) => sum + Number(item.quantity), 0);
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
		save() {
			uni.setStorageSync(this.key(), this.cart);
		},
		change(index, delta) {
			this.cart[index].quantity = Number(this.cart[index].quantity || 0) + Number(delta || 1);
			this.normalize(index);
		},
		normalize(index) {
			const row = this.cart[index];
			const min = Math.max(1, Number(row.min_quantity || 1));
			const multiple = Math.max(1, Number(row.multiple || 1));
			const value = Math.max(min, Number(row.quantity || min));
			row.quantity = Math.max(min, Math.ceil((value - min) / multiple) * multiple + min);
			this.save();
		},
		remove(index) {
			this.cart.splice(index, 1);
			this.save();
		},
		backMall() {
			uni.navigateBack();
		},
		checkout() {
			this.cart.forEach((item, index) => this.normalize(index));
			this.save();
			uni.navigateTo({ url: '/pages/yfth/workbench/purchase/checkout' });
		}
	}
};
</script>

<style scoped>
.page{min-height:100vh;background:#f5f1e9;padding:20rpx 20rpx 130rpx}.item{display:flex;gap:20rpx;margin-bottom:16rpx;padding:20rpx;background:#fff;border-radius:10rpx}.item image{width:190rpx;height:190rpx;border-radius:8rpx;background:#eee}.body{flex:1;min-width:0}.body b,.body span{display:block}.body b{font-size:28rpx}.body span{margin-top:8rpx;color:#928477;font-size:22rpx}.line{display:flex;justify-content:space-between;align-items:center;margin-top:24rpx}.line strong{color:#b57630}.qty{display:flex}.qty button,.qty input{width:58rpx;height:54rpx;line-height:54rpx;text-align:center;background:#f3efe9}.delete{text-align:right;color:#aaa;font-size:21rpx;margin-top:14rpx}.bottom{position:fixed;z-index:10;left:0;right:0;bottom:0;display:flex;justify-content:space-between;align-items:center;padding:18rpx 22rpx calc(18rpx + env(safe-area-inset-bottom));background:#fff}.bottom span{font-size:22rpx}.bottom b{display:block;color:#b57630;font-size:32rpx}.bottom button{margin:0;width:300rpx;background:#805b32;color:#fff}.empty{padding:180rpx 20rpx;text-align:center;color:#8e8175}.empty button{margin-top:30rpx;background:#805b32;color:#fff}
</style>
