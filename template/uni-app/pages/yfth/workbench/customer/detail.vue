<template>
	<view class="page">
		<view class="profile">
			<view>
				<view class="name">{{ customer.nickname || ('客户关系 #' + customer.id) }}</view>
				<view class="muted">{{ customer.phone_masked || '未留手机号' }} · {{ customer.source_text }}</view>
			</view>
			<view class="status">{{ customer.customer_status_text }}</view>
		</view>

		<view class="panel">
			<view class="panel-title">客户概况</view>
			<view class="line">归属来源：{{ customer.source_text }}</view>
			<view class="line">5980 套餐：{{ customer.package_status === 'active' ? '已购买' : '暂无' }}</view>
			<view class="line">服务状态：{{ customer.service_status === 'has_appointment' ? '已有预约' : '暂无预约' }}</view>
		</view>

		<view class="panel">
			<view class="panel-head">
				<view class="panel-title">跟进记录</view>
				<button @click="goFollow">新增跟进</button>
			</view>
			<view v-if="!follows.length" class="empty">暂无跟进记录。</view>
			<view v-else>
				<view v-for="item in follows" :key="item.id" class="follow-card">
					<view class="row">
						<view class="strong">{{ item.follow_type_text }}</view>
						<view class="muted">{{ formatTime(item.follow_time) }}</view>
					</view>
					<view class="content">{{ item.content }}</view>
					<view v-if="item.next_follow_time" class="muted">下次跟进：{{ formatTime(item.next_follow_time) }}</view>
				</view>
			</view>
		</view>

		<pageFooter :business-context="context" business-pane="customers"></pageFooter>
	</view>
</template>

<script>
import { getYfthCustomerDetail } from '@/api/yfth.js';
import pageFooter from '@/components/pageFooter/index.vue';
import { currentContext } from '@/libs/yfthContext.js';

export default {
	components: { pageFooter },
	data() {
		return {
			id: 0,
			context: {},
			customer: {},
			follows: []
		};
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
	},
	onShow() {
		this.context = currentContext();
		this.load();
	},
	methods: {
		contextParams(extra) {
			return Object.assign({
				role_code: this.context.role_code,
				store_id: this.context.store_id
			}, extra || {});
		},
		load() {
			if (!this.id) return;
			getYfthCustomerDetail(this.id, this.contextParams()).then((res) => {
				this.customer = (res.data && res.data.customer) || {};
				this.follows = (res.data && res.data.follow_records) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		goFollow() {
			uni.navigateTo({ url: '/pages/yfth/workbench/customer/follow?id=' + this.id });
		},
		formatTime(value) {
			const ts = Number(value || 0);
			if (!ts) return '-';
			const date = new Date(ts * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; box-sizing: border-box; background: #f6f0e6; padding: 24rpx; overflow-x: hidden; }
.profile, .panel { background: #fff; border-radius: 16rpx; padding: 24rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); margin-bottom: 20rpx; }
.profile, .panel-head, .row { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; }
.name, .panel-title, .strong { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.muted, .line { color: #786b73; font-size: 24rpx; margin-top: 10rpx; }
.status { color: #6f4c2f; font-weight: 700; font-size: 24rpx; }
button { background: #6f4c2f; color: #fff; border-radius: 10rpx; height: 60rpx; line-height: 60rpx; font-size: 24rpx; padding: 0 18rpx; margin: 0; }
.follow-card { background: #fffaf2; border-radius: 12rpx; padding: 18rpx; margin-top: 16rpx; }
.content { color: #3a3029; font-size: 26rpx; line-height: 1.6; margin-top: 12rpx; }
.empty { color: #786b73; text-align: center; padding: 28rpx 0 8rpx; font-size: 24rpx; }
</style>
