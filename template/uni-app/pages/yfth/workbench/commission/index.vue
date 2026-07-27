<template>
	<view class="page">
		<view class="header">
			<view>
				<text class="eyebrow">当前门店资金</text>
				<text class="title">¥ {{ withdrawalSummary.available || '0.00' }}</text>
				<text class="sub">可申请提现金额</text>
			</view>
			<view class="store-name">{{ context.store_name || ('门店 ' + context.store_id) }}</view>
		</view>

		<view class="metrics">
			<view><text>{{ withdrawalSummary.available || '0.00' }}</text><text>可申请提现</text></view>
			<view><text>{{ withdrawalSummary.frozen || '0.00' }}</text><text>审核/打款中</text></view>
			<view><text>{{ withdrawalSummary.paid || '0.00' }}</text><text>已打款</text></view>
			<view><text>{{ c1Account.unsettled || '0.00' }}</text><text>C1待线下结算</text></view>
		</view>

		<view class="withdraw-panel">
			<view>
				<text class="section-title">门店提现</text>
				<text class="section-hint">仅店长可提交。财务核对后线下银行打款，确认打款完成时才对冲金额。</text>
			</view>
			<button v-if="isManager && !withdrawalFormVisible" @click="withdrawalFormVisible = true">申请提现</button>
			<text v-else-if="!isManager" class="read-only">店员可查看，申请由店长提交</text>
		</view>
		<view v-if="withdrawalFormVisible" class="withdraw-form">
			<input v-model="withdrawalForm.amount" type="digit" placeholder="提现金额（元）" />
			<input v-model="withdrawalForm.receiver_name" placeholder="收款人姓名" />
			<input v-model="withdrawalForm.receiver_account" type="number" placeholder="银行卡号" />
			<input v-model="withdrawalForm.bank_name" placeholder="开户银行" />
			<input v-model="withdrawalForm.remark" placeholder="申请说明（可选）" />
			<view class="form-actions">
				<button class="light" @click="withdrawalFormVisible = false">取消</button>
				<button :disabled="withdrawalSubmitting" @click="submitWithdrawal">{{ withdrawalSubmitting ? '提交中...' : '提交申请' }}</button>
			</view>
		</view>

		<view class="tabs">
			<view v-for="item in tabs" :key="item.key" :class="{ active: tab === item.key }" @click="tab = item.key">{{ item.label }}</view>
		</view>

		<view v-if="tab === 'withdrawals'" class="section">
			<view v-for="item in withdrawals" :key="item.id" class="record">
				<view class="record-head"><text>{{ item.request_no }}</text><text>¥ {{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">{{ withdrawalStatus(item.status) }} · {{ item.bank_name }} {{ item.receiver_account_masked }}</view>
				<view v-if="item.review_reason" class="record-sub">审核说明：{{ item.review_reason }}</view>
			</view>
			<view v-if="!withdrawals.length" class="empty">暂无门店提现申请</view>
		</view>
		<view v-else-if="tab === 'c1'" class="section">
			<view v-for="item in c1Settlements" :key="item.id" class="record">
				<view class="record-head"><text>{{ item.user && item.user.nickname || 'C1用户' }}</text><text>¥ {{ item.amount || '0.00' }}</text></view>
				<view class="record-sub">{{ item.user && item.user.phone_masked }} · {{ item.status === 'paid' ? '已完成结算' : '已申请结算' }}</view>
				<button v-if="item.status === 'pending'" class="outline" @click="completeC1(item)">线下完成后标记结算完成</button>
			</view>
			<view v-if="!c1Settlements.length" class="empty">暂无 C1 结算申请</view>
		</view>
		<view v-else class="section">
			<view v-for="item in ledger" :key="item.id" class="record">
				<view class="record-head">
					<text>{{ sourceLabel(item.source_type) }}</text>
					<text :class="item.direction === 'credit' ? 'plus' : 'minus'">{{ item.direction === 'credit' ? '+' : '-' }}{{ item.amount || '0.00' }}</text>
				</view>
				<view class="record-sub">余额 {{ item.balance_after || '0.00' }} · {{ timeText(item.add_time) }}</view>
			</view>
			<view v-if="!ledger.length" class="empty">暂无佣金明细</view>
		</view>
		<view class="footer-note">提现不代表系统自动转账。财务线下打款并确认后，系统才记录已打款并扣减门店未结算佣金。</view>
	</view>
</template>

<script>
import {
	completeYfthStoreC1Settlement,
	createYfthStoreWithdrawal,
	getYfthStoreC1Settlements,
	getYfthStoreCommissionLedger,
	getYfthStoreCommissionSummary,
	getYfthStoreWithdrawals,
	getYfthStoreWithdrawalSummary
} from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			context: currentContext(),
			c1Account: {},
			ledger: [],
			c1Settlements: [],
			withdrawalSummary: {},
			withdrawals: [],
			tab: 'withdrawals',
			withdrawalFormVisible: false,
			withdrawalSubmitting: false,
			withdrawalForm: { amount: '', receiver_name: '', receiver_account: '', bank_name: '', remark: '' }
		};
	},
	computed: {
		isManager() { return this.context.role_code === 'store_manager'; },
		tabs() {
			return [
				{ key: 'withdrawals', label: '提现记录' },
				{ key: 'c1', label: 'C1结算' },
				{ key: 'ledger', label: '佣金明细' }
			];
		}
	},
	onShow() {
		this.context = currentContext();
		this.load();
	},
	methods: {
		params() {
			return { role_code: this.context.role_code, store_id: this.context.store_id, page: 1, limit: 50 };
		},
		load() {
			const params = this.params();
			return Promise.all([
				getYfthStoreCommissionSummary(params),
				getYfthStoreCommissionLedger(params),
				getYfthStoreC1Settlements(params),
				getYfthStoreWithdrawalSummary(params),
				getYfthStoreWithdrawals(params)
			]).then(([summary, ledger, c1, withdrawalSummary, withdrawals]) => {
				this.c1Account = (summary.data && summary.data.c1_account) || {};
				this.ledger = (ledger.data && ledger.data.list) || [];
				this.c1Settlements = (c1.data && c1.data.list) || [];
				this.withdrawalSummary = withdrawalSummary.data || {};
				this.withdrawals = (withdrawals.data && withdrawals.data.list) || [];
			}).catch((err) => uni.showToast({
				title: String((err && err.msg) || err || '门店资金加载失败'),
				icon: 'none'
			}));
		},
		submitWithdrawal() {
			const amountCent = Math.round(Number(this.withdrawalForm.amount || 0) * 100);
			if (amountCent <= 0 || !this.withdrawalForm.receiver_name.trim()
				|| !this.withdrawalForm.receiver_account.trim() || !this.withdrawalForm.bank_name.trim()) {
				return uni.showToast({ title: '请完整填写金额和收款银行卡信息', icon: 'none' });
			}
			this.withdrawalSubmitting = true;
			createYfthStoreWithdrawal(Object.assign(this.params(), {
				amount_cent: amountCent,
				receiver_name: this.withdrawalForm.receiver_name.trim(),
				receiver_account: this.withdrawalForm.receiver_account.trim(),
				bank_name: this.withdrawalForm.bank_name.trim(),
				remark: this.withdrawalForm.remark.trim(),
				request_id: `store-withdrawal-${Date.now()}`
			})).then(() => {
				uni.showToast({ title: '提现申请已提交', icon: 'success' });
				this.withdrawalFormVisible = false;
				this.withdrawalForm = { amount: '', receiver_name: '', receiver_account: '', bank_name: '', remark: '' };
				return this.load();
			}).catch((err) => uni.showToast({
				title: String((err && err.msg) || err || '提现申请失败'),
				icon: 'none'
			})).finally(() => {
				this.withdrawalSubmitting = false;
			});
		},
		completeC1(item) {
			uni.showModal({
				title: '确认线下结算',
				content: '仅在线下款项已支付给 C1 后操作。',
				success: (res) => {
					if (!res.confirm) return;
					completeYfthStoreC1Settlement(item.id, Object.assign(this.params(), {
						request_id: 'c1-settled-' + item.id,
						remark: '门店确认线下结算完成'
					})).then(() => {
						uni.showToast({ title: '已完成', icon: 'success' });
						this.load();
					}).catch((err) => uni.showToast({
						title: String((err && err.msg) || err || '操作失败'),
						icon: 'none'
					}));
				}
			});
		},
		withdrawalStatus(value) {
			return ({
				pending_review: '待财务审核',
				approved: '待线下打款',
				rejected: '已驳回',
				paid: '已打款'
			})[value] || value;
		},
		sourceLabel(value) {
			return ({
				commission_c1_responsibility_credit: 'C1佣金责任额',
				commission_b1_credit: 'B1佣金',
				store_manual_withdrawal_paid: '门店提现打款',
				manual_adjustment: '总部台账调整'
			})[value] || value || '佣金变动';
		},
		timeText(value) { return value ? new Date(Number(value) * 1000).toLocaleDateString() : '-'; }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 60rpx; box-sizing: border-box; background: #f5f0e8; color: #302820; }
.header { display: flex; justify-content: space-between; gap: 20rpx; padding: 30rpx; border-radius: 16rpx; background: #826038; color: #fff; }
.header>view:first-child { display: flex; flex-direction: column; }
.eyebrow,.sub { color: #f1e1cc; font-size: 22rpx; }
.title { margin: 10rpx 0; font-size: 48rpx; font-weight: 700; }
.store-name { max-width: 250rpx; text-align: right; font-size: 23rpx; }
.metrics { display: grid; grid-template-columns: repeat(2,1fr); gap: 14rpx; margin-top: 18rpx; }
.metrics view { display: flex; flex-direction: column; gap: 8rpx; padding: 22rpx; border-radius: 14rpx; background: #fff; }
.metrics text:first-child { color: #74532f; font-size: 31rpx; font-weight: 700; }
.metrics text:last-child { color: #85796d; font-size: 21rpx; }
.withdraw-panel { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; margin-top: 18rpx; padding: 22rpx; border-radius: 14rpx; background: #fff; }
.section-title,.section-hint { display: block; }
.section-title { font-size: 28rpx; font-weight: 700; }
.section-hint { margin-top: 8rpx; color: #8b7b6a; font-size: 21rpx; line-height: 1.5; }
.withdraw-panel button,.form-actions button { margin: 0; border-radius: 10rpx; background: #75512f; color: #fff; font-size: 23rpx; }
.read-only { color: #9b8770; font-size: 21rpx; }
.withdraw-form { margin-top: 14rpx; padding: 20rpx; border-radius: 14rpx; background: #fff; }
.withdraw-form input { height: 72rpx; margin-top: 12rpx; padding: 0 18rpx; border: 1rpx solid #e4d4bd; border-radius: 10rpx; background: #faf8f4; font-size: 24rpx; }
.form-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 14rpx; margin-top: 16rpx; }
.form-actions button { width: 100%; }
.form-actions button.light { background: #f3eadc; color: #75512f; }
.tabs { display: flex; gap: 8rpx; margin-top: 20rpx; padding: 6rpx; border-radius: 12rpx; background: #fff; }
.tabs view { flex: 1; padding: 16rpx 8rpx; text-align: center; color: #827465; font-size: 23rpx; }
.tabs .active { border-radius: 10rpx; background: #f1e6d5; color: #674625; font-weight: 700; }
.record { margin-top: 14rpx; padding: 24rpx; border-radius: 14rpx; background: #fff; }
.record-head { display: flex; justify-content: space-between; gap: 14rpx; font-size: 26rpx; font-weight: 700; }
.record-sub { margin-top: 9rpx; color: #887b6d; font-size: 22rpx; line-height: 1.55; }
.outline { margin-top: 16rpx; border: 1rpx solid #b89061; border-radius: 12rpx; background: #fff9f0; color: #76512b; font-size: 25rpx; }
.plus { color: #56745c; }
.minus { color: #a14f45; }
.empty { padding: 60rpx 0; color: #998e82; text-align: center; }
.footer-note { margin-top: 24rpx; color: #94887a; font-size: 20rpx; text-align: center; line-height: 1.55; }
</style>
