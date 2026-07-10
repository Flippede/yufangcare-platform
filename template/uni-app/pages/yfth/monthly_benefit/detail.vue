<template>
	<view class="page">
		<view v-if="detail.id" class="card">
			<view class="name">{{ detail.benefit_name }}</view>
			<view class="line">履约单号：{{ detail.fulfillment_no }}</view>
			<view class="line">状态：{{ statusText(detail.status) }}</view>
			<view class="line">方式：{{ methodText(detail.fulfillment_method) }}</view>
			<view class="line">收件人：{{ detail.recipient_name_masked }} {{ detail.recipient_phone_masked }}</view>
			<view class="line">物流：{{ detail.delivery_company || '-' }} {{ detail.delivery_no_masked || '' }}</view>
			<view class="button-row" v-if="canCancel">
				<button @click="cancel">取消履约</button>
			</view>
		</view>
		<view class="card">
			<view class="section-title">进度</view>
			<view v-for="(item, index) in events" :key="index" class="event">
				<view class="event-title">{{ item.event_type }} · {{ item.to_status }}</view>
				<view class="muted">{{ timeText(item.create_time) }} {{ item.reason }}</view>
			</view>
			<view v-if="!events.length" class="muted">暂无事件</view>
		</view>
	</view>
</template>

<script>
import { cancelYfthMonthlyBenefitFulfillment, getYfthMonthlyBenefitFulfillment } from '@/api/yfth.js';

export default {
	data() {
		return { id: 0, detail: {}, events: [] };
	},
	computed: {
		canCancel() {
			return ['pending_confirm', 'confirmed'].indexOf(this.detail.status) !== -1;
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	methods: {
		load() {
			getYfthMonthlyBenefitFulfillment(this.id).then((res) => {
				this.detail = (res.data && res.data.fulfillment) || {};
				this.events = (res.data && res.data.events) || [];
			});
		},
		cancel() {
			uni.showModal({
				title: '取消履约',
				content: '确认取消该权益履约？',
				success: (modal) => {
					if (!modal.confirm) return;
					cancelYfthMonthlyBenefitFulfillment(this.id, {
						reason: 'user_cancel',
						client_operation_key: 'monthly_cancel_' + this.id + '_' + Date.now()
					}).then(() => {
						uni.showToast({ title: '已取消', icon: 'success' });
						this.load();
					});
				}
			});
		},
		statusText(status) {
			const map = { pending_confirm: '待确认', confirmed: '已确认', preparing: '备货中', shipped: '已发货', completed: '已完成', cancelled: '已取消', rejected: '已驳回', exception: '异常' };
			return map[status] || status;
		},
		methodText(method) {
			return method === 'self_pickup' ? '到店自提' : '快递配送';
		},
		timeText(value) {
			if (!value) return '-';
			const d = new Date(Number(value) * 1000);
			return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate() + ' ' + d.getHours() + ':' + d.getMinutes();
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f8f0e5; padding: 24rpx; }
.card { background: #fff; border-radius: 18rpx; padding: 24rpx; margin-top: 18rpx; }
.name, .section-title { font-size: 32rpx; font-weight: 700; color: #2b2320; }
.line { color: #5d514a; font-size: 26rpx; margin-top: 14rpx; }
.muted { color: #806d61; font-size: 24rpx; margin-top: 6rpx; }
.event { border-left: 4rpx solid #a57945; padding-left: 18rpx; margin-top: 18rpx; }
.event-title { color: #2b2320; font-weight: 700; font-size: 27rpx; }
.button-row { display: flex; margin-top: 24rpx; }
button { flex: 1; background: #fff7e8; color: #6b4a30; border-radius: 12rpx; font-size: 26rpx; }
</style>
