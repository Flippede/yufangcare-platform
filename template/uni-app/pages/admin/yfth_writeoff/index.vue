<template>
	<view class="page">
		<view class="panel">
			<view class="title">Service Writeoff</view>
			<input class="input" v-model="digitalCode" type="number" maxlength="6" placeholder="6-digit code" />
			<view class="actions">
				<button class="plain" @click="scanCode">Scan</button>
				<button class="primary" @click="precheckDigital">Precheck</button>
			</view>
		</view>

		<view v-if="summary.appointment_no" class="panel">
			<view class="title">{{ summary.appointment_no }}</view>
			<view class="row"><text>User</text><text>{{ summary.uid }}</text></view>
			<view class="row"><text>Store</text><text>{{ summary.store_name || summary.store_id }}</text></view>
			<view class="row"><text>Service</text><text>{{ summary.service_name }}</text></view>
			<view class="row"><text>Time</text><text>{{ summary.date_text }} {{ summary.start_time_text }}-{{ summary.end_time_text }}</text></view>
			<view class="row"><text>Status</text><text>{{ summary.status }}</text></view>
			<button class="primary confirm" @click="confirmWriteoff">Confirm Writeoff</button>
		</view>

		<view v-if="result.status" class="panel">
			<view class="title">Result</view>
			<view class="row"><text>Status</text><text>{{ result.status }}</text></view>
			<view v-if="result.record" class="row"><text>No.</text><text>{{ result.record.writeoff_no }}</text></view>
		</view>
	</view>
</template>

<script>
import {
	precheckYfthServiceWriteoff,
	writeoffYfthServiceByDigital,
	writeoffYfthServiceByToken
} from '@/api/yfth_admin.js';

export default {
	data() {
		return {
			digitalCode: '',
			qrToken: '',
			summary: {},
			result: {}
		};
	},
	onLoad(options) {
		if (options.token) {
			this.qrToken = decodeURIComponent(options.token);
			this.precheckToken();
		}
	},
	methods: {
		scanCode() {
			uni.scanCode({
				success: (res) => {
					this.qrToken = decodeURIComponent(res.result || res.path || '');
					this.precheckToken();
				}
			});
		},
		precheckToken() {
			if (!this.qrToken) {
				uni.showToast({ title: 'Scan a code first', icon: 'none' });
				return;
			}
			precheckYfthServiceWriteoff({ qr_token: this.qrToken }).then((res) => {
				this.summary = res.data && res.data.appointment ? res.data.appointment : {};
				this.result = {};
			}).catch(this.showError);
		},
		precheckDigital() {
			if (!/^\d{6}$/.test(this.digitalCode)) {
				uni.showToast({ title: 'Enter 6 digits', icon: 'none' });
				return;
			}
			this.qrToken = '';
			precheckYfthServiceWriteoff({ digital_code: this.digitalCode }).then((res) => {
				this.summary = res.data && res.data.appointment ? res.data.appointment : {};
				this.result = {};
			}).catch(this.showError);
		},
		confirmWriteoff() {
			const payload = { idempotency_key: 'writeoff_' + Date.now() };
			const request = this.qrToken
				? writeoffYfthServiceByToken(this.qrToken, payload)
				: writeoffYfthServiceByDigital(this.digitalCode, payload);
			request.then((res) => {
				this.result = res.data || {};
				this.summary = (res.data && res.data.appointment) || this.summary;
				uni.showToast({ title: 'Writeoff completed', icon: 'success' });
			}).catch(this.showError);
		},
		showError(err) {
			uni.showToast({ title: String(err), icon: 'none' });
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
.panel {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 18rpx;
}
.title {
	font-size: 30rpx;
	font-weight: 700;
	margin-bottom: 18rpx;
}
.input {
	height: 84rpx;
	border: 1rpx solid #d9e1e5;
	border-radius: 10rpx;
	padding: 0 20rpx;
	font-size: 34rpx;
}
.actions {
	display: flex;
	gap: 16rpx;
	margin-top: 18rpx;
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
	border: 1rpx solid #d9e1e5;
}
.confirm {
	margin-top: 22rpx;
}
.row {
	display: flex;
	justify-content: space-between;
	color: #60707c;
	margin-top: 12rpx;
}
</style>
