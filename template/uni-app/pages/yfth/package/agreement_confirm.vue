<template>
	<view class="page">
		<view class="section">
			<view class="title">{{ preview.rule.agreement_title || '套餐服务协议' }}</view>
			<view class="summary">{{ preview.rule.agreement_content_summary }}</view>
		</view>
		<label class="agree" @click="accepted = !accepted">
			<checkbox :checked="accepted" /><text>我已阅读并同意套餐服务协议</text>
		</label>
		<button class="btn" :disabled="!accepted" @click="next">继续</button>
	</view>
</template>

<script>
import { getYfthPackageRulePreview } from '@/api/yfth.js';
export default {
	data() { return { id: 0, storeId: 0, accepted: false, preview: { rule: {} } }; },
	onLoad(options) {
		this.id = Number(options.id || 0); this.storeId = Number(options.store_id || 0);
		getYfthPackageRulePreview(this.id).then((res) => { this.preview = res.data || { rule: {} }; });
	},
	methods: {
		next() { uni.navigateTo({ url: '/pages/yfth/package/payment_confirm?id=' + this.id + '&store_id=' + this.storeId + '&accepted=1' }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 28rpx; background: #f5f2ed; }.section { padding: 28rpx; border-radius: 12rpx; background: #fff; }
.title { margin-bottom: 18rpx; font-size: 34rpx; font-weight: 700; }.summary { color: #4d5963; font-size: 28rpx; line-height: 1.7; }
.agree { display: flex; align-items: center; gap: 12rpx; margin: 28rpx 0; color: #312a22; }.btn { height: 86rpx; line-height: 86rpx; border-radius: 10rpx; background: #9a7342; color: #fff; }.btn[disabled] { opacity: .5; }
</style>
