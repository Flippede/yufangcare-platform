<template>
	<view class="page">
		<view class="head"><view class="title">线下会员开通</view><view class="sub">{{ context.store_name || '当前授权门店' }}</view></view>
		<view class="scan-card" @click="scanIdentity">
			<view class="scan-icon">扫</view>
			<view><view class="strong">扫描顾客身份码</view><view class="muted">仅可为已归属本店的普通用户开通永久会员</view></view>
			<view class="arrow">›</view>
		</view>
		<view class="actions"><view class="section-title">待处理申请</view><button @click="load">刷新列表</button></view>
		<view v-for="item in rows" :key="item.id" class="card">
			<view class="row"><view><view class="strong">{{ item.enrollment_no }}</view><view class="muted">顾客 UID {{ item.target_uid }} · 线下套餐申请</view></view><view class="status">{{ statusLabel(item.status) }}</view></view>
			<input v-if="item.status === 'pending_store_review'" v-model="reasons[item.id]" class="reason" placeholder="拒绝时填写原因" />
			<view v-if="item.status === 'pending_store_review'" class="buttons"><button class="primary" @click="approve(item)">同意开通</button><button class="reject" @click="reject(item)">拒绝</button></view>
		</view>
		<view v-if="!rows.length" class="empty">暂无会员申请</view>
	</view>
</template>
<script>
import { approveYfthStorePermanentMembership, getYfthStorePermanentMemberships, rejectYfthStorePermanentMembership } from '@/api/yfth.js';
import { currentContext, resolveYfthContext } from '@/libs/yfthContext.js';
export default {
	data() { return { context: {}, rows: [], reasons: {} }; },
	onShow() { this.context = currentContext(); if (!this.allowed()) return this.restore(); this.load(); },
	methods: {
		allowed() { return ['store_manager', 'store_staff'].indexOf(this.context.role_code) !== -1 && Number(this.context.store_id) > 0; },
		restore() { const current = currentContext(); resolveYfthContext(current.role_code, current.store_id).then(ctx => { this.context = ctx; if (!this.allowed()) throw new Error('当前身份无办理权限'); this.load(); }).catch(err => uni.showToast({ title: (err && err.message) || '无办理权限', icon: 'none' })); },
		ctx(extra) { return Object.assign({ role_code: this.context.role_code, store_id: this.context.store_id }, extra || {}); },
		key(action, id) { return 'store_pm_' + action + '_' + (id || 0) + '_' + Date.now(); },
		load() { getYfthStorePermanentMemberships(this.ctx()).then(res => { this.rows = (res.data && res.data.list) || []; }); },
		scanIdentity() { uni.navigateTo({ url: '/pages/yfth/referral/scan?mode=membership_activation' }); },
		approve(item) { uni.showModal({ title: '确认开通会员', content: '确认该顾客已完成线下购买并开通永久会员？', success: res => { if (!res.confirm) return; approveYfthStorePermanentMembership(item.id, this.ctx({ idempotency_key: this.key('approve', item.id) })).then(() => { uni.showToast({ title: '会员已开通' }); this.load(); }); } }); },
		reject(item) { const reason = String(this.reasons[item.id] || '').trim(); if (reason.length < 2) return uni.showToast({ title: '请填写拒绝原因', icon: 'none' }); rejectYfthStorePermanentMembership(item.id, this.ctx({ reason, idempotency_key: this.key('reject', item.id) })).then(() => { uni.showToast({ title: '申请已拒绝' }); this.load(); }); },
		statusLabel(status) { return { pending_store_review: '待审核', activated: '已开通', rejected: '已拒绝' }[status] || status; }
	}
};
</script>
<style scoped>
.page{min-height:100vh;background:#f7f4ef;padding:24rpx;box-sizing:border-box}.head{background:#9b713b;color:#fff;padding:32rpx;border-radius:12rpx}.title{font-size:38rpx;font-weight:700}.sub{margin-top:8rpx;color:#f5ead8}.scan-card,.actions,.buttons,.row{display:flex;gap:14rpx;align-items:center}.scan-card{margin-top:20rpx;padding:26rpx;background:#fff;border:1rpx solid #eadbc4;border-radius:12rpx}.scan-icon{display:flex;width:72rpx;height:72rpx;align-items:center;justify-content:center;border-radius:10rpx;color:#fff;background:#9b713b}.arrow{margin-left:auto;font-size:42rpx;color:#9b713b}.actions{justify-content:space-between;margin:22rpx 0 0}.actions button{width:160rpx;margin:0;font-size:23rpx}.section-title{font-size:30rpx;font-weight:700;color:#342b24}.primary{background:#9b713b;color:#fff}.reject{color:#a34338;background:#f8ece9}.card{background:#fff;border:1rpx solid #eadfce;border-radius:10rpx;padding:24rpx;margin-top:18rpx}.row{justify-content:space-between}.strong{font-weight:700;color:#342b24}.muted{font-size:23rpx;color:#7b6c5e;margin-top:8rpx}.status{color:#9b713b;font-size:22rpx}.buttons{margin-top:16rpx}.buttons button{flex:1;font-size:23rpx}.reason{box-sizing:border-box;width:100%;height:72rpx;margin-top:16rpx;padding:0 18rpx;border:1rpx solid #e4d9ca;border-radius:8rpx;background:#faf8f4}.empty{margin-top:20rpx;padding:70rpx 0;text-align:center;color:#a29585;background:#fff;border-radius:12rpx}
</style>
