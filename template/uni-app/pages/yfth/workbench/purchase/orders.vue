<template>
	<view class="page">
		<view class="tabs">
			<view v-for="item in tabs" :key="item.value" :class="{ active: status === item.value }" @click="changeStatus(item.value)">{{ item.label }}</view>
		</view>
		<view v-if="loading" class="empty">加载中...</view>
		<view v-else-if="!orders.length" class="empty">暂无采购订单</view>
		<view v-for="item in orders" :key="item.id" class="order" @click="detail(item.id)">
			<view class="head"><b>{{ item.purchase_no }}</b><span>{{ item.status_text }}</span></view>
			<view class="meta">{{ time(item.create_time) }}　共 {{ item.quantity_total }} 件</view>
			<view class="amount">采购金额 <b>¥{{ item.amount_snapshot }}</b></view>
		</view>
	</view>
</template>

<script>
import { getYfthPurchaseOrders } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			context: {},
			status: '',
			orders: [],
			loading: false,
			tabs: [
				{ label: '全部', value: '' },
				{ label: '待审核', value: 'submitted' },
				{ label: '待收货', value: 'shipped' },
				{ label: '已入库', value: 'stocked' }
			]
		};
	},
	onLoad(options) {
		this.status = options.status || '';
	},
	onShow() {
		this.context = currentContext();
		this.load();
	},
	methods: {
		changeStatus(status) {
			this.status = status;
			this.load();
		},
		load() {
			this.loading = true;
			getYfthPurchaseOrders({
				role_code: this.context.role_code,
				store_id: this.context.store_id,
				status: this.status,
				page: 1,
				limit: 50
			}).then((res) => {
				this.orders = (res.data && res.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		detail(id) {
			uni.navigateTo({ url: '/pages/yfth/workbench/purchase/detail?id=' + id });
		},
		time(value) {
			if (!value) return '-';
			return new Date(Number(value) * 1000).toLocaleString();
		}
	}
};
</script>

<style scoped>
.page{min-height:100vh;background:#f5f1e9;padding:20rpx}.tabs{display:flex;overflow:auto;background:#fff;border-radius:10rpx;margin-bottom:18rpx}.tabs view{flex:1;min-width:130rpx;padding:24rpx 10rpx;text-align:center;font-size:24rpx;border-bottom:4rpx solid transparent}.tabs .active{color:#805b32;border-bottom-color:#805b32}.order,.empty{margin-bottom:16rpx;padding:24rpx;background:#fff;border-radius:10rpx}.head,.amount{display:flex;justify-content:space-between}.head span{color:#9b7041}.meta{margin:18rpx 0;color:#8f8277;font-size:22rpx}.amount{padding-top:16rpx;border-top:1rpx solid #eee;font-size:23rpx}.amount b{font-size:28rpx}
</style>
