<template>
	<view v-if="accessGranted" class="page">
		<view class="hero">
			<view>
				<view class="eyebrow">总部统一供货</view>
				<view class="title">采购商城</view>
				<view class="store">{{ context.store_name || '当前门店' }}</view>
			</view>
			<view class="hero-actions">
				<view class="icon-btn" @click="goOrders">单</view>
				<view class="icon-btn cart-btn" @click="goCart">车<text v-if="cartCount">{{ cartCount }}</text></view>
			</view>
		</view>

		<view class="search">
			<text>⌕</text>
			<input v-model="keyword" placeholder="搜索总部采购商品" confirm-type="search" @confirm="load" />
			<view v-if="keyword" class="clear" @click="keyword = ''; load()">×</view>
		</view>

		<view class="quick-row">
			<view class="quick" @click="goOrders"><b>采购订单</b><span>查看全部订单</span></view>
			<view class="quick" @click="goUnsend"><b>待发货</b><span>等待总部发货</span></view>
			<view class="quick" @click="goTransit"><b>待收货</b><span>跟踪快递物流</span></view>
		</view>

		<view class="section-head"><b>采购商品</b><span>总部采购价 · 快递配送</span></view>
		<view v-if="loading" class="empty">正在加载采购商品...</view>
		<view v-else-if="!catalog.length" class="empty">暂无可采购商品</view>
		<view v-else class="goods-grid">
			<view v-for="item in catalog" :key="item.id" class="goods" @click="goProduct(item.id)">
				<image :src="item.product_image || fallbackImage" mode="aspectFill"></image>
				<view class="goods-body">
					<view class="name">{{ item.product_name || '采购商品' }}</view>
					<view class="info">{{ item.product_info || ('起订 ' + item.min_purchase_quantity + ' 件') }}</view>
					<view class="price-row">
						<view><small>采购价</small><b>¥{{ item.purchase_price }}</b></view>
						<view class="add" @click.stop="quickAdd(item)">+</view>
					</view>
					<view class="retail">商城参考价 ¥{{ item.retail_reference_price || item.retail_price }}</view>
				</view>
			</view>
		</view>
	</view>
	<view v-else class="access-check">正在校验采购权限...</view>
</template>

<script>
import { getYfthSupplyCatalog } from '@/api/yfth.js';
import { currentContext, resolveYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			accessGranted: false, context: {}, loading: false, keyword: '', catalog: [], cartCount: 0,
			fallbackImage: '/static/images/noCart.png'
		};
	},
	onShow() {
		const cached = currentContext();
		resolveYfthContext(cached.role_code || 'customer', cached.store_id || 0).then((context) => {
			if (context.role_code !== 'store_manager') {
				uni.showToast({ title: '仅店长可进入采购商城', icon: 'none' });
				this.redirectToWorkbench();
				return;
			}
			this.context = context; this.accessGranted = true; this.load(); this.refreshCartCount();
		}).catch((err) => uni.showToast({ title: String((err && err.msg) || err), icon: 'none' }));
	},
	methods: {
		redirectToWorkbench() {
			const target = '/pages/yfth/workbench/index';
			// #ifdef H5
			if (typeof window !== 'undefined') {
				window.location.replace(target);
				return;
			}
			// #endif
			uni.reLaunch({ url: target });
		},
		contextParams(extra) { return Object.assign({ role_code: this.context.role_code, store_id: this.context.store_id }, extra || {}); },
		cartKey() { return 'YFTH_PURCHASE_CART_' + Number(this.context.store_id || 0); },
		getCart() { return uni.getStorageSync(this.cartKey()) || []; },
		setCart(cart) { uni.setStorageSync(this.cartKey(), cart); this.refreshCartCount(); },
		refreshCartCount() { this.cartCount = this.getCart().reduce((sum, item) => sum + Number(item.quantity || 0), 0); },
		load() {
			this.loading = true;
			getYfthSupplyCatalog(this.contextParams({ keyword: this.keyword })).then((res) => {
				this.catalog = (res.data && res.data.list) || [];
			}).catch((err) => uni.showToast({ title: String((err && err.msg) || err), icon: 'none' }))
				.finally(() => { this.loading = false; });
		},
		quickAdd(item) {
			const sku = (item.skus && item.skus[0]) || {};
			if (!sku.sku_unique) return uni.showToast({ title: '商品暂无可采购规格', icon: 'none' });
			const cart = this.getCart();
			const key = item.product_id + ':' + sku.sku_unique;
			const found = cart.find((row) => row.key === key);
			if (found) found.quantity += Number(item.package_multiple || 1);
			else {
				const multiple = Math.max(1, Number(item.package_multiple || 1));
				const minQuantity = Math.ceil(Math.max(1, Number(item.min_purchase_quantity || 1)) / multiple) * multiple;
				cart.push({
				key, catalog_id: item.id, product_id: item.product_id, product_name: item.product_name,
				product_image: item.product_image, sku_unique: sku.sku_unique, sku_name: sku.sku_name,
				purchase_price: item.purchase_price, quantity: minQuantity,
				min_quantity: minQuantity, multiple
				});
			}
			this.setCart(cart);
			uni.showToast({ title: '已加入采购车', icon: 'success' });
		},
		goProduct(id) { uni.navigateTo({ url: '/pages/yfth/workbench/purchase/product?id=' + id }); },
		goCart() { uni.navigateTo({ url: '/pages/yfth/workbench/purchase/cart' }); },
		goOrders() { uni.navigateTo({ url: '/pages/goods/order_list/index?status=0' }); },
		goUnsend() { uni.navigateTo({ url: '/pages/goods/order_list/index?status=2' }); },
		goTransit() { uni.navigateTo({ url: '/pages/goods/order_list/index?status=3' }); }
	}
};
</script>

