<template>
	<view v-if="accessGranted" class="purchase-page">
		<view class="top">
			<view>
				<view class="title">采购中心</view>
				<view class="sub">{{ context.store_name || '当前门店' }}</view>
			</view>
			<button class="light" @click="goInventory">库存</button>
		</view>

		<view class="tabs">
			<view v-for="item in tabs" :key="item.value" :class="['tab', tab === item.value ? 'active' : '']" @click="changeTab(item.value)">{{ item.label }}</view>
		</view>

		<view v-if="loading" class="empty">加载中...</view>
		<block v-else>
			<view v-if="tab === 'catalog'">
				<view v-if="!catalog.length" class="empty">暂无总部授权采购商品</view>
				<view v-for="item in catalog" :key="item.id" class="card">
					<view class="row-main">
						<view>
							<view class="strong">{{ item.product_name || ('商品 ' + item.product_id) }}</view>
							<view class="muted">采购价 ¥{{ item.purchase_price }} / 起订 {{ item.min_purchase_quantity }} / 倍数 {{ item.package_multiple }}</view>
							<view class="muted">SKU: {{ firstSku(item).sku_name || firstSku(item).sku_unique || '-' }}</view>
						</view>
						<view class="price">¥{{ item.purchase_price }}</view>
					</view>
					<view v-if="canWrite" class="buy-row">
						<input v-model="quantities[item.id]" type="number" placeholder="数量" />
						<button @click="submitPurchase(item)">提交采购</button>
					</view>
					<view v-else class="muted">店员仅可查看采购目录，不能创建采购单。</view>
				</view>
			</view>

			<view v-else-if="tab === 'orders'">
				<view v-if="!orders.length" class="empty">暂无采购单</view>
				<view v-for="item in orders" :key="item.id" class="card" @click="goDetail(item.id)">
					<view class="row-main">
						<view>
							<view class="strong">{{ item.purchase_no }}</view>
							<view class="muted">{{ formatTime(item.create_time) }} / {{ item.quantity_total }} 件</view>
						</view>
						<view class="status">{{ item.status_text || item.status }}</view>
					</view>
					<view class="price">¥{{ item.amount_snapshot }}</view>
				</view>
			</view>

			<view v-else>
				<view v-if="!inTransit.length" class="empty">暂无在途采购单</view>
				<view v-for="item in inTransit" :key="item.id" class="card">
					<view class="row-main">
						<view>
							<view class="strong">{{ item.purchase_no }}</view>
							<view class="muted">发货后待收货 / {{ item.quantity_total }} 件</view>
						</view>
						<view class="status">{{ item.status }}</view>
					</view>
					<button v-if="canWrite" class="primary" @click="receive(item)">确认收货入库</button>
				</view>
			</view>
		</block>
	</view>
	<view v-else class="access-check">正在校验采购权限...</view>
</template>

