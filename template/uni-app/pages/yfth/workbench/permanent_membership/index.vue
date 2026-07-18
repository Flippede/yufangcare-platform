<template>
	<view class="page">
		<view class="head"><view class="title">永久会员办理</view><view class="sub">{{ context.store_name || '当前授权门店' }}</view></view>
		<view class="actions"><button class="primary" @click="create">新建办理</button><button @click="load">刷新列表</button></view>
		<view v-for="item in rows" :key="item.id" class="card">
			<view class="row"><view><view class="strong">{{ item.enrollment_no }}</view><view class="muted">顾客 UID {{ item.target_uid || '待绑定' }} · ￥9800.00</view></view><view class="status">{{ item.status }}</view></view>
			<view class="buttons"><button @click="bind(item)">扫码绑定</button><button @click="payment(item)">确认收款</button><button @click="code(item)">生成确认码</button></view>
		</view>
		<view v-if="issuedToken" class="code-card"><view class="strong">会员确认码</view><textarea v-model="issuedToken" readonly class="token" /><view class="muted">仅绑定顾客本人可确认，刷新后旧码失效。</view></view>
	</view>
</template>
<script>
import { bindYfthStorePermanentMembership, codeYfthStorePermanentMembership, createYfthStorePermanentMembership, getYfthStorePermanentMemberships, payYfthStorePermanentMembership } from '@/api/yfth.js';
import { currentContext, resolveYfthContext } from '@/libs/yfthContext.js';
export default {
	data() { return { context: {}, rows: [], issuedToken: '' }; },
	onShow() { this.context = currentContext(); if (!this.allowed()) return this.restore(); this.load(); },
	methods: {
		allowed() { return ['franchisee', 'store_manager'].indexOf(this.context.role_code) !== -1 && Number(this.context.store_id) > 0; },
		restore() { const current = currentContext(); resolveYfthContext(current.role_code, current.store_id).then(ctx => { this.context = ctx; if (!this.allowed()) throw new Error('当前身份无办理权限'); this.load(); }).catch(err => uni.showToast({ title: (err && err.message) || '无办理权限', icon: 'none' })); },
		ctx(extra) { return Object.assign({ role_code: this.context.role_code, store_id: this.context.store_id }, extra || {}); },
		key(action, id) { return 'store_pm_' + action + '_' + (id || 0) + '_' + Date.now(); },
		load() { getYfthStorePermanentMemberships(this.ctx()).then(res => { this.rows = (res.data && res.data.list) || []; }); },
		create() { createYfthStorePermanentMembership(this.ctx({ idempotency_key: this.key('create') })).then(() => { uni.showToast({ title: '已创建' }); this.load(); }); },
		bind(item) { uni.scanCode({ success: res => bindYfthStorePermanentMembership(item.id, this.ctx({ identity_token: this.token(res.result), idempotency_key: this.key('bind', item.id) })).then(() => { uni.showToast({ title: '顾客已绑定' }); this.load(); }) }); },
		payment(item) { uni.showModal({ title: '确认线下收款', content: '确认已收取固定 9800 元？', success: res => { if (res.confirm) payYfthStorePermanentMembership(item.id, this.ctx({ idempotency_key: this.key('payment', item.id) })).then(() => { uni.showToast({ title: '已确认收款' }); this.load(); }); } }); },
		code(item) { codeYfthStorePermanentMembership(item.id, this.ctx()).then(res => { this.issuedToken = (res.data && res.data.token) || ''; }); },
		token(value) { const text = String(value || '').trim(); const match = text.match(/[?&](token|identity_token)=([^&]+)/); return match ? decodeURIComponent(match[2]) : text; },
	}
};
</script>
<style scoped>
.page{min-height:100vh;background:#f3f5f4;padding:24rpx;box-sizing:border-box}.head{background:#294d40;color:#fff;padding:32rpx;border-radius:12rpx}.title{font-size:38rpx;font-weight:700}.sub{margin-top:8rpx;color:#d6e5df}.actions,.buttons,.row{display:flex;gap:14rpx;align-items:center}.actions{margin:20rpx 0}.actions button{flex:1}.primary{background:#315f4f;color:#fff}.card,.code-card{background:#fff;border:1rpx solid #dce4e0;border-radius:10rpx;padding:24rpx;margin-top:18rpx}.row{justify-content:space-between}.strong{font-weight:700;color:#273c35}.muted{font-size:23rpx;color:#728079;margin-top:8rpx}.status{color:#315f4f;font-size:22rpx}.buttons{margin-top:20rpx}.buttons button{flex:1;font-size:23rpx}.token{box-sizing:border-box;width:100%;height:130rpx;margin-top:16rpx;padding:16rpx;background:#f4f7f5;border:1rpx solid #d5dfda;border-radius:8rpx;font-size:22rpx}
</style>
