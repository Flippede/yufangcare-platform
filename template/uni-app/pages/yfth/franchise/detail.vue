<template>
	<view class="page">
		<view class="profile">
			<view>
				<view class="name">{{ application.city }} · {{ application.intention_area }}</view>
				<view class="muted">{{ application.application_no }}</view>
			</view>
			<view class="status">{{ application.status_text }}</view>
		</view>

		<view class="panel">
			<view class="panel-title">申请进度</view>
			<view class="step">{{ application.next_step }}</view>
			<button v-if="canOpenOpening" class="opening-btn" @click="goOpening">查看签约筹备进度</button>
			<view class="line">联系人：{{ application.name }}</view>
			<view class="line">联系电话：{{ application.phone_masked }}</view>
			<view class="line">城市区域：{{ application.city }} {{ application.region }}</view>
			<view class="line">预算：{{ application.budget || '-' }}</view>
			<view class="line">负责人：{{ application.assigned_name }}</view>
			<view class="line">提交时间：{{ formatTime(application.submit_time) }}</view>
			<view v-if="application.remark" class="line">备注：{{ application.remark }}</view>
		</view>

		<view class="panel">
			<view class="panel-title">沟通记录</view>
			<view v-if="!follows.length" class="empty">暂无沟通记录。</view>
			<view v-else>
				<view v-for="item in follows" :key="item.id" class="follow">
					<view class="follow-head">
						<view class="strong">{{ item.type_text }}</view>
						<view class="muted">{{ formatTime(item.follow_time) }}</view>
					</view>
					<view class="content">{{ item.content }}</view>
					<view v-if="item.next_time" class="muted">下次跟进：{{ formatTime(item.next_time) }}</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthFranchiseApplicationDetail } from '@/api/yfth.js';

export default {
	data() {
		return {
			id: 0,
			application: {},
			follows: []
		};
	},
	computed: {
		canOpenOpening() {
			return ['pending_contract', 'signed', 'preparing', 'opened'].indexOf(this.application.status) !== -1;
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	methods: {
		load() {
			if (!this.id) return;
			getYfthFranchiseApplicationDetail(this.id).then((res) => {
				this.application = (res.data && res.data.application) || {};
				this.follows = (res.data && res.data.follow_records) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		formatTime(value) {
			const ts = Number(value || 0);
			if (!ts) return '-';
			const date = new Date(ts * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
		},
		goOpening() {
			uni.navigateTo({ url: '/pages/yfth/franchise/opening/index' });
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.profile, .panel { background: #fff; border-radius: 18rpx; padding: 26rpx; margin-bottom: 22rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.profile, .follow-head { display: flex; justify-content: space-between; align-items: center; gap: 18rpx; }
.name, .panel-title, .strong { color: #2d2434; font-size: 30rpx; font-weight: 700; }
.muted, .line { color: #786b73; font-size: 24rpx; margin-top: 10rpx; line-height: 1.6; }
.status { color: #7b4e25; font-weight: 700; font-size: 24rpx; }
.step { margin-top: 18rpx; background: #fff7e9; color: #7b4e25; border-radius: 12rpx; padding: 16rpx; font-size: 26rpx; }
.opening-btn { margin: 18rpx 0 0; background: #7b4e25; color: #fff; border-radius: 12rpx; height: 68rpx; line-height: 68rpx; font-size: 26rpx; }
.follow { background: #fffaf2; border-radius: 12rpx; padding: 18rpx; margin-top: 16rpx; }
.content { color: #3a3029; font-size: 26rpx; line-height: 1.6; margin-top: 10rpx; }
.empty { color: #786b73; text-align: center; padding: 28rpx 0 8rpx; font-size: 24rpx; }
</style>
