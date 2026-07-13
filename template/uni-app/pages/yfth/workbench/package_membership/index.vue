<template>
	<view class="page">
		<view class="tabs">
			<view :class="['tab', tab === 'members' ? 'active' : '']" @click="changeTab('members')">永久会员</view>
			<view :class="['tab', tab === 'candidates' ? 'active' : '']" @click="changeTab('candidates')">奖励候选</view>
		</view>
		<view class="notice">仅显示当前 Token 所授权的门店数据。候选为待确认收益，不代表已支付，不提供结算、提现或打款。</view>
		<view v-if="loading" class="empty">加载中...</view>
		<view v-else-if="!list.length" class="empty">暂无记录</view>
		<view v-else>
			<view v-for="(item, index) in list" :key="index" class="row">
				<block v-if="tab === 'members'">
					<view>
						<view class="strong">{{ item.membership_no }}</view>
						<view class="muted">UID {{ item.uid }} · 门店 {{ item.store_id }}</view>
					</view>
					<view class="status">{{ item.status }}</view>
				</block>
				<block v-else>
					<view>
						<view class="strong">{{ item.candidate_type === 'package_activation' ? '套餐激活' : '普通商城消费' }}</view>
						<view class="muted">{{ item.candidate_no }} · 推荐人 {{ item.referrer_uid }} · {{ candidateStatus(item.status) }}</view>
					</view>
					<view class="amount">{{ money(item.reward_amount_cent) }}</view>
				</block>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthStorePackageCandidates, getYfthStorePackageMembers } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() { return { tab: 'members', context: {}, list: [], loading: false }; },
	onShow() { this.context = currentContext(); this.load(); },
	methods: {
		changeTab(tab) { this.tab = tab; this.load(); },
		load() {
			this.loading = true;
			const params = { role_code: this.context.role_code, store_id: this.context.store_id, page: 1, limit: 50 };
			const request = this.tab === 'members' ? getYfthStorePackageMembers(params) : getYfthStorePackageCandidates(params);
			request.then((res) => { this.list = (res.data && res.data.list) || []; })
				.catch((err) => { this.list = []; uni.showToast({ title: String((err && err.msg) || err), icon: 'none' }); })
				.finally(() => { this.loading = false; });
		},
		candidateStatus(status) { return status === 'cancelled' ? '已失效' : '待确认'; },
		money(value) { return `¥${(Number(value || 0) / 100).toFixed(2)}`; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; background: #f5f4f1; box-sizing: border-box; }
.tabs { display: flex; gap: 12rpx; }
.tab { flex: 1; padding: 20rpx; text-align: center; color: #57534c; background: #fff; border-radius: 8rpx; }
.tab.active { color: #fff; background: #28584f; }
.notice { margin-top: 18rpx; padding: 18rpx; color: #7b5a2c; background: #fbf5e9; font-size: 23rpx; line-height: 1.5; }
.row { display: flex; align-items: center; justify-content: space-between; min-height: 104rpx; margin-top: 16rpx; padding: 22rpx; background: #fff; border-radius: 8rpx; }
.strong { font-weight: 650; }
.muted { margin-top: 8rpx; color: #77736d; font-size: 24rpx; }
.status { color: #28584f; }
.amount { color: #9b662a; font-weight: 700; }
.empty { margin-top: 18rpx; padding: 70rpx 20rpx; text-align: center; color: #8a8780; background: #fff; border-radius: 8rpx; }
</style>
