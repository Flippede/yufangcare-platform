<template>
	<view class="page">
		<view class="hero">
			<view>
				<view class="eyebrow">御方通和合作中心</view>
				<view class="title">我的加盟申请</view>
				<view class="sub">查看申请进度、负责人和最近沟通记录</view>
			</view>
			<button @click="goApply">提交申请</button>
		</view>

		<view v-if="loading" class="empty">正在加载申请记录...</view>
		<view v-else-if="!list.length" class="empty">
			<view class="empty-title">还没有加盟申请</view>
			<view class="empty-desc">提交基础信息后，总部招商顾问会跟进沟通。</view>
			<button @click="goApply">立即提交</button>
		</view>
		<view v-else>
			<view v-for="item in list" :key="item.id" class="card" @click="goDetail(item)">
				<view class="card-top">
					<view>
						<view class="name">{{ item.city }} · {{ item.intention_area }}</view>
						<view class="muted">{{ item.application_no }}</view>
					</view>
					<view class="status">{{ item.status_text }}</view>
				</view>
				<view class="meta">
					<view>联系人：{{ item.name }}</view>
					<view>电话：{{ item.phone_masked }}</view>
					<view>负责人：{{ item.assigned_name }}</view>
					<view>提交：{{ formatTime(item.submit_time) }}</view>
				</view>
				<view class="next">{{ item.next_step }}</view>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthFranchiseApplications } from '@/api/yfth.js';
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';

export default {
	data() {
		return {
			loading: false,
			list: []
		};
	},
	computed: mapGetters(['isLogin']),
	onShow() {
		if (!this.isLogin) {
			toLogin();
			return;
		}
		this.load();
	},
	methods: {
		load() {
			this.loading = true;
			getYfthFranchiseApplications({ page: 1, limit: 20 }).then((res) => {
				this.list = (res.data && res.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		goApply() {
			uni.navigateTo({ url: '/pages/yfth/franchise/apply' });
		},
		goDetail(item) {
			uni.navigateTo({ url: '/pages/yfth/franchise/detail?id=' + item.id });
		},
		formatTime(value) {
			const ts = Number(value || 0);
			if (!ts) return '-';
			const date = new Date(ts * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.hero { border-radius: 22rpx; background: linear-gradient(135deg, #5a3d29, #c49b62); color: #fff; padding: 32rpx; display: flex; justify-content: space-between; gap: 18rpx; align-items: center; }
.eyebrow { color: #f4dfb8; font-size: 22rpx; }
.title { font-size: 40rpx; font-weight: 700; margin-top: 8rpx; }
.sub { color: #fff4df; font-size: 24rpx; margin-top: 8rpx; }
button { background: #fffaf2; color: #6f4c2f; border-radius: 12rpx; height: 66rpx; line-height: 66rpx; font-size: 26rpx; padding: 0 22rpx; margin: 0; }
.card, .empty { margin-top: 22rpx; background: #fff; border-radius: 18rpx; padding: 26rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.card-top { display: flex; justify-content: space-between; gap: 18rpx; }
.name, .empty-title { color: #2d2434; font-size: 30rpx; font-weight: 700; }
.muted, .empty-desc { color: #7d6d61; font-size: 24rpx; margin-top: 8rpx; }
.status { color: #7b4e25; font-size: 24rpx; font-weight: 700; }
.meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12rpx; margin-top: 22rpx; color: #5f5148; font-size: 24rpx; }
.next { margin-top: 18rpx; background: #fff7e9; border-radius: 12rpx; padding: 16rpx; color: #7b4e25; font-size: 24rpx; }
.empty { text-align: center; color: #786b73; }
.empty button { margin: 22rpx auto 0; background: #6f4c2f; color: #fff; }
</style>
