<template>
	<view class="page">
		<view class="card" v-for="item in list" :key="item.id" @click="goDetail(item.id)">
			<view class="row">
				<view>
					<view class="name">{{ item.benefit_name }}</view>
					<view class="muted">{{ item.fulfillment_no }}</view>
				</view>
				<view class="status">{{ statusText(item.status) }}</view>
			</view>
			<view class="muted">第 {{ item.month_no }} 月 · {{ methodText(item.fulfillment_method) }}</view>
		</view>
		<view v-if="!loading && !list.length" class="empty">暂无履约记录</view>
	</view>
</template>

<script>
import { getYfthMonthlyBenefitHistory } from '@/api/yfth.js';

export default {
	data() {
		return { loading: false, list: [] };
	},
	onShow() {
		this.load();
	},
	methods: {
		load() {
			this.loading = true;
			getYfthMonthlyBenefitHistory({ page: 1, limit: 20 }).then((res) => {
				this.list = (res.data && res.data.list) || [];
			}).finally(() => {
				this.loading = false;
			});
		},
		goDetail(id) {
			uni.navigateTo({ url: '/pages/yfth/monthly_benefit/detail?id=' + id });
		},
		statusText(status) {
			const map = { pending_confirm: '待确认', confirmed: '已确认', preparing: '备货中', shipped: '已发货', completed: '已完成', cancelled: '已取消', rejected: '已驳回', exception: '异常' };
			return map[status] || status;
		},
		methodText(method) {
			return method === 'self_pickup' ? '到店自提' : '快递配送';
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f8f0e5; padding: 24rpx; }
.card, .empty { background: #fff; border-radius: 18rpx; padding: 24rpx; margin-top: 18rpx; }
.row { display: flex; justify-content: space-between; gap: 20rpx; }
.name { font-size: 31rpx; font-weight: 700; color: #2b2320; }
.muted { color: #806d61; font-size: 24rpx; margin-top: 8rpx; }
.status { color: #8a5a2b; font-size: 25rpx; font-weight: 700; }
.empty { text-align: center; color: #806d61; }
</style>
