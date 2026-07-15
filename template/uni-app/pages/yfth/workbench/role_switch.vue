<template>
	<view class="page">
		<view class="title">选择身份</view>
		<view class="desc">身份来自服务端，前端不能伪造角色。</view>
		<view v-if="loading" class="empty">正在读取身份...</view>
		<view v-else>
			<view class="card customer" @click="chooseCustomer">
				<view class="name">顾客</view>
				<view class="meta">返回御方通和商城端</view>
			</view>
			<view v-for="item in businessIdentities" :key="item.identity_key" class="card" @click="choose(item)">
				<view class="name">{{ item.role_name_cn }}</view>
				<view class="meta">{{ item.store_name || (item.store_id ? ('门店ID ' + item.store_id) : '无需门店') }}</view>
			</view>
			<view v-if="!businessIdentities.length" class="empty">当前账号暂无经营身份，仅可使用顾客端。</view>
		</view>
	</view>
</template>

<script>
import { clearYfthContext, isBusinessRole, loadYfthIdentities, switchYfthRole } from '@/libs/yfthContext.js';

export default {
	data() {
		return { loading: true, identities: [] };
	},
	computed: {
		businessIdentities() {
			return this.identities.filter((item) => isBusinessRole(item.role_code));
		}
	},
	onShow() {
		this.loading = true;
		loadYfthIdentities().then((list) => {
			this.identities = list;
			this.loading = false;
		}, (err) => {
			clearYfthContext();
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
			switchYfthRole(item.role_code, item.store_id || 0).then(() => {
				uni.redirectTo({ url: '/pages/yfth/workbench/index' });
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		chooseCustomer() {
			switchYfthRole('customer', 0).then(() => {
				uni.reLaunch({ url: '/pages/index/index' });
			}).catch((err) => {
				clearYfthContext();
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
.card.customer { border: 1rpx solid #d8bd95; background: #fffaf2; }
.name { font-size: 32rpx; font-weight: 700; }
.meta { color: #8a7a68; margin-top: 10rpx; }
button { margin-top: 20rpx; background: #4b315f; color: #fff; border-radius: 12rpx; }
</style>
