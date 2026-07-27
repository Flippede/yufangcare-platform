<template>
	<view class="page">
		<view class="hero">
			<text class="eyebrow">御方通和资金资料</text>
			<text class="title">提现收款账户</text>
			<text class="description">财务线下打款将使用这里保存的银行卡资料。每笔提现都会固化申请时的收款快照。</text>
		</view>

		<view v-if="profile.configured" class="saved-card">
			<view>
				<text class="saved-bank">{{ profile.bank_name }}</text>
				<text class="saved-detail">{{ profile.receiver_name_masked }} · {{ profile.receiver_account_masked }}</text>
			</view>
			<text class="status">已设置</text>
		</view>

		<view class="form-card">
			<text class="section-title">{{ profile.configured ? '更换收款账户' : '设置收款账户' }}</text>
			<text class="hint">为保护银行卡信息，已保存资料仅展示脱敏内容；修改时请重新填写完整资料。</text>
			<view class="field">
				<text>收款人姓名</text>
				<input v-model="form.receiver_name" maxlength="64" placeholder="请输入银行卡开户姓名" />
			</view>
			<view class="field">
				<text>银行卡号</text>
				<input v-model="form.receiver_account" type="number" maxlength="32" placeholder="请输入本人银行卡号" />
			</view>
			<view class="field">
				<text>开户银行</text>
				<input v-model="form.bank_name" maxlength="128" placeholder="例如：中国工商银行北京分行" />
			</view>
			<button :disabled="submitting" @click="save">{{ submitting ? '保存中...' : '保存收款账户' }}</button>
		</view>

		<view class="notice">银行卡资料只用于总部财务线下打款核对，不会在普通页面展示完整卡号。</view>
	</view>
</template>

<script>
import { getYfthWithdrawalBeneficiary, saveYfthWithdrawalBeneficiary } from '@/api/yfth.js';

export default {
	data() {
		return {
			profile: {},
			form: { receiver_name: '', receiver_account: '', bank_name: '' },
			submitting: false
		};
	},
	onShow() {
		this.load();
	},
	methods: {
		load() {
			return getYfthWithdrawalBeneficiary().then((res) => {
				this.profile = res.data || {};
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err || '收款账户读取失败'), icon: 'none' });
			});
		},
		save() {
			const payload = {
				receiver_name: this.form.receiver_name.trim(),
				receiver_account: this.form.receiver_account.replace(/\s+/g, ''),
				bank_name: this.form.bank_name.trim()
			};
			if (payload.receiver_name.length < 2 || !/^\d{8,32}$/.test(payload.receiver_account) || !payload.bank_name) {
				return uni.showToast({ title: '请完整填写正确的银行卡资料', icon: 'none' });
			}
			this.submitting = true;
			saveYfthWithdrawalBeneficiary(payload).then((res) => {
				this.profile = res.data || {};
				this.form = { receiver_name: '', receiver_account: '', bank_name: '' };
				uni.showToast({ title: '收款账户已保存', icon: 'success' });
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err || '保存失败'), icon: 'none' });
			}).finally(() => {
				this.submitting = false;
			});
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; box-sizing: border-box; background: #f5f0e8; color: #302820; }
.hero { display: flex; flex-direction: column; padding: 34rpx 30rpx; border-radius: 16rpx; background: #8a673e; color: #fff; }
.eyebrow { color: #f3e3ce; font-size: 22rpx; }
.title { margin-top: 10rpx; font-size: 38rpx; font-weight: 700; }
.description { margin-top: 14rpx; color: #f5eadb; font-size: 23rpx; line-height: 1.65; }
.saved-card,.form-card { margin-top: 18rpx; border-radius: 16rpx; background: #fff; }
.saved-card { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; padding: 26rpx; }
.saved-card>view { display: flex; flex-direction: column; gap: 8rpx; }
.saved-bank { font-size: 28rpx; font-weight: 700; }
.saved-detail,.hint { color: #8c7e6e; font-size: 22rpx; line-height: 1.55; }
.status { padding: 8rpx 14rpx; border-radius: 8rpx; background: #f2e7d5; color: #77542f; font-size: 21rpx; }
.form-card { padding: 28rpx; }
.section-title { display: block; font-size: 29rpx; font-weight: 700; }
.hint { display: block; margin-top: 10rpx; }
.field { margin-top: 22rpx; }
.field>text { display: block; margin-bottom: 10rpx; color: #675a4c; font-size: 23rpx; }
.field input { height: 78rpx; padding: 0 20rpx; border: 1rpx solid #e4d3b9; border-radius: 10rpx; background: #fbf9f5; font-size: 25rpx; }
button { margin-top: 28rpx; border-radius: 12rpx; background: #8a673e; color: #fff; font-size: 27rpx; }
button[disabled] { opacity: .58; }
.notice { margin: 22rpx 10rpx; color: #958879; font-size: 21rpx; line-height: 1.6; text-align: center; }
@media (min-width: 620px) {
	.page { width: 560px; margin: 0 auto; }
}
</style>
