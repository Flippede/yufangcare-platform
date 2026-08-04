<template>
	<view class="page">
		<view class="hero">
			<view class="eyebrow">御方通和B端服务</view>
			<view class="title">客服工作台</view>
			<view class="subtitle">当前负责 {{ data.assigned_store_count || 0 }} 家门店</view>
		</view>
		<view v-if="loading" class="state">正在读取门店...</view>
		<view v-else-if="error" class="state error" @click="load">{{ error }}，点击重试</view>
		<block v-else>
			<view class="notice">{{ data.notice }}</view>
			<view v-for="store in (data.stores || [])" :key="store.binding_id" class="store-card">
				<view class="store-head"><text>{{ store.store_name || ('门店 ' + store.store_id) }}</text><text>服务中</text></view>
				<view class="line"><text>店长</text><text>{{ store.manager_name || '未登记' }}</text></view>
				<view class="line"><text>联系电话</text><text>{{ store.manager_phone_masked || store.store_phone || '-' }}</text></view>
				<view class="line"><text>门店地址</text><text>{{ store.store_address || '-' }}</text></view>
				<view class="line"><text>待处理问题</text><text>{{ store.open_issue_count || 0 }}</text></view>
			</view>
			<view v-if="!(data.stores || []).length" class="state">总部尚未分配B端门店</view>
		</block>
		<view class="bottom-nav safe-area-inset-bottom">
			<view class="active"><text>客服工作台</text></view>
			<view @click="goMall"><text>商城</text></view>
			<view @click="goMine"><text>我的</text></view>
		</view>
	</view>
</template>

<script>
import { getYfthCustomerServiceWorkbench } from '@/api/yfth.js';
import { enterYfthBusinessMall, enterYfthBusinessUserCenter } from '@/libs/yfthContext.js';

export default {
	data() { return { loading: true, error: '', data: {} }; },
	onShow() { this.load(); },
	methods: {
		load() {
			this.loading = true; this.error = '';
			return getYfthCustomerServiceWorkbench().then((res) => { this.data = res.data || {}; })
				.catch((err) => { this.error = String((err && err.msg) || err || '客服工作台加载失败'); })
				.finally(() => { this.loading = false; });
		},
		goMall() { enterYfthBusinessMall(); uni.switchTab({ url: '/pages/index/index' }); },
		goMine() { enterYfthBusinessUserCenter(); uni.switchTab({ url: '/pages/user/index' }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx calc(150rpx + env(safe-area-inset-bottom)); box-sizing: border-box; background: #f5f2ed; color: #332b22; }
.hero { padding: 36rpx 30rpx; border-radius: 16rpx; background: #967043; color: #fff; }
.eyebrow { font-size: 23rpx; opacity: .8; }.title { margin-top: 10rpx; font-size: 42rpx; font-weight: 700; }.subtitle { margin-top: 10rpx; font-size: 24rpx; }
.notice { margin: 20rpx 0; padding: 20rpx; border-radius: 12rpx; background: #fff8eb; color: #806c55; font-size: 22rpx; line-height: 1.6; }
.store-card { margin-top: 18rpx; padding: 26rpx; border-radius: 14rpx; background: #fff; }
.store-head,.line { display: flex; justify-content: space-between; gap: 20rpx; }.store-head { margin-bottom: 18rpx; font-size: 29rpx; font-weight: 700; }.store-head text:last-child { color: #5d8064; font-size: 22rpx; }
.line { padding: 12rpx 0; color: #776c61; font-size: 23rpx; }.line text:last-child { color: #39312a; text-align: right; }
.state { padding: 80rpx 20rpx; color: #8b8177; text-align: center; }.error { color: #a34f45; }
.bottom-nav { position: fixed; z-index: 20; right: 0; bottom: 0; left: 0; display: flex; height: 104rpx; background: #fff; box-shadow: 0 -3rpx 14rpx rgba(60,45,30,.08); }
.bottom-nav view { flex: 1; display: flex; align-items: center; justify-content: center; color: #8a8279; font-size: 23rpx; }.bottom-nav .active { color: #8a6033; font-weight: 700; }
@media (min-width: 760px) { .page,.bottom-nav { width: 750rpx; margin-right: auto; margin-left: auto; }.bottom-nav { left: 50%; transform: translateX(-50%); } }
</style>
