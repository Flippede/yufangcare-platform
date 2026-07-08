<template>
	<view class="page">
		<view class="hero">
			<view class="title">签约筹备进度</view>
			<view class="sub">{{ application.application_no || '暂无进入签约阶段的申请' }}</view>
		</view>

		<view v-if="!application.id" class="panel empty">当前还没有可查看的签约筹备流程。</view>
		<view v-else>
			<view class="panel">
				<view class="row"><text>申请状态</text><text>{{ application.status_text }}</text></view>
				<view class="row"><text>合同状态</text><text>{{ contract.status_text || '-' }}</text></view>
				<view class="row"><text>付款状态</text><text>{{ payment.status_text || '-' }}</text></view>
				<view class="row"><text>门店档案</text><text>{{ storeProfile.status_text || '-' }}</text></view>
				<view class="row"><text>验收状态</text><text>{{ acceptance.status_text || '-' }}</text></view>
			</view>

			<view class="grid">
				<view class="tile" @click="go('/pages/yfth/franchise/opening/contract?id=' + contract.id)">合同确认</view>
				<view class="tile" @click="go('/pages/yfth/franchise/opening/payment?id=' + payment.id)">付款凭证</view>
				<view class="tile" @click="go('/pages/yfth/franchise/opening/tasks')">筹备任务</view>
				<view class="tile" @click="go('/pages/yfth/franchise/opening/acceptance')">开店验收</view>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthFranchiseOpening } from '@/api/yfth.js';

export default {
	data() {
		return { application: {}, contract: {}, payment: {}, storeProfile: {}, acceptance: {} };
	},
	onShow() {
		this.load();
	},
	methods: {
		load() {
			getYfthFranchiseOpening().then((res) => {
				const data = res.data || {};
				this.application = data.application || {};
				this.contract = data.contract || {};
				this.payment = data.payment || {};
				this.storeProfile = data.store_profile || {};
				this.acceptance = data.acceptance || {};
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		go(url) {
			if (url.indexOf('undefined') !== -1 || url.indexOf('id=0') !== -1) {
				uni.showToast({ title: '当前节点暂未生成', icon: 'none' });
				return;
			}
			uni.navigateTo({ url });
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.hero, .panel, .tile { background: #fff; border-radius: 18rpx; padding: 26rpx; margin-bottom: 22rpx; box-shadow: 0 10rpx 26rpx rgba(70,45,30,.06); }
.hero { background: linear-gradient(135deg, #5a3d29, #c49b62); color: #fff; }
.title { font-size: 38rpx; font-weight: 700; }
.sub { margin-top: 10rpx; color: #fff4df; font-size: 24rpx; }
.row { display: flex; justify-content: space-between; padding: 16rpx 0; border-bottom: 1px solid #f0e3d6; color: #3a3029; font-size: 26rpx; }
.row:last-child { border-bottom: 0; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; }
.tile { text-align: center; color: #7b4e25; font-weight: 700; }
.empty { color: #786b73; text-align: center; }
</style>
