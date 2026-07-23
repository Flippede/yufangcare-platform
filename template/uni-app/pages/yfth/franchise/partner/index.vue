<template>
	<view class="page">
		<view class="hero">
			<view class="eyebrow">御方通和招商组织</view>
			<view class="rank">{{ profile.rank_name || '招商合伙人' }}</view>
			<view class="store">{{ profile.store_name || ('门店 ' + profile.primary_store_id) }}</view>
		</view>

		<view v-if="loading" class="empty">正在读取招商身份...</view>
		<view v-else-if="error" class="empty error">{{ error }}</view>
		<block v-else>
			<view class="metrics">
				<view><strong>{{ performance.personal_openings || 0 }}</strong><span>个人有效开店</span></view>
				<view><strong>{{ performance.team_openings || 0 }}</strong><span>团队有效开店</span></view>
				<view><strong>{{ performance.team_size || 0 }}</strong><span>团队人数</span></view>
			</view>

			<view class="panel">
				<view class="panel-head"><view><view class="title">加盟申请二维码</view><view class="hint">仅用于打开申请并记录招商来源，不会直接开店或授予身份</view></view><button @click="createInvite">生成/替换</button></view>
				<view v-if="invite.invite_path" class="invite-box">
					<view class="qr-wrap">
						<zb-code v-if="shareUrl" :key="qrRenderKey" ref="partnerQr" cid="yfth-partner-qr" :val="shareUrl" :size="390" :show="false" :onval="false" :loadMake="false" foreground="#75512f" @result="onQrReady" />
						<image v-if="qrImage" class="partner-qr-image" :src="qrImage" mode="aspectFit" />
						<view v-else-if="qrError" class="qr-state error" @click="queueQrRender">{{ qrError }}，点击重试</view>
						<view v-else class="qr-state">二维码生成中...</view>
					</view>
					<view class="invite-link">{{ shareUrl }}</view>
					<view class="invite-actions"><button class="light" @click="copyLink">复制申请链接</button><button class="light" @click="saveQr">保存二维码</button></view>
				</view>
				<view v-else class="inline-empty">点击生成本人的加盟申请码。</view>
			</view>

			<view class="panel">
				<view class="title">我的招商申请</view>
				<view v-if="applications.length"><view v-for="item in applications" :key="item.id" class="row"><view><b>{{ item.name || item.application_no }}</b><span>{{ item.city }} · {{ item.status }}</span></view><em>{{ item.source_status }}</em></view></view>
				<view v-else class="inline-empty">暂无直属招商申请。</view>
			</view>

			<view class="panel">
				<view class="panel-head"><view class="title">团队结构</view><button class="light" @click="loadTeam">刷新</button></view>
				<view v-if="flattenedTeam.length"><view v-for="item in flattenedTeam" :key="item.partner_uid" class="tree-row" :style="{ paddingLeft: (item.depth * 24) + 'rpx' }"><view><b>{{ item.nickname || item.account || ('UID ' + item.partner_uid) }}</b><span>{{ item.rank_name }} · {{ item.status }}</span></view></view></view>
				<view v-else class="inline-empty">暂无直属合伙人。</view>
			</view>

			<view class="panel">
				<view class="title">晋级与保级</view>
				<view class="rule-line"><span>当前职级</span><b>{{ profile.rank_name }}</b></view>
				<view class="rule-json">晋级条件：{{ formatRule(promotionRule.promotion_config) }}</view>
				<view class="rule-json">保级要求：{{ formatRule(promotionRule.retention_config) }}</view>
				<view class="hint">系统只生成资格和预警，总部人工决定晋级、降级、暂停或退出。</view>
				<view v-if="promotionApplication.id" class="promotion-status">最近申请：{{ promotionApplication.status }} · {{ promotionApplication.target_rank }}</view>
				<button v-if="data.next_rank && (!promotionApplication.id || promotionApplication.status !== 'pending')" class="promotion-button" @click="applyPromotion">申请晋升下一职级</button>
			</view>

			<view class="panel">
				<view class="title">采购分润</view>
				<view class="reward-grid">
					<view><strong>￥{{ procurement.pending || '0.00' }}</strong><span>待结算</span></view>
					<view><strong>￥{{ procurement.settled || '0.00' }}</strong><span>已结算</span></view>
					<view><strong>￥{{ procurement.reversed || '0.00' }}</strong><span>退款冲正</span></view>
				</view>
				<view v-for="item in recentProcurement" :key="item.id" class="profit-row">
					<view><b>{{ item.rank_code }}</b><span>采购单 {{ item.purchase_order_id }} · {{ item.status }}</span></view>
					<strong>{{ cent(item.amount_cent) }}</strong>
				</view>
				<view v-if="!recentProcurement.length" class="inline-empty">暂无店长采购分润。</view>
			</view>

			<view class="panel">
				<view class="title">开店服务奖励</view>
				<view class="reward-grid">
					<view><strong>￥{{ openingService.pending || '0.00' }}</strong><span>待结算</span></view>
					<view><strong>￥{{ openingService.settled || '0.00' }}</strong><span>已结算</span></view>
					<view><strong>￥{{ openingService.reversed || '0.00' }}</strong><span>已冲正</span></view>
				</view>
				<view class="hint">当前仅县级合伙人按有效开店记录获得服务奖励，上级职级接口保留但默认金额为 0。</view>
			</view>

			<view v-if="profile.rank_code === 'platform_director'" class="panel">
				<view class="title">平台加权分红</view>
				<view class="reward-grid">
					<view><strong>￥{{ platformDividend.pending || '0.00' }}</strong><span>待结算</span></view>
					<view><strong>￥{{ platformDividend.settled || '0.00' }}</strong><span>已结算</span></view>
					<view><strong>￥{{ platformDividend.reversed || '0.00' }}</strong><span>已冲正</span></view>
				</view>
				<view class="hint">按总部发布规则，以平台采购业绩池和有效开店权重生成批次；采购分润比例可独立设为 0。</view>
			</view>

			<view class="panel">
				<view class="title">招商收益候选</view>
				<view class="reward-grid"><view><strong>￥{{ rewards.pending || '0.00' }}</strong><span>待确认</span></view><view><strong>￥{{ rewards.confirmed || '0.00' }}</strong><span>已确认</span></view><view><strong>￥{{ rewards.settled || '0.00' }}</strong><span>线下已结算</span></view></view>
				<view class="hint">仅记录线下业务事实，不代表平台自动打款。</view>
			</view>
		</block>
	</view>
