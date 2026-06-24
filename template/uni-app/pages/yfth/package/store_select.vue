<template>
	<view class="page">
		<view v-for="store in stores" :key="store.store_id" class="store" @click="choose(store)">
			<view class="name">{{ store.store_name }}</view>
			<view class="status">{{ store.store_status }}</view>
		</view>
		<view v-if="!stores.length" class="empty">暂无可服务门店</view>
	</view>
</template>

<script>
import { getYfthPackageStores } from '@/api/yfth.js';

export default {
	data() {
		return { id: 0, stores: [] };
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		getYfthPackageStores(this.id).then((res) => {
			this.stores = res.data || [];
		});
	},
	methods: {
		choose(store) {
			uni.navigateTo({
				url: '/pages/yfth/package/agreement_confirm?id=' + this.id + '&store_id=' + store.store_id
			});
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	background: #f5f7f8;
	padding: 24rpx;
}
.store {
	padding: 28rpx;
	background: #fff;
	border-radius: 12rpx;
	margin-bottom: 18rpx;
}
.name {
	font-size: 32rpx;
	font-weight: 600;
	color: #1f2933;
}
.status,
.empty {
	margin-top: 12rpx;
	color: #697580;
	font-size: 26rpx;
}
</style>
