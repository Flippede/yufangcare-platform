<template>
	<view class="page">
		<view class="header">
			<view class="title">权益自提</view>
			<view class="sub">仅展示当前门店的产品权益自提履约单。</view>
		</view>
		<view v-for="item in list" :key="item.id" class="card">
			<view class="row">
				<view>
					<view class="name">{{ item.benefit_name }}</view>
					<view class="muted">{{ item.fulfillment_no }}</view>
				</view>
				<view class="status">{{ statusText(item.status) }}</view>
			</view>
			<button v-if="canConfirm(item)" @click="confirm(item)">确认自提</button>
		</view>
		<view v-if="!loading && !list.length" class="empty">暂无待处理自提单</view>
	</view>
</template>

<script>
import { confirmYfthStoreWorkbenchMonthlyBenefitPickup, getYfthStoreWorkbenchMonthlyBenefitPickup } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return { loading: false, list: [], context: {} };
	},
	onShow() {
		this.context = currentContext();
		this.load();
	},
	methods: {
		contextParams(extra) {
			return Object.assign({ role_code: this.context.role_code, store_id: this.context.store_id }, extra || {});
		},
		load() {
			this.loading = true;
			getYfthStoreWorkbenchMonthlyBenefitPickup(this.contextParams({ page: 1, limit: 20 })).then((res) => {
				this.list = (res.data && res.data.list) || [];
			}).finally(() => {
				this.loading = false;
			});
		},
		confirm(item) {
			confirmYfthStoreWorkbenchMonthlyBenefitPickup(item.id, this.contextParams({
				reason: 'store_pickup_confirm',
				client_operation_key: 'pickup_' + item.id + '_' + Date.now()
			})).then(() => {
				uni.showToast({ title: '已确认', icon: 'success' });
				this.load();
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		canConfirm(item) {
			return ['confirmed', 'preparing'].indexOf(item.status) !== -1;
		},
		statusText(status) {
			const map = { confirmed: '待自提', preparing: '备货中', completed: '已完成' };
			return map[status] || status;
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f8f0e5; padding: 24rpx; }
.header { background: linear-gradient(135deg, #4f3424, #a5763b); color: #fff; border-radius: 18rpx; padding: 28rpx; }
.title { font-size: 40rpx; font-weight: 700; }
.sub { margin-top: 8rpx; color: #f7e8d0; font-size: 24rpx; }
.card, .empty { background: #fff; border-radius: 18rpx; padding: 24rpx; margin-top: 18rpx; }
.row { display: flex; justify-content: space-between; gap: 18rpx; }
.name { font-size: 31rpx; font-weight: 700; color: #2b2320; }
.muted { color: #806d61; font-size: 24rpx; margin-top: 8rpx; }
.status { color: #8a5a2b; font-size: 25rpx; font-weight: 700; }
button { margin-top: 18rpx; background: #6f4c2f; color: #fff; border-radius: 12rpx; font-size: 26rpx; }
.empty { text-align: center; color: #806d61; }
</style>
