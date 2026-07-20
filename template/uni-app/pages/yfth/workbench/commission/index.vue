<template>
	<view class="page">
		<view class="header">
			<view><text class="eyebrow">当前门店佣金账户</text><text class="title">¥ {{ account.hq_withdrawable || '0.00' }}</text><text class="sub">总部可提现总额</text></view>
			<view class="store-name">{{ context.store_name || ('门店 ' + context.store_id) }}</view>
		</view>
		<view class="metrics">
			<view><text>{{ account.own_available || '0.00' }}</text><text>门店自身佣金</text></view>
			<view><text>{{ account.proxy_available || '0.00' }}</text><text>C1代发佣金</text></view>
			<view><text>{{ account.c1_pending || '0.00' }}</text><text>本店C1待付</text></view>
			<view><text>{{ account.hq_frozen || '0.00' }}</text><text>提现审核中</text></view>
		</view>
		<view class="tabs">
			<view v-for="item in tabs" :key="item.key" :class="{ active: tab === item.key }" @click="tab = item.key">{{ item.label }}</view>
		</view>

		<view v-if="tab === 'c1'" class="section">
			<view v-for="item in c1Withdrawals" :key="item.id" class="record">
				<view class="record-head"><text>{{ item.user && item.user.nickname || 'C1用户' }}</text><text>¥ {{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">{{ item.user && item.user.phone_masked }} · {{ item.status === 'paid' ? '提现完成' : '处理中' }}</view>
				<button v-if="item.status === 'pending'" class="outline" @click="completeC1(item)">线下付款后标记提现完成</button>
			</view>
			<view v-if="!c1Withdrawals.length" class="empty">暂无C1提现申请</view>
		</view>

		<view v-else-if="tab === 'store'" class="section">
			<view v-if="canManageSettlement" class="panel">
				<view class="panel-title">向总部申请提现</view>
				<view class="settlement-line">结算账户：{{ settlement.account_no_masked || '尚未绑定' }}</view>
				<view class="input-row"><text>¥</text><input v-model="storeAmount" type="digit" placeholder="输入提现金额" /></view>
				<button class="primary" @click="requestStore">确认申请</button>
			</view>
			<view v-for="item in storeWithdrawals" :key="item.id" class="record">
				<view class="record-head"><text>{{ item.withdrawal_no }}</text><text>¥ {{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">门店自身 {{ item.own_amount || '0.00' }} · C1代发 {{ item.proxy_amount || '0.00' }}</view>
				<view class="status">{{ item.status === 'success' ? '提现成功' : '审核中' }}</view>
			</view>
			<view v-if="!canManageSettlement" class="notice">店员可处理本店C1提现，但不能修改结算账户或向总部申请门店提现。</view>
		</view>

		<view v-else-if="tab === 'account'" class="section">
			<view v-if="canManageSettlement" class="panel form">
				<view class="panel-title">默认结算账户</view>
				<picker :range="accountTypeLabels" @change="accountForm.account_type = Number($event.detail.value) === 1 ? 'company' : 'personal'">
					<view class="picker">账户类型：{{ accountForm.account_type === 'company' ? '企业' : '个人' }}</view>
				</picker>
				<input v-model="accountForm.account_name" placeholder="收款户名" />
				<input v-model="accountForm.account_no" placeholder="银行账号" />
				<input v-model="accountForm.bank_name" placeholder="开户银行" />
				<input v-model="accountForm.bank_branch" placeholder="开户支行" />
				<input v-model="accountForm.reserved_phone" placeholder="预留手机号（可选）" />
				<input v-model="accountForm.contact_name" placeholder="联系人" />
				<input v-model="accountForm.contact_phone" placeholder="联系电话" />
				<button class="primary" @click="saveAccount">保存结算账户</button>
			</view>
			<view v-else class="notice">当前身份没有结算账户维护权限。</view>
		</view>

		<view v-else class="section">
			<view v-for="item in ledger" :key="item.id" class="record">
				<view class="record-head"><text>{{ bucketLabel(item.bucket) }}</text><text :class="item.direction === 'credit' ? 'plus' : 'minus'">{{ item.direction === 'credit' ? '+' : '-' }}{{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">{{ item.source_type }} · 余额 {{ item.balance_after || '0.00' }}</view>
			</view>
			<view v-if="!ledger.length" class="empty">暂无账户流水</view>
		</view>
		<view class="footer-note">系统显示税前金额，只记录佣金、线下付款和提现事实，不代表平台自动打款。</view>
	</view>
</template>

<script>
import {
	getYfthStoreCommissionSummary, getYfthStoreCommissionLedger, getYfthStoreC1Withdrawals,
	completeYfthStoreC1Withdrawal, saveYfthStoreSettlementAccount,
	getYfthStoreCommissionWithdrawals, requestYfthStoreCommissionWithdrawal
} from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			context: currentContext(), summary: {}, account: {}, settlement: {}, ledger: [], c1Withdrawals: [], storeWithdrawals: [],
			tab: 'c1', storeAmount: '', accountTypeLabels: ['个人', '企业'],
			accountForm: { account_type: 'personal', account_name: '', account_no: '', bank_name: '', bank_branch: '', reserved_phone: '', contact_name: '', contact_phone: '' }
		};
	},
	computed: {
		tabs() { return [{ key: 'c1', label: 'C1提现' }, { key: 'store', label: '门店提现' }, { key: 'ledger', label: '账户明细' }, { key: 'account', label: '结算账户' }]; },
		canManageSettlement() { return this.context.role_code !== 'store_staff'; }
	},
	onShow() { this.context = currentContext(); this.load(); },
	methods: {
		params() { return { role_code: this.context.role_code, store_id: this.context.store_id, page: 1, limit: 50 }; },
		load() {
			const params = this.params();
			return Promise.all([
				getYfthStoreCommissionSummary(params), getYfthStoreCommissionLedger(params),
				getYfthStoreC1Withdrawals(params), getYfthStoreCommissionWithdrawals(params)
			]).then(([summary, ledger, c1, store]) => {
				this.summary = summary.data || {}; this.account = this.summary.account || {}; this.settlement = this.summary.settlement_account || {};
				this.ledger = (ledger.data && ledger.data.list) || []; this.c1Withdrawals = (c1.data && c1.data.list) || []; this.storeWithdrawals = (store.data && store.data.list) || [];
			}).catch((err) => uni.showToast({ title: String((err && err.msg) || err || '门店账户加载失败'), icon: 'none' }));
		},
		toCents(value) {
			const match = String(value || '').trim().match(/^(\d+)(?:\.(\d{1,2}))?$/);
			return match ? Number(match[1]) * 100 + Number(((match[2] || '') + '00').slice(0, 2)) : 0;
		},
		completeC1(item) {
			uni.showModal({ title: '确认线下付款', content: '仅在线下款项已支付给C1后操作。', success: (res) => {
				if (!res.confirm) return;
				completeYfthStoreC1Withdrawal(item.id, Object.assign(this.params(), { request_id: 'c1-paid-' + item.id, remark: '门店确认线下付款完成' }))
					.then(() => { uni.showToast({ title: '已完成', icon: 'success' }); this.load(); })
					.catch((err) => uni.showToast({ title: String((err && err.msg) || err || '操作失败'), icon: 'none' }));
			} });
		},
		requestStore() {
			const amountCent = this.toCents(this.storeAmount);
			if (!amountCent) return uni.showToast({ title: '请输入正确金额', icon: 'none' });
			if (!this.settlement.id) { this.tab = 'account'; return uni.showToast({ title: '请先绑定结算账户', icon: 'none' }); }
			requestYfthStoreCommissionWithdrawal(Object.assign(this.params(), { amount_cent: amountCent, request_id: 'store-' + Date.now() + '-' + Math.random().toString(16).slice(2) }))
				.then(() => { this.storeAmount = ''; uni.showToast({ title: '已提交', icon: 'success' }); this.load(); })
				.catch((err) => uni.showToast({ title: String((err && err.msg) || err || '提交失败'), icon: 'none' }));
		},
		saveAccount() {
			saveYfthStoreSettlementAccount(Object.assign({}, this.accountForm, this.params()))
				.then(() => { uni.showToast({ title: '已保存', icon: 'success' }); this.load(); })
				.catch((err) => uni.showToast({ title: String((err && err.msg) || err || '保存失败'), icon: 'none' }));
		},
		bucketLabel(bucket) { return bucket === 'store_own' ? '门店自身佣金' : (bucket === 'store_proxy' ? 'C1代发佣金' : '门店提现'); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 60rpx; background: #f5f0e8; color: #302820; }
.header { display: flex; justify-content: space-between; gap: 20rpx; padding: 30rpx; border-radius: 16rpx; background: #826038; color: #fff; }
.header > view:first-child { display: flex; flex-direction: column; }.eyebrow,.sub { color: #f1e1cc; font-size: 22rpx; }.title { margin: 10rpx 0; font-size: 48rpx; font-weight: 700; }.store-name { max-width: 250rpx; text-align: right; font-size: 23rpx; }
.metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 14rpx; margin-top: 18rpx; }
.metrics view { display: flex; flex-direction: column; gap: 8rpx; padding: 22rpx; border-radius: 14rpx; background: #fff; }.metrics text:first-child { color: #74532f; font-size: 31rpx; font-weight: 700; }.metrics text:last-child { color: #85796d; font-size: 21rpx; }
.tabs { display: flex; overflow-x: auto; gap: 8rpx; margin-top: 20rpx; padding: 6rpx; border-radius: 12rpx; background: #fff; }.tabs view { flex: 0 0 auto; padding: 16rpx 22rpx; color: #827465; font-size: 23rpx; }.tabs .active { border-radius: 10rpx; background: #f1e6d5; color: #674625; font-weight: 700; }
.panel,.record,.notice { margin-top: 14rpx; padding: 24rpx; border-radius: 14rpx; background: #fff; }.panel-title { font-size: 28rpx; font-weight: 700; }.settlement-line,.record-sub,.notice { color: #887b6d; font-size: 22rpx; line-height: 1.55; }.settlement-line { margin-top: 14rpx; }.input-row { display: flex; align-items: center; gap: 12rpx; margin-top: 18rpx; padding: 6rpx 16rpx; border: 1rpx solid #e4d6c5; border-radius: 12rpx; }.input-row input { flex: 1; height: 68rpx; }.primary,.outline { margin-top: 16rpx; border-radius: 12rpx; font-size: 25rpx; }.primary { background: #846039; color: #fff; }.outline { border: 1rpx solid #b89061; background: #fff9f0; color: #76512b; }
.record-head { display: flex; justify-content: space-between; gap: 14rpx; font-size: 26rpx; font-weight: 700; }.record-sub { margin-top: 9rpx; }.status { margin-top: 8rpx; color: #7a5a35; font-size: 22rpx; }.plus { color: #56745c; }.minus { color: #a14f45; }
.form input,.picker { box-sizing: border-box; width: 100%; height: 72rpx; margin-top: 14rpx; padding: 0 18rpx; border: 1rpx solid #eadfce; border-radius: 10rpx; background: #fffaf3; font-size: 24rpx; line-height: 72rpx; }.empty { padding: 60rpx 0; color: #998e82; text-align: center; }.footer-note { margin-top: 24rpx; color: #94887a; font-size: 20rpx; text-align: center; line-height: 1.55; }
</style>
