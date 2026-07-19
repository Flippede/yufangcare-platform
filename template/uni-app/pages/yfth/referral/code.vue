<template>
	<view class="page">
		<view v-if="loading" class="state">正在准备推广码...</view>
		<view v-else-if="error" class="state error"><text>{{ error }}</text><button @click="load">重新加载</button></view>
		<block v-else>
			<view class="hero">
				<view class="eyebrow">御方通和永久会员</view>
				<view class="title">我的推广码</view>
				<view class="subtitle">邀请非会员加入同一归属门店</view>
			</view>
			<view v-if="!isMember" class="panel empty-panel">
				<view class="panel-title">购买套餐后获得推广资格</view>
				<view class="muted">套餐支付并成功激活后，将获得永久会员资格和一级邀请能力。</view>
				<button class="primary" @click="goPackage">查看康养套餐</button>
			</view>
			<block v-else>
				<view class="panel code-panel">
					<view v-if="inviteLink" class="qr-wrap">
						<zb-code ref="qrcode" cid="yfth-referral-qr" :val="inviteLink" :size="390" :onval="true" :loadMake="true" foreground="#7b572c" @result="onQrReady" />
					</view>
					<view v-else class="qr-placeholder">推广码生成中</view>
					<view class="expiry">有效期至 {{ expiryText }}，失效后可刷新生成</view>
					<view class="actions">
						<button @click="copyLink">复制邀请链接</button>
						<button @click="saveQr">保存二维码</button>
						<button class="primary" @click="issue">刷新推广码</button>
					</view>
					<button class="share-button" open-type="share">分享给好友</button>
				</view>
				<view class="panel stats">
					<view><text class="label">当前归属门店</text><text class="value store">{{ promotion.store_name || ('门店 ' + promotion.store_id) }}</text></view>
					<view><text class="label">已邀请人数</text><text class="value">{{ promotion.invited_count || 0 }}</text></view>
				</view>
				<view class="panel links">
					<view @click="goAttribution"><text>我的归属</text><text>›</text></view>
					<view @click="goRewards"><text>我的奖励</text><text>›</text></view>
				</view>
				<view class="panel direct-panel">
					<view class="direct-heading">
						<view>
							<view class="panel-title">我的直推</view>
							<view class="direct-subtitle">已邀请 {{ referralsCount }} 人</view>
						</view>
						<view class="amount-note">奖励候选 / 线下结算</view>
					</view>
					<view v-if="referralsLoading && !referrals.length" class="direct-state">正在读取直推记录...</view>
					<view v-else-if="referralsError && !referrals.length" class="direct-state direct-error" @click="loadReferrals(true)">{{ referralsError }}，点击重试</view>
					<view v-else-if="!referrals.length" class="direct-state">暂无直推用户</view>
					<view v-else class="direct-list">
						<view v-for="(item, index) in referrals" :key="index" class="direct-item">
							<image v-if="item.avatar" class="direct-avatar" :src="item.avatar" mode="aspectFill" />
							<view v-else class="direct-avatar direct-avatar-fallback">{{ displayInitial(item.display_name) }}</view>
							<view class="direct-user">
								<view class="direct-name">{{ item.display_name }}</view>
								<view class="direct-meta">{{ relationStatusText(item.relation_status) }} · {{ formatDate(item.started_at) }}</view>
							</view>
							<view class="direct-reward">
								<view class="reward-total">¥{{ formatMoney(item.reward_amount_cent) }}</view>
								<view class="reward-detail">待处理 ¥{{ formatMoney(item.pending_amount_cent) }}</view>
								<view class="reward-detail">已结算 ¥{{ formatMoney(item.settled_amount_cent) }}</view>
							</view>
						</view>
						<button v-if="referrals.length < referralsCount" class="load-more" :loading="referralsLoading" @click="loadReferrals(false)">查看更多</button>
					</view>
					<view class="direct-footnote">金额来自现有奖励候选和线下结算台账，不代表平台自动打款或到账。</view>
				</view>
				<view class="notice">推广码只建立御方通和一级推荐和永久归属，不使用 CRMEB 旧分销关系。推荐收益为候选记录，不代表自动到账。</view>
			</block>
		</block>
	</view>
</template>

