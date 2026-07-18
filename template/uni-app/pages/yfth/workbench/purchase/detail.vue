<template>
	<view class="detail-page">
		<view v-if="loading" class="empty">加载中...</view>
		<block v-else-if="detail.order">
			<view class="card">
				<view class="strong">{{ detail.order.purchase_no }}</view>
				<view class="muted">状态：{{ detail.order.status_text || detail.order.status }}</view>
				<view class="muted">金额：¥{{ detail.order.amount_snapshot }} / 数量：{{ detail.order.quantity_total }}</view>
			</view>
			<view v-if="detail.quota_payment" class="card">
				<view class="section-title">支付构成</view>
				<view class="muted">商品额度：¥{{ money(detail.quota_payment.quota_amount_cent) }}</view>
				<view class="muted">在线支付：¥{{ money(detail.quota_payment.online_amount_cent) }}</view>
				<view class="muted">额度状态：{{ detail.quota_payment.status || '-' }}</view>
			</view>
			<view class="card">
				<view class="section-title">商品明细</view>
				<view v-for="item in detail.items" :key="item.id" class="line">
					<view>
						<view class="strong small">{{ item.product_name_snapshot }}</view>
						<view class="muted">{{ item.sku_name_snapshot || item.sku_unique }}</view>
					</view>
					<view>x{{ item.quantity }}</view>
				</view>
			</view>
			<view class="card">
				<view class="section-title">发货收货</view>
				<view v-for="item in detail.shipments" :key="item.id" class="muted">发货单：{{ item.shipment_no }} / {{ item.status }}</view>
				<view v-for="item in detail.receipts" :key="item.id" class="muted">收货单：{{ item.receipt_no }} / {{ item.status }}</view>
			</view>
		</block>
		<view v-else class="empty">采购单不存在</view>
	</view>
</template>

<script>
import { getYfthPurchaseOrderDetail } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return { id: 0, detail: {}, loading: false, context: {} };
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.context = currentContext();
		this.load();
	},
	methods: {
		money(value) { return (Number(value || 0) / 100).toFixed(2); },
		load() {
			this.loading = true;
			getYfthPurchaseOrderDetail(this.id, { role_code: this.context.role_code, store_id: this.context.store_id }).then((res) => {
				this.detail = res.data || {};
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		}
	}
};
</script>

<style scoped>
.detail-page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.card, .empty { background: #fff; border-radius: 16rpx; padding: 24rpx; margin-top: 18rpx; }
.strong { font-size: 32rpx; font-weight: 700; color: #2d2434; }
.strong.small { font-size: 28rpx; }
.muted { color: #786b73; font-size: 24rpx; margin-top: 8rpx; }
.section-title { font-size: 30rpx; font-weight: 700; margin-bottom: 12rpx; }
.line { display: flex; justify-content: space-between; gap: 18rpx; padding: 18rpx 0; border-bottom: 1rpx solid #f1e5d4; }
</style>
