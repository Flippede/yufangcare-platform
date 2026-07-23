<template>
	<view class="page">
		<view class="address" @click="manageAddress">
			<view v-if="address.id">
				<b>{{ address.real_name }}　{{ address.phone }}</b>
				<span>{{ fullAddress }}</span>
			</view>
			<view v-else>
				<b>请选择收货地址</b>
				<span>采购商品将按该地址配送</span>
			</view>
			<text>›</text>
		</view>

		<view class="panel">
			<view v-for="item in cart" :key="item.key" class="goods">
				<image :src="item.product_image" mode="aspectFill"></image>
				<view>
					<b>{{ item.product_name }}</b>
					<span>{{ item.sku_name || '默认规格' }}</span>
					<small>¥{{ item.purchase_price }} × {{ item.quantity }}</small>
				</view>
			</view>
		</view>

		<view class="panel rows">
			<view><span>商品金额</span><b>¥{{ goodsTotal }}</b></view>
			<view><span>配送方式</span><b>快递配送</b></view>
			<view><span>运费</span><b>¥0.00</b></view>
			<view><span>商品额度</span><input v-model="quotaAmount" type="digit" :placeholder="'可用 ¥' + quotaAvailable" /></view>
			<view><span>结算方式</span><b>总部审核后按采购单结算</b></view>
		</view>

		<view class="panel">
			<textarea v-model="buyerMark" maxlength="255" placeholder="采购备注（选填）"></textarea>
		</view>
		<view class="notice">采购价、商品规格、收货地址和金额将在提交时形成快照。总部审核后发货，物流可在同一采购订单中查看。</view>

		<view class="bottom">
			<view><small>采购应付</small><b>¥{{ onlineAmount }}</b></view>
			<button :disabled="submitting" @click="submit">{{ submitting ? '提交中' : '提交采购订单' }}</button>
		</view>
	</view>
</template>

<script>
import { createYfthPurchaseOrder, getYfthProductQuotaSummary } from '@/api/yfth.js';
import { getAddressDefault } from '@/api/user.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			context: {},
			cart: [],
			address: {},
			quotaAmount: '',
			quotaAvailableCent: 0,
			buyerMark: '',
			submitting: false
		};
	},
	computed: {
		goodsTotal() {
			return this.cart.reduce((sum, item) => sum + Number(item.purchase_price) * Number(item.quantity), 0).toFixed(2);
		},
		quotaAvailable() {
			return (Number(this.quotaAvailableCent || 0) / 100).toFixed(2);
		},
		onlineAmount() {
			return Math.max(0, Number(this.goodsTotal) - Math.min(Number(this.quotaAmount || 0), Number(this.quotaAvailable))).toFixed(2);
		},
		fullAddress() {
			return [this.address.province, this.address.city, this.address.district, this.address.detail].filter(Boolean).join(' ');
		}
	},
	onShow() {
		this.context = currentContext();
		this.cart = uni.getStorageSync(this.key()) || [];
		this.loadAddress();
		getYfthProductQuotaSummary({ role_code: this.context.role_code, store_id: this.context.store_id }).then((res) => {
			this.quotaAvailableCent = Number((res.data && res.data.available_cent) || 0);
		}).catch(() => {});
	},
	methods: {
		key() {
			return 'YFTH_PURCHASE_CART_' + Number(this.context.store_id || 0);
		},
		loadAddress() {
			getAddressDefault().then((res) => {
				this.address = res.data || {};
			}).catch(() => {
				this.address = {};
			});
		},
		manageAddress() {
			uni.navigateTo({ url: '/pages/users/user_address_list/index' });
		},
		submit() {
			if (this.submitting) return;
			if (!this.cart.length) {
				uni.showToast({ title: '采购车为空', icon: 'none' });
				return;
			}
			if (!this.address.id) {
				uni.showToast({ title: '请选择收货地址', icon: 'none' });
				return;
			}
			this.submitting = true;
			createYfthPurchaseOrder({
				role_code: this.context.role_code,
				store_id: this.context.store_id,
				address_id: this.address.id,
				pay_type: 'offline',
				buyer_mark: this.buyerMark,
				quota_amount_cent: Math.max(0, Math.min(this.quotaAvailableCent, Math.round(Number(this.quotaAmount || 0) * 100))),
				idempotency_key: 'yfth_purchase_checkout_' + Date.now(),
				items: this.cart.map((item) => ({
					product_id: item.product_id,
					sku_unique: item.sku_unique,
					quantity: Number(item.quantity)
				}))
			}).then((res) => {
				const order = res.data && res.data.order;
				uni.removeStorageSync(this.key());
				uni.redirectTo({ url: '/pages/yfth/workbench/purchase/detail?id=' + (order && order.id) });
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.submitting = false;
			});
		}
	}
};
</script>

<style scoped>
.page{min-height:100vh;background:#f5f1e9;padding:20rpx 20rpx 150rpx}.address,.panel,.notice{margin-bottom:16rpx;padding:24rpx;background:#fff;border-radius:10rpx}.address{display:flex;justify-content:space-between;align-items:center;border-top:6rpx solid #b88a50}.address b,.address span{display:block}.address span{margin-top:12rpx;color:#766b62;font-size:23rpx;line-height:1.5}.address>text{font-size:46rpx;color:#a88b6d}.goods{display:flex;gap:18rpx;padding:16rpx 0;border-bottom:1rpx solid #eee}.goods:last-child{border:0}.goods image{width:140rpx;height:140rpx;border-radius:8rpx;background:#eee}.goods b,.goods span,.goods small{display:block}.goods span{margin-top:8rpx;color:#918477;font-size:22rpx}.goods small{margin-top:16rpx;color:#b57630}.rows>view{display:flex;justify-content:space-between;gap:20rpx;padding:18rpx 0;border-bottom:1rpx solid #eee;font-size:25rpx}.rows>view:last-child{border:0}.rows input{text-align:right}.notice{color:#826b51;font-size:22rpx;line-height:1.6;background:#fff9ec;border:1rpx solid #e5cfad}.bottom{position:fixed;z-index:10;left:0;right:0;bottom:0;display:flex;justify-content:space-between;align-items:center;padding:16rpx 20rpx calc(16rpx + env(safe-area-inset-bottom));background:#fff}.bottom small,.bottom b{display:block}.bottom b{color:#b57630;font-size:36rpx}.bottom button{margin:0;width:330rpx;background:#805b32;color:#fff}
</style>
