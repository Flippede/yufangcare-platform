<template>
	<view v-if="product.id" class="page">
		<swiper v-if="images.length" class="swiper" indicator-dots circular>
			<swiper-item v-for="(image, index) in images" :key="index">
				<image :src="image" mode="aspectFill"></image>
			</swiper-item>
		</swiper>
		<view v-else class="image-empty">暂无商品图片</view>

		<view class="panel">
			<view class="price"><small>采购价</small> ¥{{ product.purchase_price }}</view>
			<view class="retail">商城参考价 ¥{{ product.retail_reference_price || product.retail_price }}</view>
			<view class="name">{{ product.product_name }}</view>
			<view class="desc">{{ product.product_info || '总部统一供货，采购订单由总部审核后发货。' }}</view>
		</view>

		<view class="panel">
			<view class="label">采购规格</view>
			<view class="sku-list">
				<view
					v-for="sku in product.skus"
					:key="sku.sku_unique"
					:class="['sku', selectedSku.sku_unique === sku.sku_unique ? 'active' : '']"
					@click="selectedSku = sku"
				>{{ sku.sku_name || '默认规格' }}</view>
			</view>
			<view class="qty-row">
				<text>采购数量</text>
				<view>
					<button @click="changeQty(-step)">-</button>
					<input v-model.number="quantity" type="number" @blur="normalizeQuantity" />
					<button @click="changeQty(step)">+</button>
				</view>
			</view>
			<view class="rule">起订 {{ product.min_purchase_quantity }} 件，按 {{ product.package_multiple }} 件递增</view>
		</view>

		<view class="bottom">
			<view class="cart" @click="goCart">采购车<text v-if="cartCount">{{ cartCount }}</text></view>
			<button class="secondary" @click="addCart(false)">加入采购车</button>
			<button class="primary" @click="addCart(true)">立即采购</button>
		</view>
	</view>
	<view v-else class="loading">正在加载采购商品...</view>
</template>

<script>
import { getYfthSupplyCatalog } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			catalogId: 0,
			product: {},
			selectedSku: {},
			quantity: 1,
			context: {},
			cartCount: 0
		};
	},
	computed: {
		images() {
			const images = this.product.slider_images && this.product.slider_images.length
				? this.product.slider_images
				: [this.product.product_image];
			return images.filter(Boolean);
		},
		step() {
			return Math.max(1, Number(this.product.package_multiple || 1));
		}
	},
	onLoad(options) {
		this.catalogId = Number(options.id || 0);
		this.context = currentContext();
		this.load();
	},
	onShow() {
		this.refreshCount();
	},
	methods: {
		key() {
			return 'YFTH_PURCHASE_CART_' + Number(this.context.store_id || 0);
		},
		cart() {
			return uni.getStorageSync(this.key()) || [];
		},
		load() {
			getYfthSupplyCatalog({
				role_code: this.context.role_code,
				store_id: this.context.store_id,
				limit: 100
			}).then((res) => {
				this.product = ((res.data && res.data.list) || []).find((item) => Number(item.id) === this.catalogId) || {};
				this.selectedSku = (this.product.skus && this.product.skus[0]) || {};
				this.quantity = this.minimumQuantity();
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		minimumQuantity() {
			return Math.ceil(Math.max(1, Number(this.product.min_purchase_quantity || 1)) / this.step) * this.step;
		},
		normalizeQuantity() {
			const min = this.minimumQuantity();
			const value = Math.max(min, Number(this.quantity || min));
			this.quantity = Math.max(min, Math.ceil(value / this.step) * this.step);
		},
		changeQty(delta) {
			this.quantity = Number(this.quantity || 0) + Number(delta || 0);
			this.normalizeQuantity();
		},
		addCart(checkoutNow) {
			if (!this.selectedSku.sku_unique) {
				uni.showToast({ title: '请选择采购规格', icon: 'none' });
				return;
			}
			this.normalizeQuantity();
			const cart = this.cart();
			const key = this.product.product_id + ':' + this.selectedSku.sku_unique;
			const found = cart.find((item) => item.key === key);
			if (found) {
				found.quantity += Number(this.quantity);
			} else {
				cart.push({
					key,
					catalog_id: this.product.id,
					product_id: this.product.product_id,
					product_name: this.product.product_name,
					product_image: this.product.product_image,
					sku_unique: this.selectedSku.sku_unique,
					sku_name: this.selectedSku.sku_name,
					purchase_price: this.product.purchase_price,
					quantity: Number(this.quantity),
					min_quantity: this.minimumQuantity(),
					multiple: this.step
				});
			}
			uni.setStorageSync(this.key(), cart);
			this.refreshCount();
			if (checkoutNow) {
				this.goCart();
			} else {
				uni.showToast({ title: '已加入采购车', icon: 'success' });
			}
		},
		refreshCount() {
			this.cartCount = this.cart().reduce((sum, item) => sum + Number(item.quantity || 0), 0);
		},
		goCart() {
			uni.navigateTo({ url: '/pages/yfth/workbench/purchase/cart' });
		}
	}
};
</script>

<style scoped>
.page,.loading{min-height:100vh;background:#f5f1e9}.page{padding-bottom:130rpx}.loading{display:flex;align-items:center;justify-content:center;color:#8f8175}.swiper,.swiper image,.image-empty{width:100%;height:720rpx;background:#eee}.image-empty{display:flex;align-items:center;justify-content:center;color:#988b7d}.panel{margin:18rpx 20rpx;padding:26rpx;background:#fff;border-radius:10rpx}.price{color:#b57630;font-size:42rpx;font-weight:700}.price small{font-size:22rpx}.retail{color:#aaa;font-size:22rpx;text-decoration:line-through}.name{margin-top:20rpx;font-size:34rpx;font-weight:700}.desc,.rule{margin-top:12rpx;color:#8f8175;font-size:23rpx}.label{font-size:28rpx;font-weight:700}.sku-list{display:flex;flex-wrap:wrap;gap:14rpx;margin-top:18rpx}.sku{padding:16rpx 22rpx;background:#f4f0e9;border:1rpx solid transparent;border-radius:8rpx}.sku.active{color:#805b32;border-color:#a77a46;background:#fff8eb}.qty-row{display:flex;justify-content:space-between;align-items:center;margin-top:28rpx}.qty-row>view{display:flex}.qty-row button,.qty-row input{width:72rpx;height:64rpx;line-height:64rpx;text-align:center;background:#f6f2ec;border:0}.bottom{position:fixed;z-index:10;left:0;right:0;bottom:0;display:flex;align-items:center;padding:16rpx 20rpx calc(16rpx + env(safe-area-inset-bottom));background:#fff}.bottom button{height:76rpx;line-height:76rpx;border-radius:0;font-size:25rpx}.cart{position:relative;width:110rpx;text-align:center;font-size:22rpx}.cart text{position:absolute;top:-18rpx;right:12rpx;background:#d84c37;color:#fff;border-radius:18rpx;padding:2rpx 9rpx}.secondary{flex:1;background:#d9b172;color:#fff}.primary{flex:1;background:#805b32;color:#fff}
</style>
