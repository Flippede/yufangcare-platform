<template>
	<view class="page">
		<view class="panel">
			<view class="row"><text>套餐</text><text>{{ detail.package_name }}</text></view>
			<view class="row"><text>价格</text><text>¥{{ price }}</text></view>
			<view class="row"><text>权益周期</text><text>{{ monthCount }}个月</text></view>
			<view class="row"><text>归属门店</text><text>{{ storeName || (storeId ? ('门店 ' + storeId) : '读取中') }}</text></view>
			<view class="notice">本套餐在线提交申请、线下完成购买。所属门店店长或店员确认后，系统开通永久会员并按现有规则处理推荐奖励。</view>
			<view v-if="storeError" class="error">{{ storeError }}</view>
		</view>
		<button class="btn" :disabled="submitting || !storeReady" @click="submitApplication">{{ submitting ? '提交中' : (storeReady ? '确认并申请' : '等待归属门店') }}</button>
	</view>
</template>

<script>
import { applyYfthPermanentMembership, getYfthPackageDetail, getYfthPackageMembershipMe, getYfthPackageRulePreview } from '@/api/yfth.js';
export default {
	data() {
		return { id: 0, storeId: 0, storeName: '', storeReady: false, storeError: '', detail: {}, preview: { rule: {} }, submitting: false };
	},
	computed: { price() { return this.preview.rule.package_price || '0.00'; }, monthCount() { return this.preview.rule.month_count || 0; } },
	onLoad(options) {
		this.id = Number(options.id || 0); this.storeId = Number(options.store_id || 0); this.storeName = decodeURIComponent(String(options.store_name || ''));
		getYfthPackageDetail(this.id).then((res) => { this.detail = res.data || {}; });
		getYfthPackageRulePreview(this.id).then((res) => { this.preview = res.data || { rule: {} }; });
		this.loadAuthoritativeStore();
	},
	methods: {
		loadAuthoritativeStore() {
			this.storeReady = false; this.storeError = '';
			return getYfthPackageMembershipMe().then((res) => {
				const profile = (res && res.data) || {};
				const purchaseStore = profile.purchase_store || {};
				const storeId = Number(purchaseStore.store_id || 0);
				if (storeId < 1) throw new Error('当前账号尚未绑定归属门店，请先扫描门店获客码完成绑定');
				this.storeId = storeId;
				this.storeName = purchaseStore.store_name || this.storeName || '';
				this.storeReady = true;
			}).catch((err) => {
				this.storeError = (err && (err.msg || err.message)) || '归属门店读取失败';
			});
		},
		submitApplication() {
			if (this.submitting || !this.storeReady) return;
			this.submitting = true;
			applyYfthPermanentMembership({
				store_id: this.storeId,
				idempotency_key: `offline_membership_apply_${this.id}_${Date.now()}`
			}).then(() => {
				uni.showModal({
					title: '申请已提交',
					content: '请联系归属门店线下办理，店长或店员确认后自动开通会员。',
					showCancel: false,
					success: () => uni.redirectTo({ url: '/pages/yfth/permanent_membership/index' })
				});
			}).catch((err) => this.$util.Tips({ title: (err && (err.msg || err.message)) || String(err || '申请提交失败') }))
				.finally(() => { this.submitting = false; });
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; background: #f5f2ed; }.panel { margin-bottom: 20rpx; padding: 24rpx; border-radius: 12rpx; background: #fff; }
.row { display: flex; justify-content: space-between; gap: 24rpx; padding: 16rpx 0; border-bottom: 1px solid #edf0f2; color: #312a22; font-size: 28rpx; }.row text:last-child { text-align: right; word-break: break-all; }
.notice { margin-top: 20rpx; padding: 18rpx; border: 1px solid #ead6b5; border-radius: 8rpx; background: #fff8ea; color: #79562f; font-size: 24rpx; line-height: 1.6; }
.error { margin-top: 16rpx; color: #c53b2f; font-size: 24rpx; line-height: 1.5; }
.btn { margin-top: 16rpx; height: 86rpx; line-height: 86rpx; border-radius: 10rpx; background: #9a7342; color: #fff; }.btn[disabled] { opacity: .5; }
</style>