<script>
import { getYfthPackageMembershipMe, getYfthDirectReferrals, issueYfthDirectReferralInvite } from '@/api/yfth.js';
import zbCode from '@/components/zb-code/zb-code.vue';
import { YFTH_HEADQUARTERS_HOME_ROUTE, yfthReferralAcceptRoute } from '@/libs/yfthReferralNavigation.js';

export default {
	components: { zbCode },
	data() {
		return {
			loading: true,
			error: '',
			profile: {},
			invite: {},
			issuing: false,
			qrImage: '',
			referrals: [],
			referralsCount: 0,
			referralPage: 1,
			referralsLoading: false,
			referralsError: ''
		};
	},
	computed: {
		isMember() { return Boolean(this.profile.membership && this.profile.membership.is_member); },
		promotion() { return this.profile.promotion || {}; },
		inviteLink() {
			if (!this.invite.invite_token) return '';
			const path = yfthReferralAcceptRoute(this.invite.invite_token);
			// #ifdef H5
			return `${window.location.origin}${path}`;
			// #endif
			// #ifndef H5
			return path;
			// #endif
		},
		expiryText() {
			if (!this.invite.expires_at) return '-';
			const date = new Date(Number(this.invite.expires_at) * 1000);
			return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
		}
	},
	onLoad() { this.load(); },
	onShareAppMessage() {
		const path = this.invite.invite_token ? yfthReferralAcceptRoute(this.invite.invite_token) : YFTH_HEADQUARTERS_HOME_ROUTE;
		return { title: '接受御方通和推荐邀请', path };
	},
	methods: {
		load() {
			this.loading = true; this.error = '';
			getYfthPackageMembershipMe().then((res) => {
				this.profile = res.data || {};
				if (this.isMember) return Promise.all([this.issue(), this.loadReferrals(true)]);
			}).catch((err) => { this.error = (err && (err.msg || err.message)) || '推广资格读取失败'; })
				.finally(() => { this.loading = false; });
		},
		issue() {
			if (this.issuing) return Promise.resolve();
			this.issuing = true;
			return issueYfthDirectReferralInvite({ request_id: `promotion-${Date.now()}` }).then((res) => {
				this.invite = res.data || {};
				this.profile.promotion = { ...this.promotion, store_id: this.invite.store_id, store_name: this.invite.store_name, invited_count: this.invite.invited_count };
			}).catch((err) => { this.error = (err && (err.msg || err.message)) || '推广码生成失败'; })
				.finally(() => { this.issuing = false; });
		},
		loadReferrals(reset) {
			if (this.referralsLoading) return Promise.resolve();
			if (reset) {
				this.referralPage = 1;
				this.referrals = [];
			}
			this.referralsLoading = true;
			this.referralsError = '';
			return getYfthDirectReferrals({ page: this.referralPage, limit: 20 }).then((res) => {
				const data = res.data || {};
				const rows = Array.isArray(data.list) ? data.list : [];
				this.referrals = reset ? rows : this.referrals.concat(rows);
				this.referralsCount = Number(data.count || 0);
				if (rows.length) this.referralPage += 1;
			}).catch((err) => {
				this.referralsError = (err && (err.msg || err.message)) || '直推记录读取失败';
			}).finally(() => { this.referralsLoading = false; });
		},
		formatMoney(value) { return (Number(value || 0) / 100).toFixed(2); },
		formatDate(value) {
			if (!value) return '时间未记录';
			const date = new Date(Number(value) * 1000);
			return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
		},
		relationStatusText(status) {
			return ({ active: '推荐中', paused: '已暂停', closed: '已转为会员' })[status] || '关系已变更';
		},
		displayInitial(name) { return String(name || '用').slice(0, 1); },
		copyLink() { if (this.inviteLink) uni.setClipboardData({ data: this.inviteLink }); },
		onQrReady(value) { this.qrImage = String(value || ''); },
		saveQr() {
			if (!this.qrImage) return uni.showToast({ title: '二维码尚未生成', icon: 'none' });
			// #ifdef H5
			const anchor = document.createElement('a');
			anchor.href = this.qrImage;
			anchor.download = `御方通和推广码-${Date.now()}.png`;
			document.body.appendChild(anchor);
			anchor.click();
			document.body.removeChild(anchor);
			uni.showToast({ title: '二维码图片已保存或下载', icon: 'none' });
			return;
			// #endif
			// #ifndef H5
			if (this.$refs.qrcode && this.$refs.qrcode._saveCode) this.$refs.qrcode._saveCode();
			// #endif
		},
		goPackage() { uni.navigateTo({ url: '/pages/yfth/package/list' }); },
		goAttribution() { uni.navigateTo({ url: '/pages/yfth/authority/index' }); },
		goRewards() { uni.navigateTo({ url: '/pages/yfth/referral/ledger' }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; box-sizing: border-box; background: #f5f2ec; color: #332a20; }
.hero { padding: 42rpx 34rpx; border-radius: 12rpx; color: #fff; background: #9b713b; }
.eyebrow { font-size: 23rpx; opacity: .82; }.title { margin-top: 10rpx; font-size: 44rpx; font-weight: 700; }.subtitle { margin-top: 12rpx; font-size: 25rpx; opacity: .9; }
.panel { margin-top: 20rpx; padding: 28rpx; border-radius: 12rpx; background: #fff; }.panel-title { font-size: 31rpx; font-weight: 650; }.muted { margin-top: 12rpx; color: #80776c; font-size: 24rpx; line-height: 1.55; }
.code-panel { text-align: center; }.qr-wrap { display: flex; justify-content: center; padding: 22rpx 0; }.qr-placeholder { padding: 140rpx 0; color: #9a9388; }.expiry { color: #8b8276; font-size: 23rpx; }
.actions { display: flex; gap: 16rpx; margin-top: 24rpx; }.actions button { flex: 1; font-size: 25rpx; }.primary { color: #fff; background: #9b713b; }.share-button { margin-top: 16rpx; color: #7b572c; background: #f7efe2; }
.stats { display: grid; grid-template-columns: 1.5fr 1fr; }.stats>view { display: flex; flex-direction: column; gap: 10rpx; }.label { color: #8b8276; font-size: 23rpx; }.value { font-size: 34rpx; font-weight: 700; }.value.store { font-size: 28rpx; }
.links>view { display: flex; justify-content: space-between; padding: 22rpx 0; border-bottom: 1px solid #eee9e1; }.links>view:last-child { border-bottom: 0; }.notice { margin: 20rpx 0; padding: 22rpx; border: 1px solid #eadbc3; border-radius: 10rpx; color: #7b603d; background: #fbf6ed; font-size: 23rpx; line-height: 1.6; }
.direct-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 20rpx; }.direct-subtitle { margin-top: 8rpx; color: #8b8276; font-size: 23rpx; }.amount-note { flex-shrink: 0; padding: 8rpx 14rpx; border-radius: 6rpx; color: #8b6331; background: #f7efe2; font-size: 20rpx; }.direct-list { margin-top: 16rpx; }.direct-item { display: flex; align-items: center; gap: 18rpx; padding: 22rpx 0; border-bottom: 1px solid #eee9e1; }.direct-item:last-of-type { border-bottom: 0; }.direct-avatar { width: 76rpx; height: 76rpx; flex: 0 0 76rpx; border-radius: 50%; background: #eee9e1; }.direct-avatar-fallback { display: flex; align-items: center; justify-content: center; color: #fff; background: #b78a50; font-size: 30rpx; font-weight: 700; }.direct-user { min-width: 0; flex: 1; }.direct-name { overflow: hidden; color: #332a20; font-size: 27rpx; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }.direct-meta { margin-top: 8rpx; color: #978d80; font-size: 21rpx; }.direct-reward { flex: 0 0 184rpx; text-align: right; }.reward-total { color: #9b713b; font-size: 30rpx; font-weight: 700; }.reward-detail { margin-top: 5rpx; color: #958b7f; font-size: 19rpx; }.direct-state { padding: 42rpx 0 28rpx; text-align: center; color: #978d80; font-size: 24rpx; }.direct-error { color: #a64b42; }.direct-footnote { margin-top: 18rpx; color: #92877a; font-size: 21rpx; line-height: 1.55; }.load-more { margin-top: 20rpx; color: #7b572c; background: #f7efe2; font-size: 24rpx; }
.state { padding: 180rpx 30rpx; text-align: center; color: #7f776d; }.state button { margin-top: 24rpx; }.error { color: #a64b42; }.empty-panel .primary { width: 100%; margin-top: 24rpx; }
</style>
