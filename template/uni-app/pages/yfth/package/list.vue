<template>
	<view class="page">
		<view class="hero">
			<view class="title">御方通和康养套餐</view>
			<view class="sub">选择适合的套餐，完成支付并激活后获得对应权益。</view>
		</view>
		<view v-if="loading" class="state">正在加载可购买套餐...</view>
		<view v-else-if="error" class="state error">
			<view>{{ error }}</view>
			<button @click="load">重新加载</button>
		</view>
		<view v-else-if="!list.length" class="state">当前暂无可购买套餐</view>
		<view v-else class="list">
			<view v-for="item in list" :key="item.id" class="package" @click="openDetail(item)">
				<view class="package-main">
					<view class="name">{{ item.package_title || item.package_name }}</view>
					<view class="summary">{{ item.service_summary || '御方通和家庭康养套餐' }}</view>
					<view class="meta">{{ monthCount(item) }}个月权益计划</view>
				</view>
				<view class="package-side">
					<view class="price">¥{{ price(item) }}</view>
					<view class="action">查看详情</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthPackageList } from '@/api/yfth.js';

export default {
	data() {
		return { loading: true, error: '', list: [] };
	},
	onLoad() {
		this.load();
	},
	methods: {
		load() {
			this.loading = true;
			this.error = '';
			getYfthPackageList().then((res) => {
				this.list = (res.data && res.data.list) || [];
			}).catch((err) => {
				this.error = (err && err.msg) || '套餐列表读取失败';
			}).finally(() => {
				this.loading = false;
			});
		},
		openDetail(item) {
			uni.navigateTo({ url: '/pages/yfth/package/detail?id=' + item.id });
		},
		price(item) {
			return (item.rule && item.rule.package_price) || item.base_price || '0.00';
		},
		monthCount(item) {
			return (item.rule && item.rule.month_count) || item.benefit_months || 0;
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding-bottom: 36rpx; background: #f5f7f8; }
.hero { padding: 54rpx 32rpx 46rpx; color: #fff; background: linear-gradient(135deg, #204b45, #2f7668); }
.title { font-size: 42rpx; font-weight: 700; }
.sub { margin-top: 16rpx; font-size: 26rpx; line-height: 1.6; opacity: .9; }
.list { padding: 24rpx; }
.package { display: flex; align-items: center; justify-content: space-between; gap: 24rpx; margin-bottom: 18rpx; padding: 28rpx; background: #fff; border-radius: 12rpx; }
.package-main { flex: 1; min-width: 0; }
.name { font-size: 32rpx; font-weight: 650; color: #20312e; }
.summary { margin-top: 12rpx; color: #66716f; font-size: 25rpx; line-height: 1.5; }
.meta { margin-top: 14rpx; color: #8a6a36; font-size: 24rpx; }
.package-side { flex: 0 0 auto; text-align: right; }
.price { color: #b56d2e; font-size: 34rpx; font-weight: 700; }
.action { margin-top: 14rpx; color: #2f7668; font-size: 24rpx; }
.state { padding: 130rpx 36rpx; color: #6d7675; text-align: center; }
.state button { margin-top: 24rpx; color: #fff; background: #2f7668; }
.error { color: #a23d34; }
</style>
