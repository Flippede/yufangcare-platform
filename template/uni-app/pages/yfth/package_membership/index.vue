<template>
	<view class="page">
		<view v-if="loading" class="state">正在读取会员资格...</view>
		<view v-else-if="error" class="state error">
			<text>{{ error }}</text>
			<button @click="load">重新加载</button>
		</view>
		<block v-else>
			<view class="hero">
				<view class="eyebrow">御方通和套餐会员</view>
				<view class="title">{{ isMember ? '永久会员' : '尚未激活' }}</view>
				<view class="sub" v-if="isMember">永久有效 · 归属门店 {{ member.store_id }}</view>
				<view class="sub" v-else>购买并成功激活后获得永久会员资格</view>
			</view>

			<view v-if="!isMember" class="panel purchase-panel">
				<view class="panel-title">购买康养套餐</view>
				<view class="muted">从已发布套餐中选择服务门店，确认协议后使用现有商城支付能力完成购买。</view>
				<button class="primary wide" @click="goPurchase">查看可购买套餐</button>
			</view>

			<view v-if="inviteToken" class="panel">
				<view class="panel-title">我的一级邀请</view>
				<view class="token">{{ inviteToken }}</view>
				<view class="muted">邀请只可由非会员接受，接受后将归属同一门店。</view>
				<view class="actions">
					<button @click="copyInvite">复制邀请码</button>
					<button class="primary" open-type="share">分享邀请</button>
				</view>
			</view>

			<view class="panel">
				<view class="panel-title">{{ isMember ? '邀请非会员' : '接受一级邀请' }}</view>
				<block v-if="isMember">
					<view class="muted">一次仅存在一个有效邀请入口，重新生成会使旧入口失效。</view>
					<button class="primary wide" @click="issueInvite">生成新邀请码</button>
				</block>
				<block v-else>
					<input v-model.trim="acceptToken" class="input" maxlength="64" placeholder="输入64位邀请码" />
					<button class="primary wide" @click="acceptInvite">确认接受</button>
				</block>
			</view>

			<view v-if="isMember" class="panel">
				<view class="panel-head">
					<view class="panel-title">奖励候选</view>
					<text class="link" @click="loadCandidates">刷新</text>
				</view>
				<view v-if="!candidates.length" class="empty">暂无奖励候选记录</view>
				<view v-for="item in candidates" :key="item.candidate_no" class="candidate">
					<view>
						<view class="strong">{{ item.candidate_type === 'package_activation' ? '套餐激活' : '普通商城消费' }}</view>
					<view class="muted">候选编号 {{ item.candidate_no }} · {{ candidateStatus(item.status) }}</view>
					<view v-if="item.status === 'settled'" class="muted">门店已记录线下结算，平台不会自动打款</view>
					</view>
					<view class="amount">{{ money(item.reward_amount_cent) }}</view>
				</view>
				<view class="notice">待确认收益，不代表已支付、已结算或已打款；全额退款后对应候选将失效。</view>
			</view>
		</block>
	</view>
</template>

<script>
import {
	acceptYfthDirectReferralInvite,
	getYfthDirectReferralCandidates,
	getYfthPackageMembershipMe,
	issueYfthDirectReferralInvite
} from '@/api/yfth.js';

export default {
	data() {
		return {
			loading: true, error: '', profile: {}, candidates: [], inviteToken: '', acceptToken: ''
		};
	},
	computed: {
		isMember() { return Boolean(this.profile.membership && this.profile.membership.is_member); },
		member() { return (this.profile.membership && this.profile.membership.member) || {}; }
	},
	onLoad(options) {
		this.acceptToken = String((options && options.invite_token) || '');
		this.load();
	},
	onShareAppMessage() {
		return {
			title: '邀请你加入御方通和',
			path: `/pages/yfth/package_membership/index?invite_token=${this.inviteToken}`
		};
	},
	methods: {
		load() {
			this.loading = true; this.error = '';
			getYfthPackageMembershipMe().then((res) => {
				this.profile = res.data || {};
				if (this.isMember) this.loadCandidates();
			}).catch((err) => { this.error = (err && err.msg) || '会员资格读取失败'; })
				.finally(() => { this.loading = false; });
		},
		issueInvite() {
			issueYfthDirectReferralInvite({ request_id: `invite-${Date.now()}` }).then((res) => {
				this.inviteToken = (res.data && res.data.invite_token) || '';
				uni.showToast({ title: '邀请码已生成', icon: 'success' });
			});
		},
		acceptInvite() {
			if (!/^[a-f0-9]{64}$/.test(this.acceptToken)) {
				uni.showToast({ title: '请输入有效邀请码', icon: 'none' }); return;
			}
			const operation = `invite-accept-${Date.now()}`;
			acceptYfthDirectReferralInvite({
				invite_token: this.acceptToken, idempotency_key: operation, request_id: operation
			}).then(() => {
				uni.showToast({ title: '已绑定归属门店', icon: 'success' }); this.acceptToken = ''; this.load();
			});
		},
		goPurchase() {
			uni.navigateTo({ url: '/pages/yfth/package/list' });
		},
		loadCandidates() {
			getYfthDirectReferralCandidates({ page: 1, limit: 20 }).then((res) => {
				this.candidates = (res.data && res.data.list) || [];
			});
		},
		copyInvite() {
			uni.setClipboardData({ data: this.inviteToken });
		},
		candidateStatus(status) {
			return ({ pending: '待确认', confirmed: '已确认', settled: '已结算', cancelled: '已取消' })[status] || status;
		},
		money(value) { return `¥${(Number(value || 0) / 100).toFixed(2)}`; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; background: #f5f4f1; box-sizing: border-box; }
.hero { padding: 42rpx 32rpx; color: #fff; background: #28584f; border-radius: 12rpx; }
.eyebrow { font-size: 24rpx; opacity: .82; }
.title { margin-top: 12rpx; font-size: 44rpx; font-weight: 700; }
.sub { margin-top: 14rpx; font-size: 26rpx; opacity: .9; }
.panel { margin-top: 22rpx; padding: 28rpx; background: #fff; border-radius: 12rpx; }
.panel-head { display: flex; align-items: center; justify-content: space-between; }
.panel-title { font-size: 31rpx; font-weight: 650; }
.muted { margin-top: 10rpx; color: #77746e; font-size: 24rpx; line-height: 1.55; }
.token { margin-top: 18rpx; padding: 18rpx; word-break: break-all; background: #f4f7f6; font-family: monospace; font-size: 24rpx; }
.actions { display: flex; gap: 16rpx; margin-top: 20rpx; }
.actions button { flex: 1; }
.primary { color: #fff; background: #28584f; }
.wide { width: 100%; margin-top: 22rpx; }
.input { height: 82rpx; margin-top: 20rpx; padding: 0 20rpx; border: 1px solid #d7d4ce; border-radius: 8rpx; }
.candidate { display: flex; justify-content: space-between; align-items: center; min-height: 94rpx; border-bottom: 1px solid #eceae5; }
.strong { font-weight: 600; }
.amount { color: #9b662a; font-weight: 700; }
.notice { margin-top: 18rpx; padding: 18rpx; color: #7b5a2c; background: #fbf5e9; font-size: 23rpx; line-height: 1.5; }
.state { padding: 160rpx 40rpx; text-align: center; color: #6c6a65; }
.state button { margin-top: 24rpx; }
.error { color: #a23d34; }
.empty { padding: 40rpx 0; text-align: center; color: #8b8984; }
.link { color: #28584f; }
</style>
