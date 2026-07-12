<template>
	<view class="page">
		<view class="hero">
			<view class="eyebrow">总部统一商城</view>
			<view class="title">我的归属</view>
			<view class="subtitle">仅展示当前登录账号的正式归属状态</view>
		</view>

		<view v-if="loading" class="state-card">正在读取归属信息...</view>
		<view v-else-if="error" class="state-card error-card">
			<view class="state-title">暂时无法读取</view>
			<view class="state-copy">{{ error }}</view>
			<button class="retry" @click="load">重新加载</button>
		</view>
		<block v-else>
			<view class="status-card">
				<view class="status-head">
					<view>
						<view class="label">当前状态</view>
						<view class="status-title">{{ data.attribution_status_label || '暂未归属' }}</view>
					</view>
					<view :class="['badge', data.attribution_status || 'unassigned']">{{ data.attribution_status || 'unassigned' }}</view>
				</view>
				<view class="tips">{{ data.tips || '暂未形成正式门店归属' }}</view>
			</view>

			<view v-if="data.store" class="store-card">
				<image v-if="data.store.logo" class="store-logo" :src="data.store.logo" mode="aspectFill" />
				<view v-else class="store-logo placeholder">店</view>
				<view class="store-info">
					<view class="store-name">{{ data.store.name || '服务门店' }}</view>
					<view class="store-location">{{ data.store.district || '门店公开地址暂未完善' }}</view>
				</view>
			</view>

			<view class="info-card">
				<view class="info-row">
					<text>一级推荐关系</text>
					<text class="value">{{ data.has_active_referral ? '存在有效关系' : '当前无有效关系' }}</text>
				</view>
				<view class="info-row" v-if="data.bound_at">
					<text>归属形成时间</text>
					<text class="value">{{ formatTime(data.bound_at) }}</text>
				</view>
				<view class="info-row" v-if="data.paused_at">
					<text>暂停时间</text>
					<text class="value">{{ formatTime(data.paused_at) }}</text>
				</view>
				<view class="info-row" v-if="data.closed_at">
					<text>关闭时间</text>
					<text class="value">{{ formatTime(data.closed_at) }}</text>
				</view>
			</view>
		</block>
	</view>
</template>

<script>
import { getYfthMyHqAuthority } from '@/api/yfth.js';
const { createRequestGeneration } = require('@/libs/yfthRequestGeneration.js');

export default {
	data() {
		return { loading: true, error: '', data: {} };
	},
	created() {
		this.requestGeneration = createRequestGeneration();
	},
	onShow() {
		this.requestGeneration.invalidateAll();
		this.clearSensitiveState();
		this.load();
	},
	onHide() {
		this.requestGeneration.invalidateAll();
		this.clearSensitiveState();
	},
	onUnload() {
		this.requestGeneration.destroy();
		this.clearSensitiveState();
	},
	methods: {
		load() {
			const uid = Number(this.$store.getters.uid || 0);
			const identity = 'uid:' + uid;
			const ticket = this.requestGeneration.next('me', identity);
			this.data = {};
			this.loading = true;
			this.error = '';
			getYfthMyHqAuthority().then((res) => {
				if (!this.requestGeneration.isCurrent(ticket, 'uid:' + Number(this.$store.getters.uid || 0))) return;
				this.data = res.data || {};
			}).catch((err) => {
				if (!this.requestGeneration.isCurrent(ticket, identity)) return;
				this.data = {};
				this.error = String((err && err.msg) || '请稍后重试');
			}).finally(() => {
				if (this.requestGeneration.isCurrent(ticket, identity)) this.loading = false;
			});
		},
		clearSensitiveState() {
			this.loading = false;
			this.error = '';
			this.data = {};
		},
		formatTime(value) {
			const date = new Date(Number(value || 0) * 1000);
			if (!Number(value)) return '-';
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f7f4ef; padding: 24rpx; box-sizing: border-box; }
.hero { background: #5a3f2c; color: #fff; padding: 34rpx 30rpx; border-radius: 12rpx; }
.eyebrow { color: #ead7b5; font-size: 22rpx; }
.title { font-size: 42rpx; font-weight: 700; margin-top: 8rpx; }
.subtitle { color: #eee2d2; font-size: 24rpx; margin-top: 8rpx; }
.status-card, .store-card, .info-card, .state-card { background: #fff; border: 1rpx solid #eadfce; border-radius: 12rpx; margin-top: 20rpx; padding: 28rpx; }
.status-head, .store-card, .info-row { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; }
.label { color: #8b7a69; font-size: 23rpx; }
.status-title { color: #2f2823; font-size: 34rpx; font-weight: 700; margin-top: 6rpx; }
.badge { border-radius: 8rpx; padding: 10rpx 16rpx; background: #eee9e2; color: #6c5b4d; font-size: 22rpx; }
.badge.active { background: #e4f2e8; color: #287342; }
.badge.paused { background: #fff1d7; color: #95600b; }
.badge.closed { background: #f7e3e1; color: #9b3f38; }
.tips { color: #6f6358; font-size: 25rpx; line-height: 1.7; margin-top: 22rpx; padding-top: 20rpx; border-top: 1rpx solid #eee6dc; }
.store-card { justify-content: flex-start; }
.store-logo { width: 96rpx; height: 96rpx; border-radius: 10rpx; background: #efe6d8; flex: 0 0 auto; }
.placeholder { display: flex; align-items: center; justify-content: center; color: #7a5b3d; font-size: 36rpx; }
.store-info { min-width: 0; }
.store-name { font-size: 30rpx; color: #342b24; font-weight: 600; }
.store-location { color: #8b7a69; font-size: 23rpx; margin-top: 8rpx; }
.info-row { min-height: 74rpx; color: #665a50; font-size: 25rpx; border-bottom: 1rpx solid #f0e9df; }
.info-row:last-child { border-bottom: 0; }
.value { color: #2f2823; text-align: right; }
.state-card { text-align: center; color: #71655a; padding: 70rpx 30rpx; }
.state-title { color: #3c332c; font-size: 30rpx; font-weight: 600; }
.state-copy { margin-top: 12rpx; font-size: 24rpx; }
.retry { margin-top: 24rpx; width: 240rpx; background: #65472f; color: #fff; border-radius: 8rpx; font-size: 25rpx; }
</style>
