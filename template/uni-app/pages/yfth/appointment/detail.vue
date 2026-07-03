<template>
	<view class="page">
		<view v-if="detail.id" class="card">
			<view class="title">{{ detail.appointment_no }}</view>
			<view class="row"><text>Status</text><text>{{ detail.status }}</text></view>
			<view class="row"><text>Date</text><text>{{ detail.schedule.date }}</text></view>
			<view class="row"><text>Slot</text><text>{{ detail.schedule.start_time }}-{{ detail.schedule.end_time }}</text></view>
			<view class="row"><text>Store</text><text>{{ detail.store.name || detail.store.id }}</text></view>
			<view class="row"><text>Service</text><text>{{ detail.service_project.name || detail.service_project.id }}</text></view>
			<view class="row"><text>Benefit</text><text>{{ detail.benefit.name }}</text></view>
		</view>

		<view v-if="canShowCode" class="card code-card">
			<view class="section-title">Writeoff Code</view>
			<view v-if="dynamicCode.qr_payload" class="qr-box">
				<zb-code cid="yfthWriteoffCode" :val="dynamicCode.qr_payload" :size="180" unit="px" :onval="true" />
			</view>
			<view v-if="dynamicCode.digital_code" class="digital-code">{{ dynamicCode.digital_code }}</view>
			<view class="code-tip">{{ codeTip }}</view>
			<button class="primary small" @click="generateCode">{{ dynamicCode.qr_payload ? 'Refresh Code' : 'Generate Code' }}</button>
		</view>

		<view v-if="writeoffResult.status === 'written_off'" class="card">
			<view class="section-title">Writeoff Result</view>
			<view class="row"><text>Status</text><text>Completed</text></view>
			<view class="row"><text>Method</text><text>{{ writeoffResult.record.writeoff_method }}</text></view>
			<view class="row"><text>Time</text><text>{{ writeoffResult.record.writeoff_time }}</text></view>
		</view>

		<view v-if="canOperate" class="actions">
			<button class="plain" @click="cancel">Cancel</button>
			<button class="primary" @click="loadReschedule">Reschedule</button>
		</view>
		<view v-if="rescheduleMode" class="card">
			<picker :range="rescheduleSlots" range-key="start_time" @change="selectSlot">
				<view class="picker">{{ selectedSlot.start_time || 'Select new slot' }}</view>
			</picker>
			<button class="primary small" @click="submitReschedule">Submit Reschedule</button>
		</view>
		<view class="card" v-if="timeline.length">
			<view class="section-title">Timeline</view>
			<view v-for="(event, index) in timeline" :key="index" class="event">
				<text>{{ event.event_type }}</text>
				<text>{{ event.to_status }}</text>
			</view>
		</view>
	</view>
</template>

<script>
import zbCode from '@/components/zb-code/zb-code.vue';
import {
	cancelYfthServiceAppointment,
	generateYfthAppointmentCode,
	getYfthAppointmentDetail,
	getYfthAppointmentRescheduleSlots,
	rescheduleYfthServiceAppointment
} from '@/api/yfth.js';

export default {
	components: {
		zbCode
	},
	data() {
		return {
			id: 0,
			detail: {
				store: {},
				service_project: {},
				schedule: {},
				benefit: {},
				dynamic_code: {},
				writeoff_result: {}
			},
			dynamicCode: {},
			countdown: 0,
			timer: null,
			rescheduleMode: false,
			rescheduleSlots: [],
			selectedSlot: {}
		};
	},
	computed: {
		canOperate() {
			return this.detail.actions && (this.detail.actions.can_cancel || this.detail.actions.can_reschedule);
		},
		canShowCode() {
			return this.detail.dynamic_code && this.detail.dynamic_code.can_generate;
		},
		timeline() {
			return this.detail.timeline || [];
		},
		writeoffResult() {
			return this.detail.writeoff_result || { status: 'none' };
		},
		codeTip() {
			if (!this.canShowCode) return this.detail.dynamic_code.reason || '';
			if (this.countdown > 0) return 'Expires in ' + this.countdown + 's';
			return 'Generate a fresh code when you arrive at the store.';
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	onUnload() {
		this.stopTimer();
	},
	methods: {
		load() {
			getYfthAppointmentDetail(this.id).then((res) => {
				this.detail = Object.assign({}, this.detail, res.data || {});
				this.dynamicCode = {};
				this.stopTimer();
			});
		},
		generateCode() {
			generateYfthAppointmentCode(this.id, {
				idempotency_key: 'code_' + Date.now()
			}).then((res) => {
				this.dynamicCode = (res.data && res.data.code) || {};
				this.countdown = Math.max(0, Number(this.dynamicCode.ttl_seconds || 0));
				this.startTimer();
			}).catch((err) => {
				uni.showToast({ title: String(err), icon: 'none' });
			});
		},
		startTimer() {
			this.stopTimer();
			this.timer = setInterval(() => {
				this.countdown = Math.max(0, this.countdown - 1);
				if (this.countdown <= 0) this.stopTimer();
			}, 1000);
		},
		stopTimer() {
			if (this.timer) {
				clearInterval(this.timer);
				this.timer = null;
			}
		},
		cancel() {
			cancelYfthServiceAppointment(this.id, {
				reason: 'user_cancel',
				idempotency_key: 'cancel_' + Date.now()
			}).then(() => {
				this.load();
			});
		},
		loadReschedule() {
			this.rescheduleMode = true;
			getYfthAppointmentRescheduleSlots(this.id, { date: this.detail.schedule.date }).then((res) => {
				this.rescheduleSlots = ((res.data && res.data.slots) || []).filter((item) => {
					return item.status === 'available' && item.remaining_capacity > 0;
				});
			});
		},
		selectSlot(e) {
			this.selectedSlot = this.rescheduleSlots[Number(e.detail.value)] || {};
		},
		submitReschedule() {
			if (!this.selectedSlot.slot_key) {
				uni.showToast({ title: 'Select slot', icon: 'none' });
				return;
			}
			rescheduleYfthServiceAppointment(this.id, {
				date: this.detail.schedule.date,
				start_minute: this.selectedSlot.start_minute,
				reason: 'user_reschedule',
				idempotency_key: 'reschedule_' + Date.now()
			}).then(() => {
				this.rescheduleMode = false;
				this.load();
			});
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	background: #f5f7f8;
	padding: 24rpx;
}
.card {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 18rpx;
}
.title,
.section-title {
	font-size: 30rpx;
	font-weight: 700;
	margin-bottom: 16rpx;
}
.section-title {
	font-size: 28rpx;
}
.row,
.event {
	display: flex;
	justify-content: space-between;
	color: #60707c;
	margin-top: 12rpx;
}
.actions {
	display: flex;
	gap: 16rpx;
	margin-bottom: 18rpx;
}
.primary,
.plain {
	flex: 1;
	border-radius: 10rpx;
}
.primary {
	background: #1f7a6b;
	color: #fff;
}
.plain {
	background: #fff;
	color: #394854;
}
.small {
	margin-top: 18rpx;
}
.picker {
	color: #1f2a33;
}
.code-card {
	text-align: center;
}
.qr-box {
	display: flex;
	justify-content: center;
	margin: 12rpx 0;
}
.digital-code {
	font-size: 52rpx;
	font-weight: 700;
	letter-spacing: 0;
	color: #1f2a33;
}
.code-tip {
	margin-top: 12rpx;
	color: #6a7780;
	font-size: 24rpx;
}
</style>
