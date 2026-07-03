<template>
	<view class="page">
		<view class="section">
			<view class="label">Service</view>
			<picker :range="projects" range-key="service_name" @change="selectProject">
				<view class="picker">{{ project.service_name || 'Select service' }}</view>
			</picker>
		</view>
		<view class="section" v-if="project.id">
			<view class="label">Store</view>
			<picker :range="stores" range-key="store_name" @change="selectStore">
				<view class="picker">{{ store.store_name || 'Select store' }}</view>
			</picker>
		</view>
		<view class="section" v-if="store.store_id">
			<view class="label">Date</view>
			<picker :range="dates" range-key="date" @change="selectDate">
				<view class="picker">{{ date.date || 'Select date' }}</view>
			</picker>
		</view>
		<view class="section" v-if="date.date">
			<view class="label">Slot</view>
			<view class="slot-list">
				<view
					v-for="item in slots"
					:key="item.slot_key"
					:class="['slot', selectedSlot.start_minute === item.start_minute ? 'active' : '']"
					@click="selectSlot(item)"
				>{{ item.start_time }}-{{ item.end_time }} / {{ item.remaining_capacity }}</view>
			</view>
		</view>
		<view class="section" v-if="project.id">
			<view class="label">Benefit</view>
			<picker :range="benefits" range-key="benefit_name" @change="selectBenefit">
				<view class="picker">{{ benefit.benefit_name || 'Select benefit' }}</view>
			</picker>
		</view>
		<textarea v-model="note" class="note" placeholder="Note" />
		<button class="primary" :disabled="submitting" @click="submit">Submit</button>
	</view>
</template>

<script>
import {
	createYfthServiceAppointment,
	getYfthAppointmentBenefits,
	getYfthServiceAvailableDates,
	getYfthServiceDaySlots,
	getYfthServiceProjects,
	getYfthServiceStores
} from '@/api/yfth.js';

export default {
	data() {
		return {
			projects: [],
			stores: [],
			dates: [],
			slots: [],
			benefits: [],
			project: {},
			store: {},
			date: {},
			selectedSlot: {},
			benefit: {},
			note: '',
			submitting: false
		};
	},
	onLoad() {
		this.loadProjects();
	},
	methods: {
		loadProjects() {
			getYfthServiceProjects({ page: 1, limit: 50 }).then((res) => {
				this.projects = (res.data && res.data.list) || [];
			});
		},
		selectProject(e) {
			this.project = this.projects[Number(e.detail.value)] || {};
			this.store = {};
			this.date = {};
			this.selectedSlot = {};
			getYfthServiceStores(this.project.id).then((res) => {
				this.stores = (res.data && res.data.list) || [];
			});
			getYfthAppointmentBenefits({ service_project_id: this.project.id }).then((res) => {
				this.benefits = (res.data && res.data.list) || [];
			});
		},
		selectStore(e) {
			this.store = this.stores[Number(e.detail.value)] || {};
			this.date = {};
			this.selectedSlot = {};
			getYfthServiceAvailableDates(this.project.id, {
				store_id: this.store.store_id,
				start_date: '',
				end_date: ''
			}).then((res) => {
				this.dates = ((res.data && res.data.dates) || []).filter((item) => item.available);
			});
		},
		selectDate(e) {
			this.date = this.dates[Number(e.detail.value)] || {};
			this.selectedSlot = {};
			getYfthServiceDaySlots(this.project.id, {
				store_id: this.store.store_id,
				date: this.date.date
			}).then((res) => {
				this.slots = ((res.data && res.data.slots) || []).filter((item) => item.status === 'available' && item.remaining_capacity > 0);
			});
		},
		selectSlot(item) {
			this.selectedSlot = item;
		},
		selectBenefit(e) {
			this.benefit = this.benefits[Number(e.detail.value)] || {};
		},
		submit() {
			if (!this.project.id || !this.store.store_id || !this.date.date || !this.selectedSlot.slot_key || !this.benefit.id) {
				uni.showToast({ title: 'Incomplete', icon: 'none' });
				return;
			}
			this.submitting = true;
			createYfthServiceAppointment({
				service_project_id: this.project.id,
				store_id: this.store.store_id,
				benefit_item_id: this.benefit.id,
				date: this.date.date,
				start_minute: this.selectedSlot.start_minute,
				user_note: this.note,
				idempotency_key: 'mp_' + Date.now()
			}).then((res) => {
				const id = res.data && res.data.appointment && res.data.appointment.id;
				uni.redirectTo({ url: '/pages/yfth/appointment/detail?id=' + id });
			}).finally(() => {
				this.submitting = false;
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
.section {
	background: #fff;
	border-radius: 12rpx;
	padding: 22rpx;
	margin-bottom: 18rpx;
}
.label {
	font-size: 24rpx;
	color: #60707c;
	margin-bottom: 12rpx;
}
.picker {
	font-size: 30rpx;
	color: #1f2a33;
}
.slot-list {
	display: flex;
	flex-wrap: wrap;
	gap: 14rpx;
}
.slot {
	border: 1px solid #d7dee4;
	border-radius: 8rpx;
	padding: 12rpx 16rpx;
	color: #394854;
}
.slot.active {
	border-color: #1f7a6b;
	color: #1f7a6b;
}
.note {
	width: 100%;
	min-height: 120rpx;
	background: #fff;
	border-radius: 12rpx;
	padding: 20rpx;
	box-sizing: border-box;
	margin-bottom: 20rpx;
}
.primary {
	background: #1f7a6b;
	color: #fff;
	border-radius: 10rpx;
}
</style>
