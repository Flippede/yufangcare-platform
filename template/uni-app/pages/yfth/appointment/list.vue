<template>
	<view class="page">
		<view class="toolbar">
			<button class="primary" @click="goCreate">New Appointment</button>
		</view>
		<view v-for="item in list" :key="item.id" class="card" @click="goDetail(item.id)">
			<view class="title">{{ item.appointment_no }}</view>
			<view class="row"><text>Status</text><text>{{ item.status }}</text></view>
			<view class="row"><text>Date</text><text>{{ item.date_text }} {{ item.start_time_text }}</text></view>
			<view class="row"><text>Store</text><text>{{ item.store_id }}</text></view>
		</view>
		<view v-if="!list.length && !loading" class="empty">No appointments</view>
	</view>
</template>

<script>
import { getYfthMyAppointments } from '@/api/yfth.js';

export default {
	data() {
		return { list: [], loading: false };
	},
	onShow() {
		this.load();
	},
	methods: {
		load() {
			this.loading = true;
			getYfthMyAppointments({ page: 1, limit: 20 }).then((res) => {
				const data = res.data || {};
				this.list = data.list || [];
			}).finally(() => {
				this.loading = false;
			});
		},
		goCreate() {
			uni.navigateTo({ url: '/pages/yfth/appointment/create' });
		},
		goDetail(id) {
			uni.navigateTo({ url: '/pages/yfth/appointment/detail?id=' + id });
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
.toolbar {
	margin-bottom: 20rpx;
}
.primary {
	background: #1f7a6b;
	color: #fff;
	border-radius: 10rpx;
}
.card {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 18rpx;
}
.title {
	font-size: 30rpx;
	font-weight: 700;
	margin-bottom: 14rpx;
}
.row {
	display: flex;
	justify-content: space-between;
	color: #60707c;
	margin-top: 10rpx;
}
.empty {
	margin-top: 120rpx;
	text-align: center;
	color: #7a858f;
}
</style>