<script>
import {
	createYfthPurchaseOrder,
	getYfthPurchaseOrders,
	getYfthSupplyCatalog,
	getYfthSupplyInTransit,
	receiveYfthPurchaseOrder
} from '@/api/yfth.js';
import { currentContext, resolveYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			accessGranted: false,
			context: {},
			tab: 'catalog',
			loading: false,
			catalog: [],
			orders: [],
			inTransit: [],
			quantities: {},
			tabs: [
				{ label: '采购商品', value: 'catalog' },
				{ label: '我的采购单', value: 'orders' },
				{ label: '在途收货', value: 'transit' }
			]
		};
	},
	computed: {
		canWrite() {
			return this.context.role_code === 'store_manager';
		}
	},
	onShow() {
		const cached = currentContext();
		resolveYfthContext(cached.role_code || 'customer', cached.store_id || 0).then((context) => {
			if (context.role_code !== 'store_manager') {
				this.redirectToWorkbench();
				return;
			}
			this.context = context;
			this.accessGranted = true;
			this.load();
		}).catch((err) => {
			uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			uni.navigateBack();
		});
	},
	methods: {
		redirectToWorkbench() {
			const target = '/pages/yfth/workbench/index';
			this.accessGranted = false;
			uni.showToast({ title: '仅店长可进入采购中心', icon: 'none' });
			uni.reLaunch({ url: target });
			setTimeout(() => {
				// #ifdef H5
				if (typeof window !== 'undefined' && window.location.pathname !== target) {
					window.location.replace(target);
				}
				// #endif
			}, 300);
		},
		contextParams(extra) {
			return Object.assign({ role_code: this.context.role_code, store_id: this.context.store_id }, extra || {});
		},
		changeTab(tab) {
			this.tab = tab;
			this.load();
		},
		load() {
			this.loading = true;
			const api = this.tab === 'catalog'
				? getYfthSupplyCatalog(this.contextParams())
				: (this.tab === 'orders' ? getYfthPurchaseOrders(this.contextParams({ page: 1, limit: 20 })) : getYfthSupplyInTransit(this.contextParams({ page: 1, limit: 20 })));
			api.then((res) => {
				const list = (res.data && res.data.list) || [];
				if (this.tab === 'catalog') this.catalog = list;
				if (this.tab === 'orders') this.orders = list;
				if (this.tab === 'transit') this.inTransit = list;
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		firstSku(item) {
			return (item.skus && item.skus[0]) || {};
		},
		submitPurchase(item) {
			const sku = this.firstSku(item);
			const qty = Number(this.quantities[item.id] || item.min_purchase_quantity || 1);
			if (!sku.sku_unique) {
				uni.showToast({ title: '商品暂无可采购 SKU', icon: 'none' });
				return;
			}
			const payload = this.contextParams({
				idempotency_key: 'yfth_purchase_' + item.id + '_' + Date.now(),
				items: [{ product_id: item.product_id, sku_unique: sku.sku_unique, quantity: qty }]
			});
			createYfthPurchaseOrder(payload).then((res) => {
				uni.showToast({ title: '采购单已提交', icon: 'success' });
				const order = res.data && res.data.order;
				if (order && order.id) this.goDetail(order.id);
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		receive(item) {
			uni.showModal({
				title: '确认收货',
				content: '确认收到该采购单商品并完成入库？',
				success: (modal) => {
					if (!modal.confirm) return;
					receiveYfthPurchaseOrder(item.id, this.contextParams({ idempotency_key: 'yfth_receipt_' + item.id + '_' + Date.now() })).then(() => {
						uni.showToast({ title: '已入库', icon: 'success' });
						this.load();
					}).catch((err) => {
						uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
					});
				}
			});
		},
		goDetail(id) {
			uni.navigateTo({ url: '/pages/yfth/workbench/purchase/detail?id=' + id });
		},
		goInventory() {
			uni.navigateTo({ url: '/pages/yfth/workbench/purchase/inventory' });
		},
		formatTime(value) {
			const ts = Number(value || 0);
			if (!ts) return '-';
			const date = new Date(ts * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
		}
	}
};
</script>

<style scoped>
.purchase-page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.access-check { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f6f0e6; color: #7a604d; font-size: 26rpx; }
.top { background: #5a3f2e; color: #fff; border-radius: 16rpx; padding: 26rpx; display: flex; justify-content: space-between; align-items: center; }
.title { font-size: 38rpx; font-weight: 700; }
.sub { margin-top: 8rpx; color: #f4dfc0; font-size: 24rpx; }
.light { background: #fffaf2; color: #6f4c2f; border-radius: 12rpx; font-size: 24rpx; }
.tabs { display: flex; gap: 12rpx; margin: 20rpx 0; }
.tab { flex: 1; text-align: center; background: #fff7e9; color: #7a604d; border-radius: 12rpx; padding: 18rpx 8rpx; font-size: 25rpx; }
.tab.active { background: #6f4c2f; color: #fff; font-weight: 700; }
.card, .empty { background: #fff; border-radius: 16rpx; padding: 24rpx; margin-top: 18rpx; }
.row-main { display: flex; justify-content: space-between; gap: 18rpx; }
.strong { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.muted { color: #786b73; font-size: 24rpx; margin-top: 8rpx; }
.price, .status { color: #8f4d2c; font-weight: 700; }
.buy-row { display: flex; gap: 14rpx; margin-top: 18rpx; }
.buy-row input { flex: 1; background: #fffaf2; border-radius: 10rpx; padding: 0 18rpx; height: 64rpx; line-height: 64rpx; }
.buy-row button, .primary { flex: 1; background: #6f4c2f; color: #fff; border-radius: 10rpx; font-size: 25rpx; }
</style>
