<template>
	<view class="page">
		<view v-for="item in list" :key="item.id" class="panel">
			<view class="top">
				<view class="title">{{ item.task_name }}</view>
				<view class="status">{{ item.status_text }}</view>
			</view>
			<view v-if="item.task_code === 'first_purchase'" class="line">采购单ID：{{ item.purchase_order_id || '-' }}</view>
			<input v-model="forms[item.id].content" placeholder="说明" />
			<input v-model="forms[item.id].attachment_ids" placeholder="附件ID，多个用英文逗号分隔" />
			<input v-if="item.task_code === 'first_purchase'" v-model="forms[item.id].purchase_order_id" placeholder="已入库采购单ID" />
			<button @click="submit(item)">提交任务证据</button>
			<view v-if="item.reject_reason" class="warn">驳回原因：{{ item.reject_reason }}</view>
		</view>
	</view>
</template>

<script>
import { getYfthFranchiseOpeningTasks, submitYfthFranchiseOpeningTask } from '@/api/yfth.js';

export default {
	data() { return { list: [], forms: {} }; },
	onShow() { this.load(); },
	methods: {
		load() {
			getYfthFranchiseOpeningTasks().then((res) => {
				this.list = (res.data && res.data.list) || [];
				const forms = {};
				this.list.forEach((item) => {
					forms[item.id] = { content: '', attachment_ids: '', purchase_order_id: item.purchase_order_id || '' };
				});
				this.forms = forms;
			});
		},
		submit(item) {
			submitYfthFranchiseOpeningTask(item.id, this.forms[item.id] || {}).then(() => {
				uni.showToast({ title: '已提交', icon: 'success' });
				this.load();
			});
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f6f0e6; padding: 24rpx; }
.panel { background: #fff; border-radius: 18rpx; padding: 26rpx; margin-bottom: 22rpx; box-shadow: 0 10rpx 26rpx rgba(70,45,30,.06); }
.top { display: flex; justify-content: space-between; gap: 18rpx; }
.title { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.status { color: #7b4e25; font-size: 24rpx; }
.line, .warn { color: #5f5148; font-size: 24rpx; margin-top: 12rpx; }
.warn { color: #b45a2c; }
input { margin-top: 16rpx; background: #fff7e9; border-radius: 12rpx; padding: 16rpx; font-size: 24rpx; }
button { margin-top: 20rpx; background: #7b4e25; color: #fff; border-radius: 12rpx; }
</style>
