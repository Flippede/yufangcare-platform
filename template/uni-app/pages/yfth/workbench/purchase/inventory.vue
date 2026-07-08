<template>
	<view class="inventory-page">
		<view class="tabs">
			<view :class="['tab', tab === 'balance' ? 'active' : '']" @click="changeTab('balance')">库存余额</view>
			<view :class="['tab', tab === 'ledger' ? 'active' : '']" @click="changeTab('ledger')">库存流水</view>
		</view>
		<view v-if="loading" class="empty">加载中...</view>
		<view v-else-if="!list.length" class="empty">暂无库存数据</view>
		<view v-else>
			<view v-for="item in list" :key="item.id" class="card">
				<view class="strong">{{ item.product_name || ('商品 ' + item.product_id) }}</view>
				<view class="muted">SKU: {{ item.sku_unique }}</view>
				<view v-if="tab === 'balance'" class="qty">库存：{{ item.quantity }}</view>
				<view v-else>
					<view class="qty">变动：{{ item.quantity_change }} / 结余：{{ item.balance_after }}</view>
					<view class="muted">{{ item.business_type }} #{{ item.business_id }}</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthInventory, getYfthInventoryLedger } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return { tab: 'balance', list: [], loading: false, context: {} };
	},
	onShow() {
		this.context = currentContext();
		this.load();
	},
	methods: {
		changeTab(tab) {
			this.tab = tab;
			this.load();
		},
		load() {
			this.loading = true;
			const params = { role_code: this.context.role_code, store_id: this.context.store_id, page: 1, limit: 50 };
			const api = this.tab === 'balance' ? getYfthInventory(params) : getYfthInventoryLedger(params);
			api.then((res) => {
				this.list = (res.data && res.data.list) || [];
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
.inventory-page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.tabs { display: flex; gap: 12rpx; margin-bottom: 18rpx; }
.tab { flex: 1; text-align: center; background: #fff7e9; color: #7a604d; border-radius: 12rpx; padding: 18rpx 8rpx; font-size: 25rpx; }
.tab.active { background: #6f4c2f; color: #fff; font-weight: 700; }
.card, .empty { background: #fff; border-radius: 16rpx; padding: 24rpx; margin-top: 18rpx; }
.strong { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.muted { color: #786b73; font-size: 24rpx; margin-top: 8rpx; }
.qty { color: #8f4d2c; font-weight: 700; margin-top: 10rpx; }
</style>