</template>

<script>
import { applyYfthPartnerPromotion, createYfthPartnerInvite, getYfthPartnerTeam, getYfthPartnerWorkbench } from '@/api/yfth.js';
import zbCode from '@/components/zb-code/zb-code.vue';

export default {
	components: { zbCode },
	data() { return { loading: true, error: '', data: {}, team: [], invite: {}, qrImage: '', qrError: '', qrRenderKey: 0, qrRenderTimer: null }; },
	computed: {
		profile() { return this.data.profile || {}; }, performance() { return this.data.performance || {}; },
		applications() { return this.data.my_applications || []; }, rewards() { return this.data.reward_summary || {}; },
		profitSummary() { return this.data.profit_summary || {}; },
		procurement() { return this.profitSummary.procurement || {}; },
		openingService() { return this.profitSummary.opening_service || {}; },
		platformDividend() { return this.profitSummary.platform_dividend || {}; },
		recentProcurement() { return this.profitSummary.recent_procurement || []; },
		promotionRule() { return this.data.promotion_rule || {}; },
		promotionApplication() { return this.data.promotion_application || {}; },
		flattenedTeam() {
			const rows = [];
			const walk = (items, depth) => (items || []).forEach((item) => {
				rows.push({ ...item, depth });
				walk(item.children || [], depth + 1);
			});
			walk(this.team, 0);
			return rows;
		},
		shareUrl() {
			const path = this.invite.invite_path || '';
			// #ifdef H5
			return `${window.location.origin}${path}`;
			// #endif
			// #ifndef H5
			return path;
			// #endif
		}
	},
	watch: {
		shareUrl(value, previous) {
			if (value && value !== previous) this.queueQrRender();
		}
	},
	onLoad() { this.load(); },
	beforeDestroy() { if (this.qrRenderTimer) clearTimeout(this.qrRenderTimer); },
	methods: {
		load() { this.loading = true; this.error = ''; return getYfthPartnerWorkbench().then((res) => { this.data = res.data || {}; return this.loadTeam(); }).catch((err) => { this.error = String((err && err.msg) || err || '招商身份读取失败'); }).finally(() => { this.loading = false; }); },
		loadTeam() { return getYfthPartnerTeam().then((res) => { this.team = (res.data || {}).tree || []; }); },
		createInvite() { createYfthPartnerInvite({ request_id: `partner-invite-${Date.now()}` }).then((res) => { this.invite = res.data || {}; uni.showToast({ title: '申请码已生成', icon: 'success' }); }).catch((err) => { uni.showToast({ title: (err && (err.msg || err.message)) || '申请码生成失败', icon: 'none' }); }); },
		applyPromotion() { uni.showModal({ title: '申请晋升', editable: true, placeholderText: '请说明已达成的晋升条件', success: (modal) => { if (!modal.confirm || !String(modal.content || '').trim()) return; applyYfthPartnerPromotion({ reason: String(modal.content).trim() }).then(() => { uni.showToast({ title: '已提交总部审核', icon: 'success' }); this.load(); }).catch((err) => { uni.showToast({ title: (err && (err.msg || err.message)) || '提交失败', icon: 'none' }); }); } }); },
		copyLink() { uni.setClipboardData({ data: this.shareUrl }); },
		queueQrRender() {
			if (!this.shareUrl) return;
			if (this.qrRenderTimer) clearTimeout(this.qrRenderTimer);
			this.qrImage = '';
			this.qrError = '';
			this.qrRenderKey += 1;
			const expectedUrl = this.shareUrl;
			this.$nextTick(() => {
				this.qrRenderTimer = setTimeout(() => {
					this.qrRenderTimer = null;
					try {
						if (!this.$refs.partnerQr || this.shareUrl !== expectedUrl) throw new Error('qr_component_not_ready');
						this.$refs.partnerQr._makeCode();
					} catch (error) {
						this.qrError = '二维码生成失败';
					}
				}, 0);
			});
		},
		onQrReady(value) {
			const result = typeof value === 'string' ? value : '';
			this.qrImage = result;
			this.qrError = result ? '' : '二维码生成失败';
		},
		saveQr() {
			if (!this.qrImage) return uni.showToast({ title: '二维码尚未生成', icon: 'none' });
			// #ifdef H5
			const anchor = document.createElement('a');
			anchor.href = this.qrImage;
			anchor.download = `加盟申请码-${Date.now()}.png`;
			document.body.appendChild(anchor);
			anchor.click();
			document.body.removeChild(anchor);
			// #endif
			// #ifndef H5
			if (this.$refs.partnerQr && this.$refs.partnerQr._saveCode) this.$refs.partnerQr._saveCode();
			// #endif
		},
		cent(value) { return `￥${(Number(value || 0) / 100).toFixed(2)}`; },
		formatRule(value) { const data = value || {}; const entries = Object.keys(data).map((key) => key + '=' + data[key]); return entries.length ? entries.join('，') : '总部人工审核'; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; box-sizing: border-box; background: #f5efe5; color: #2d2434; }.hero { padding: 32rpx; border-radius: 16rpx; background: #8b633b; color: #fff; }.eyebrow { color: #f3dfba; font-size: 22rpx; }.rank { margin-top: 8rpx; font-size: 40rpx; font-weight: 700; }.store { margin-top: 8rpx; color: #fff1d7; font-size: 24rpx; }.metrics { display: grid; grid-template-columns: repeat(3,1fr); margin-top: 18rpx; background: #fff; border-radius: 16rpx; }.metrics>view,.reward-grid>view { padding: 24rpx 8rpx; text-align: center; }.metrics strong,.reward-grid strong { display: block; color: #74502e; font-size: 32rpx; }.metrics span,.reward-grid span { display: block; margin-top: 8rpx; color: #8d8178; font-size: 21rpx; }.panel { margin-top: 18rpx; padding: 24rpx; border-radius: 16rpx; background: #fff; box-shadow: 0 8rpx 24rpx rgba(75,50,30,.05); }.panel-head,.row,.rule-line { display: flex; align-items: center; justify-content: space-between; gap: 14rpx; }.title { font-size: 30rpx; font-weight: 700; }.hint,.inline-empty,.row span,.tree-row span { display: block; margin-top: 8rpx; color: #91847a; font-size: 22rpx; line-height: 1.55; }.panel button { margin: 0; padding: 0 18rpx; height: 58rpx; line-height: 58rpx; border-radius: 10rpx; background: #75512f; color: #fff; font-size: 23rpx; }.panel button.light { background: #f6ecdc; color: #75512f; }.invite-box { margin-top: 20rpx; text-align: center; }.qr-wrap { display: flex; align-items: center; justify-content: center; min-height: 390rpx; padding: 12rpx 0; }.partner-qr-image { display: block; width: 390rpx; height: 390rpx; }.qr-state { display: flex; align-items: center; justify-content: center; width: 390rpx; height: 390rpx; color: #91847a; font-size: 23rpx; background: #faf6ef; }.invite-actions { display: flex; justify-content: center; gap: 16rpx; }.invite-link { margin: 14rpx 0; padding: 14rpx; border-radius: 10rpx; background: #faf6ef; color: #786a60; font-size: 20rpx; word-break: break-all; }.row,.tree-row { padding-top: 18rpx; padding-bottom: 18rpx; border-bottom: 1rpx solid #f0e8de; }.row b,.tree-row b { font-size: 25rpx; }.row em { color: #a8753e; font-size: 22rpx; font-style: normal; }.rule-line { margin-top: 18rpx; padding: 16rpx; background: #faf6ef; }.rule-json { margin-top: 12rpx; color: #705e51; font-size: 23rpx; line-height: 1.6; }.reward-grid { display: grid; grid-template-columns: repeat(3,1fr); }.empty { margin-top: 20rpx; padding: 40rpx; text-align: center; background: #fff; border-radius: 16rpx; }.error { color: #c44; }
.promotion-status { margin-top: 16rpx; color: #8b633b; font-size: 23rpx; }.panel button.promotion-button { width: 100%; margin-top: 18rpx; }
.profit-row { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; padding: 18rpx 0; border-top: 1rpx solid #f0e8de; }.profit-row b,.profit-row strong { color: #74502e; font-size: 25rpx; }.profit-row span { display: block; margin-top: 5rpx; color: #91847a; font-size: 21rpx; }
</style>
