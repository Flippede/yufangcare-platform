<template>
	<view class="page">
		<view class="title">当前经营身份</view>
		<view class="desc">系统自动进入最高有效经营身份；同一身份管理多家门店时仅选择当前门店。</view>
		<view v-if="loading" class="empty">正在读取身份...</view>
		<view v-else>
			<view v-for="item in businessGroups" :key="item.role_code" :class="['card', switching ? 'disabled' : '']" @click="choose(item)">
				<view class="name">{{ item.role_name_cn }}</view>
				<view class="meta">{{ item.store_count ? `可管理 ${item.store_count} 家门店` : '无需门店' }}</view>
				<view v-if="item.store_count" class="store-list">{{ item.store_names.join('、') }}</view>
			</view>
			<view v-if="!businessGroups.length" class="empty">当前账号暂无经营身份，将使用顾客端。</view>
		</view>
	</view>
</template>

<script>
import { dominantYfthIdentities, loadYfthIdentities, resolveDominantYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return { loading: true, switching: false, identities: [] };
	},
	computed: {
		businessGroups() {
			const groups = {};
			dominantYfthIdentities(this.identities).forEach((item) => {
				if (!groups[item.role_code]) groups[item.role_code] = { role_code: item.role_code, role_name_cn: item.role_name_cn, stores: [] };
				if (item.store_id && !groups[item.role_code].stores.some((store) => store.store_id === item.store_id)) groups[item.role_code].stores.push(item);
			});
			return Object.keys(groups).map((key) => {
				const group = groups[key];
				return Object.assign(group, {
					store_count: group.stores.length,
					store_names: group.stores.map((store) => store.store_name || ('门店ID ' + store.store_id))
				});
			});
		}
	},
	onShow() {
		this.loading = true;
		loadYfthIdentities().then((list) => {
			this.identities = list;
			if (!dominantYfthIdentities(list).length) {
				uni.reLaunch({ url: '/pages/index/index' });
			}
			this.loading = false;
		}, (err) => {
			this.identities = [];
			uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			this.loading = false;
			setTimeout(() => {
				uni.reLaunch({ url: '/pages/index/index' });
			}, 300);
		});
	},
	methods: {
		choose(item) {
			if (this.switching) return;
			if (item.store_count > 1) {
				uni.navigateTo({ url: `/pages/yfth/workbench/store_switch?role_code=${item.role_code}` });
				return;
			}
			const storeId = item.stores && item.stores[0] ? item.stores[0].store_id : 0;
			this.switching = true;
			uni.showLoading({ title: '正在切换', mask: true });
			resolveDominantYfthContext(this.identities).then(() => {
				uni.reLaunch({
					url: '/pages/yfth/workbench/index',
					fail: () => uni.showToast({ title: '工作台打开失败，请重试', icon: 'none' })
				});
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.switching = false;
				uni.hideLoading();
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
.card.customer { border: 1rpx solid #d8bd95; background: #fffaf2; }
.name { font-size: 32rpx; font-weight: 700; }
.meta { color: #8a7a68; margin-top: 10rpx; }
.store-list { margin-top: 12rpx; color: #9b713b; font-size: 24rpx; line-height: 1.5; }
.card.disabled { opacity: .62; pointer-events: none; }
button { margin-top: 20rpx; background: #4b315f; color: #fff; border-radius: 12rpx; }
</style>
