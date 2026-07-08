<template>
	<view class="page">
		<view class="panel">
			<view class="label">当前场景</view>
			<view class="scene">{{ sceneText }}</view>
			<button class="btn" @click="createCode">生成/查看推荐码</button>
		</view>
		<view v-for="item in codes" :key="item.id" class="card">
			<view class="code">{{ item.code }}</view>
			<view class="meta">状态：{{ item.status }}　门店：{{ item.store_id || '无' }}</view>
		</view>
		<view class="bind panel">
			<view class="label">通过推荐码绑定</view>
			<input v-model="bindCode" class="input" placeholder="输入推荐码" />
			<button class="btn secondary" @click="bind">绑定为候选关系</button>
		</view>
	</view>
</template>

<script>
import { createYfthReferralCode, getYfthReferralCode, bindYfthReferralCode } from '@/api/yfth.js';

export default {
	data() {
		return { scene: 'package_5980', codes: [], bindCode: '' };
	},
	computed: {
		sceneText() {
			return this.scene === 'franchise_opening' ? '加盟开店推荐' : '5980套餐推荐';
		},
	},
	onLoad(options) {
		this.scene = options.scene || 'package_5980';
		this.load();
	},
	methods: {
		load() {
			getYfthReferralCode({ scene: this.scene }).then((res) => {
				this.codes = (res.data && res.data.list) || [];
			});
		},
		createCode() {
			createYfthReferralCode({ scene: this.scene }).then(() => {
				this.load();
			});
		},
		bind() {
			bindYfthReferralCode({ scene: this.scene, code: this.bindCode }).then(() => {
				uni.showToast({ title: '已绑定候选关系' });
			});
		},
	},
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f7f4ee; padding: 24rpx; }
.panel, .card { background: #fff; border-radius: 12rpx; padding: 28rpx; margin-bottom: 20rpx; }
.label { color: #8b7a65; font-size: 24rpx; }
.scene { color: #3a2b18; font-size: 34rpx; font-weight: 600; margin: 8rpx 0 24rpx; }
.btn { background: #8b6b3e; color: #fff; font-size: 28rpx; }
.secondary { background: #b49664; margin-top: 18rpx; }
.code { font-size: 42rpx; font-weight: 700; color: #3a2b18; letter-spacing: 2rpx; }
.meta { color: #8b7a65; font-size: 24rpx; margin-top: 12rpx; }
.input { height: 76rpx; background: #f7f4ee; border-radius: 8rpx; padding: 0 20rpx; margin-top: 16rpx; }
</style>
