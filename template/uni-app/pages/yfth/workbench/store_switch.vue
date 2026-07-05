<template>
	<view class="page">
		<view class="title">切换门店</view>
		<view class="desc">仅展示当前身份由服务端返回的授权门店。</view>
		<view v-if="loading" class="empty">正在读取门店...</view>
		<view v-else-if="!stores.length" class="empty">当前身份暂无可切换门店。</view>
		<view v-else>
			<view v-for="item in stores" :key="item.store_id" class="card" @click="choose(item)">
				<view class="name">{{ item.store_name || ('门店ID ' + item.store_id) }}</view>
				<view class="meta">{{ item.role_name_cn }}</view>
			</view>
		</view>
	</view>
</template>

<script>
import { currentContext, isBusinessRole, loadYfthIdentities, switchYfthStore } from '@/libs/yfthContext.js';

export default {
	data() {
		return { loading: true, context: {}, identities: [] };
	},
	computed: {
		stores() {
			const role = this.context.role_code;
			const map = {};
			this.identities.filter((item) => item.role_code === role && item.store_id).forEach((item) => {
				map[item.store_id] = item;
			});
			return Object.keys(map).map((key) => map[key]);
		}
	},
	onShow() {
		this.context = currentContext();
		if (!isBusinessRole(this.context.role_code)) {
			uni.reLaunch({ url: '/pages/index/index' });
			return;
		}
		this.loading = true;
		loadYfthIdentities().then((list) => {
			this.identities = list;
		}).catch((err) => {
			uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
		}).finally(() => {
			this.loading = false;
		});
	},
	methods: {
		choose(item) {
			switchYfthStore(item.store_id).then(() => {
				uni.redirectTo({ url: '/pages/yfth/workbench/index' });
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 32rpx; }
.title { font-size: 40rpx; font-weight: 700; color: #2d2434; }
.desc { color: #786b73; margin: 12rpx 0 24rpx; }
.card, .empty { background: #fff; border-radius: 16rpx; padding: 26rpx; margin-bottom: 18rpx; }
.name { font-size: 32rpx; font-weight: 700; }
.meta { color: #8a7a68; margin-top: 10rpx; }
</style>
