<template>
	<view class="page">
		<view class="panel">
			<view class="panel-title">新增客户跟进</view>
			<picker mode="selector" :range="typeOptions" range-key="label" @change="changeType">
				<view class="picker">{{ currentTypeLabel }}</view>
			</picker>
			<textarea v-model="form.content" maxlength="1000" placeholder="记录本次沟通内容、客户需求或下次计划" />
			<input v-model="form.next_follow_time" placeholder="下次跟进时间，可填 2026-07-08 10:00" />
			<button @click="submit">提交跟进</button>
		</view>
	</view>
</template>

<script>
import { addYfthCustomerFollow } from '@/api/yfth.js';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			id: 0,
			context: {},
			form: { follow_type: 'other', content: '', next_follow_time: '' },
			typeOptions: [
				{ label: '电话', value: 'phone' },
				{ label: '微信', value: 'wechat' },
				{ label: '到店沟通', value: 'store_visit' },
				{ label: '其他', value: 'other' }
			]
		};
	},
	computed: {
		currentTypeLabel() {
			const found = this.typeOptions.find((item) => item.value === this.form.follow_type);
			return found ? found.label : '其他';
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.context = currentContext();
	},
	methods: {
		contextParams(extra) {
			return Object.assign({
				role_code: this.context.role_code,
				store_id: this.context.store_id
			}, extra || {});
		},
		changeType(event) {
			const index = Number(event.detail.value || 0);
			this.form.follow_type = this.typeOptions[index].value;
		},
		submit() {
			if (!this.form.content.trim()) {
				uni.showToast({ title: '请填写跟进内容', icon: 'none' });
				return;
			}
			addYfthCustomerFollow(this.id, this.contextParams(this.form)).then(() => {
				uni.showToast({ title: '已记录', icon: 'success' });
				setTimeout(() => uni.navigateBack(), 500);
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.panel { background: #fff; border-radius: 16rpx; padding: 24rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.panel-title { font-size: 32rpx; font-weight: 700; color: #2d2434; margin-bottom: 20rpx; }
.picker, input, textarea { background: #fffaf2; border-radius: 12rpx; padding: 18rpx 20rpx; font-size: 26rpx; margin-bottom: 18rpx; color: #3a3029; }
textarea { width: auto; height: 260rpx; line-height: 1.6; }
button { background: #6f4c2f; color: #fff; border-radius: 12rpx; height: 72rpx; line-height: 72rpx; font-size: 28rpx; margin-top: 8rpx; }
</style>