<style scoped>
.page,.access-check{min-height:100vh;background:#f5f1e9;color:#2d241d}.page{padding-bottom:40rpx}.access-check{display:flex;align-items:center;justify-content:center}.hero{display:flex;justify-content:space-between;align-items:center;padding:40rpx 30rpx 34rpx;background:#7d5b39;color:#fff}.eyebrow{font-size:22rpx;opacity:.75}.title{font-size:48rpx;font-weight:700;margin-top:5rpx}.store{font-size:25rpx;margin-top:8rpx;opacity:.9}.hero-actions{display:flex;gap:14rpx}.icon-btn{position:relative;width:72rpx;height:72rpx;border:1rpx solid rgba(255,255,255,.55);border-radius:50%;display:flex;align-items:center;justify-content:center}.cart-btn text{position:absolute;right:-4rpx;top:-8rpx;min-width:30rpx;height:30rpx;border-radius:15rpx;background:#d94a35;font-size:19rpx;text-align:center;line-height:30rpx}.search{height:76rpx;margin:-18rpx 24rpx 18rpx;padding:0 24rpx;display:flex;align-items:center;gap:14rpx;background:#fff;border-radius:10rpx;box-shadow:0 8rpx 22rpx rgba(80,55,30,.09)}.search input{flex:1;font-size:26rpx}.clear{padding:10rpx}.quick-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx;padding:0 24rpx}.quick{background:#fff;padding:22rpx 16rpx;border-radius:10rpx}.quick b,.quick span{display:block}.quick b{font-size:27rpx}.quick span{font-size:20rpx;color:#918274;margin-top:8rpx}.section-head{display:flex;justify-content:space-between;align-items:center;padding:34rpx 24rpx 18rpx}.section-head b{font-size:34rpx}.section-head span{font-size:22rpx;color:#9a8a7b}.goods-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18rpx;padding:0 24rpx}.goods{overflow:hidden;background:#fff;border-radius:10rpx}.goods>image{width:100%;height:310rpx;background:#eee8df}.goods-body{padding:18rpx}.name{height:72rpx;font-size:27rpx;font-weight:600;line-height:36rpx;overflow:hidden}.info{height:32rpx;color:#9a8a7b;font-size:21rpx;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.price-row{display:flex;justify-content:space-between;align-items:end;margin-top:14rpx}.price-row small{display:block;color:#a47c4c;font-size:19rpx}.price-row b{color:#b77932;font-size:31rpx}.add{width:50rpx;height:50rpx;border-radius:50%;background:#8a633d;color:#fff;text-align:center;line-height:48rpx;font-size:36rpx}.retail{margin-top:8rpx;color:#aaa;font-size:19rpx;text-decoration:line-through}.empty{margin:24rpx;background:#fff;border-radius:10rpx;padding:80rpx 20rpx;text-align:center;color:#9a8a7b}
</style>
