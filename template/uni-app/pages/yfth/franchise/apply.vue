<template>
	<view class="page">
		<view class="header">
			<view class="eyebrow">合作申请</view>
			<view class="title">填写加盟意向</view>
			<view class="sub">本页面仅提交基础申请，不代表已获得加盟商身份。</view>
		</view>

		<view class="form-card">
			<view class="field">
				<view class="label">联系人</view>
				<input v-model="form.name" placeholder="请输入联系人姓名" />
			</view>
			<view class="field">
				<view class="label">联系电话</view>
				<input v-model="form.phone" type="number" placeholder="请输入联系电话" />
			</view>
			<view class="field">
				<view class="label">城市</view>
				<input v-model="form.city" placeholder="如：杭州" />
			</view>
			<view class="field">
				<view class="label">区域</view>
				<input v-model="form.region" placeholder="如：西湖区" />
			</view>
			<view class="field">
				<view class="label">意向商圈/区域</view>
				<input v-model="form.intention_area" placeholder="请输入意向开店区域" />
			</view>
			<view class="field">
				<view class="label">预算</view>
				<input v-model="form.budget" type="digit" placeholder="请输入预算金额" />
			</view>
			<view class="field">
				<view class="label">补充说明</view>
				<textarea v-model="form.remark" placeholder="可填写资源、经验或合作想法" />
			</view>
			<button class="submit" :disabled="submitting" @click="submit">提交申请</button>
		</view>
	</view>
</template>

<script>
import { submitYfthFranchiseApplication } from '@/api/yfth.js';
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';

export default {
	data() {
		return {
			submitting: false,
			form: {
				name: '',
				phone: '',
				city: '',
				region: '',
				intention_area: '',
				budget: '',
				remark: ''
			}
		};
	},
	computed: mapGetters(['isLogin']),
	methods: {
		submit() {
			if (!this.isLogin) {
				toLogin();
				return;
			}
			if (!this.form.name || !this.form.phone || !this.form.city || !this.form.intention_area) {
				uni.showToast({ title: '请填写联系人、电话、城市和意向区域', icon: 'none' });
				return;
			}
			this.submitting = true;
			submitYfthFranchiseApplication(this.form).then((res) => {
				const id = res.data && res.data.application && res.data.application.id;
				uni.showToast({ title: '已提交', icon: 'success' });
				setTimeout(() => {
					uni.redirectTo({ url: '/pages/yfth/franchise/detail?id=' + id });
				}, 500);
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.submitting = false;
			});
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.header { border-radius: 22rpx; background: linear-gradient(135deg, #5a3d29, #c49b62); color: #fff; padding: 32rpx; }
.eyebrow { color: #f4dfb8; font-size: 22rpx; }
.title { font-size: 40rpx; font-weight: 700; margin-top: 8rpx; }
.sub { color: #fff4df; font-size: 24rpx; margin-top: 8rpx; }
.form-card { margin-top: 22rpx; background: #fff; border-radius: 18rpx; padding: 26rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.field { margin-bottom: 24rpx; }
.label { color: #2d2434; font-size: 26rpx; font-weight: 700; margin-bottom: 12rpx; }
input, textarea { width: 100%; box-sizing: border-box; background: #fffaf2; border-radius: 12rpx; padding: 0 22rpx; font-size: 26rpx; color: #2d2434; }
input { height: 76rpx; line-height: 76rpx; }
textarea { min-height: 160rpx; padding-top: 18rpx; line-height: 1.6; }
.submit { width: 100%; background: #6f4c2f; color: #fff; border-radius: 12rpx; height: 78rpx; line-height: 78rpx; font-size: 28rpx; margin-top: 10rpx; }
</style>
