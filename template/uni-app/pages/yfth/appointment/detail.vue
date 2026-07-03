<template>
	<view class="page">
		<view v-if="detail.id" class="card">
			<view class="title">{{ detail.appointment_no }}</view>
			<view class="row"><text>Status</text><text>{{ detail.status }}</text></view>
			<view class="row"><text>Date</text><text>{{ detail.date_text }}</text></view>
			<view class="row"><text>Slot</text><text>{{ detail.start_time_text }}-{{ detail.end_time_text }}</text></view>
			<view class="row"><text>Store</text><text>{{ detail.store_id }}</text></view>
			<view class="row"><text>Benefit</text><text>{{ detail.benefit_item_id }}</text></view>
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
		<view class="card" v-if="detail.events && detail.events.length">
			<view v-for="event in detail.events" :key="event.id" class="event">
				<text>{{ event.event_type }}</text>
				<text>{{ event.to_status }}</text>
			</view>
		</view>
	</view>
</template>

<script>
import {
	cancelYfthServiceAppointment,
	getYfthAppointmentDetail,
	getYfthAppointmentRescheduleSlots,
	rescheduleYfthServiceAppointment
} from '@/api/yfth.js';

export default {
	data() {
		return {
			id: 0,
			detail: {},
			rescheduleMode: false,
			rescheduleSlots: [],
			selectedSlot: {}
		};
	},
	computed: {
		canOperate() {
			return ['pending_confirm', 'confirmed'].indexOf(this.detail.status) !== -1;
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	methods: {
		load() {
			getYfthAppointmentDetail(this.id).then((res) => {
				this.detail = res.data || {};
			});
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
			getYfthAppointmentRescheduleSlots(this.id, { date: this.detail.date_text }).then((res) => {
				this.rescheduleSlots = ((res.data && res.data.slots) || []).filter((item) => {
					return item.status === 'available' && item.remaining_capacity > 0 && item.start_minute !== this.detail.start_minute;
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
				date: this.detail.date_text,
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
.title {
	font-size: 30rpx;
	font-weight: 700;
	margin-bottom: 16rpx;
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
</style>
