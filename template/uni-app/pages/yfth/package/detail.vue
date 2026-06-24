<template>
	<view class="page">
		<view class="hero">
			<view class="title">{{ detail.package_title || detail.package_name }}</view>
			<view class="price">¥{{ price }}</view>
			<view class="meta">{{ monthCount }}个月权益计划</view>
		</view>
		<view class="section">
			<view class="section-title">套餐内容</view>
			<view class="summary">{{ detail.service_summary || '御方通和家庭康养套餐' }}</view>
			<view v-for="item in benefits" :key="item.id" class="benefit">
				<text>第{{ item.month_no }}月</text>
				<text>{{ item.benefit_name }}</text>
				<text>x{{ item.quantity }}</text>
			</view>
		</view>
		<view class="footer">
			<button class="btn" @click="goStores">选择服务门店</button>
			<button class="ghost" @click="goMine">我的套餐</button>
		</view>
	</view>
</template>

<script>
import { getYfthPackageDetail } from '@/api/yfth.js';

export default {
	data() {
		return {
			id: 0,
			detail: {},
			benefits: []
		};
	},
	computed: {
		price() {
			return (this.detail.rule && this.detail.rule.package_price) || this.detail.base_price || '0.00';
		},
		monthCount() {
			return (this.detail.rule && this.detail.rule.month_count) || this.detail.benefit_months || 0;
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	methods: {
		load() {
			getYfthPackageDetail(this.id).then((res) => {
				this.detail = res.data || {};
				this.benefits = this.detail.benefits || [];
			});
		},
		goStores() {
			uni.navigateTo({ url: '/pages/yfth/package/store_select?id=' + this.id });
		},
		goMine() {
			uni.navigateTo({ url: '/pages/yfth/package/my_packages' });
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	background: #f5f7f8;
	padding-bottom: 140rpx;
}
.hero {
	padding: 56rpx 32rpx 44rpx;
	background: linear-gradient(135deg, #204b45, #2f7668);
	color: #fff;
}
.title {
	font-size: 42rpx;
	font-weight: 700;
}
.price {
	margin-top: 24rpx;
	font-size: 56rpx;
	font-weight: 700;
}
.meta {
	margin-top: 12rpx;
	font-size: 26rpx;
	opacity: 0.86;
}
.section {
	margin: 24rpx;
	padding: 24rpx;
	background: #fff;
	border-radius: 12rpx;
}
.section-title {
	font-size: 32rpx;
	font-weight: 600;
	margin-bottom: 18rpx;
}
.summary {
	color: #5c6670;
	line-height: 1.6;
	margin-bottom: 18rpx;
}
.benefit {
	display: flex;
	justify-content: space-between;
	padding: 18rpx 0;
	border-top: 1px solid #edf0f2;
	font-size: 26rpx;
}
.footer {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	gap: 16rpx;
	padding: 18rpx 24rpx 28rpx;
	background: #fff;
	box-shadow: 0 -8rpx 24rpx rgba(20, 35, 50, 0.08);
}
.btn,
.ghost {
	flex: 1;
	height: 84rpx;
	line-height: 84rpx;
	border-radius: 10rpx;
	font-size: 28rpx;
}
.btn {
	color: #fff;
	background: #2f7668;
}
.ghost {
	color: #2f7668;
	background: #eef7f5;
}
</style>
