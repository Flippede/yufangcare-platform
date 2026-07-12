<template>
	<view class="page">
		<view class="header">
			<view>
				<view class="eyebrow">{{ context.store_name || '当前经营门店' }}</view>
				<view class="title">客户归属</view>
				<view class="subtitle">正式归属与推荐状态只读视图</view>
			</view>
			<button class="refresh" @click="load(true)">刷新</button>
		</view>

		<view class="filters">
			<input v-model="query.keyword" placeholder="昵称或手机号" confirm-type="search" @confirm="load(true)" />
			<picker :range="statusLabels" @change="changeStatus">
				<view class="picker">{{ currentStatusLabel }}</view>
			</picker>
			<button class="search" @click="load(true)">查询</button>
		</view>

		<view v-if="loading" class="empty">正在加载...</view>
		<view v-else-if="error" class="empty error">
			<view>{{ error }}</view>
			<button class="retry" @click="load(true)">重新加载</button>
		</view>
		<view v-else-if="!list.length" class="empty">当前门店暂无正式归属客户</view>
		<view v-else>
			<view v-for="item in list" :key="item.attribution_id" class="customer-card" @click="openDetail(item)">
				<image v-if="item.customer.avatar" class="avatar" :src="item.customer.avatar" mode="aspectFill" />
				<view v-else class="avatar avatar-placeholder">客</view>
				<view class="customer-main">
					<view class="customer-head">
						<view class="name">{{ item.customer.nickname || '未设置昵称' }}</view>
						<view :class="['status', item.attribution_status]">{{ item.attribution_status_label }}</view>
					</view>
					<view class="muted">{{ item.customer.phone_masked || '手机号未填写' }}</view>
					<view class="meta">{{ item.source_label }} · {{ item.has_active_referral ? '存在一级推荐' : '无有效推荐' }}</view>
					<view class="meta">绑定：{{ formatTime(item.bound_at) }}</view>
				</view>
			</view>
			<button v-if="list.length < total" class="load-more" @click="load(false)">加载更多</button>
		</view>

		<view v-if="detailVisible" class="mask" @click="detailVisible = false">
			<view class="detail" @click.stop>
				<view class="detail-title">归属详情</view>
				<view class="detail-row"><text>客户</text><text>{{ detail.customer.nickname || '-' }}</text></view>
				<view class="detail-row"><text>手机号</text><text>{{ detail.customer.phone_masked || '-' }}</text></view>
				<view class="detail-row"><text>状态</text><text>{{ detail.attribution_status_label }}</text></view>
				<view class="detail-row"><text>安全来源</text><text>{{ detail.source_label }}</text></view>
				<view class="detail-row"><text>一级推荐</text><text>{{ detail.has_active_referral ? '存在' : '无' }}</text></view>
				<view class="detail-row"><text>绑定时间</text><text>{{ formatTime(detail.bound_at) }}</text></view>
				<button class="close" @click="detailVisible = false">关闭</button>
			</view>
		</view>
	</view>
</template>

<script>
import { getYfthStoreCustomerAttributionDetail, getYfthStoreCustomerAttributions } from '@/api/yfth.js';
import { clearYfthContext, currentContext, resolveYfthContext } from '@/libs/yfthContext.js';

export default {
	data() {
		return {
			context: {},
			query: { keyword: '', status: '', page: 1, limit: 15 },
			statusValues: ['', 'active', 'paused'],
			statusLabels: ['全部状态', '归属有效', '归属暂停'],
			list: [],
			total: 0,
			loading: true,
			error: '',
			detailVisible: false,
			detail: {}
		};
	},
	computed: {
		currentStatusLabel() {
			const index = this.statusValues.indexOf(this.query.status);
			return this.statusLabels[index < 0 ? 0 : index];
		}
	},
	onShow() {
		this.bootstrap();
	},
	methods: {
		bootstrap() {
			const cached = currentContext();
			resolveYfthContext(cached.role_code || 'customer', cached.store_id || 0).then((context) => {
				if (['franchisee', 'store_manager'].indexOf(context.role_code) === -1 || !Number(context.store_id)) {
					throw new Error('当前身份无权查看客户归属');
				}
				this.context = context;
				this.load(true);
			}).catch((err) => {
				clearYfthContext();
				this.loading = false;
				this.error = String((err && err.msg) || err || '身份校验失败');
			});
		},
		params(extra) {
			return Object.assign({ role_code: this.context.role_code, store_id: this.context.store_id }, extra || {});
		},
		load(reset) {
			if (!this.context.store_id) return;
			if (reset) {
				this.query.page = 1;
				this.list = [];
			}
			this.loading = true;
			this.error = '';
			getYfthStoreCustomerAttributions(this.params(this.query)).then((res) => {
				const data = res.data || {};
				this.list = reset ? (data.list || []) : this.list.concat(data.list || []);
				this.total = Number(data.count || 0);
				if (this.list.length < this.total) this.query.page += 1;
			}).catch((err) => {
				this.error = String((err && err.msg) || err || '读取失败');
			}).finally(() => {
				this.loading = false;
			});
		},
		changeStatus(event) {
			this.query.status = this.statusValues[Number(event.detail.value) || 0];
			this.load(true);
		},
		openDetail(item) {
			getYfthStoreCustomerAttributionDetail(item.attribution_id, this.params()).then((res) => {
				this.detail = (res.data && res.data.attribution) || {};
				this.detailVisible = true;
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err || '详情读取失败'), icon: 'none' });
			});
		},
		formatTime(value) {
			if (!Number(value)) return '-';
			const date = new Date(Number(value) * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f2ed; padding: 24rpx; box-sizing: border-box; }
.header { display: flex; justify-content: space-between; align-items: center; background: #4c382b; color: #fff; border-radius: 12rpx; padding: 28rpx; }
.eyebrow { color: #dec9aa; font-size: 22rpx; }
.title { font-size: 36rpx; font-weight: 700; margin-top: 6rpx; }
.subtitle { color: #e8ddd0; font-size: 23rpx; margin-top: 6rpx; }
.refresh { width: 116rpx; height: 64rpx; line-height: 64rpx; padding: 0; border-radius: 8rpx; background: #fff8ed; color: #5d422e; font-size: 24rpx; }
.filters { display: grid; grid-template-columns: 1fr 190rpx 110rpx; gap: 12rpx; margin: 18rpx 0; }
.filters input, .picker { height: 70rpx; line-height: 70rpx; background: #fff; border: 1rpx solid #ded4c7; border-radius: 8rpx; padding: 0 18rpx; font-size: 24rpx; box-sizing: border-box; }
.picker { text-align: center; padding: 0 8rpx; }
.search { height: 70rpx; line-height: 70rpx; background: #6a4b34; color: #fff; border-radius: 8rpx; padding: 0; font-size: 24rpx; }
.customer-card { display: flex; gap: 20rpx; background: #fff; border: 1rpx solid #e4dbd0; border-radius: 10rpx; margin-bottom: 14rpx; padding: 22rpx; }
.avatar { width: 82rpx; height: 82rpx; border-radius: 50%; background: #eee4d8; flex: 0 0 auto; }
.avatar-placeholder { display: flex; align-items: center; justify-content: center; color: #795a40; }
.customer-main { min-width: 0; flex: 1; }
.customer-head { display: flex; justify-content: space-between; gap: 16rpx; align-items: center; }
.name { color: #2f2925; font-size: 29rpx; font-weight: 600; }
.status { color: #376d45; background: #e5f2e8; border-radius: 6rpx; padding: 6rpx 10rpx; font-size: 20rpx; }
.status.paused { color: #94620d; background: #fff0d2; }
.muted, .meta { color: #8a7b6e; font-size: 23rpx; margin-top: 7rpx; }
.empty { background: #fff; border: 1rpx solid #e4dbd0; border-radius: 10rpx; padding: 80rpx 20rpx; color: #847568; text-align: center; }
.retry, .load-more, .close { margin-top: 22rpx; background: #6a4b34; color: #fff; border-radius: 8rpx; font-size: 24rpx; }
.load-more { width: 260rpx; }
.mask { position: fixed; inset: 0; z-index: 20; background: rgba(0,0,0,.42); display: flex; align-items: flex-end; }
.detail { width: 100%; background: #fff; padding: 30rpx 28rpx calc(30rpx + env(safe-area-inset-bottom)); border-radius: 16rpx 16rpx 0 0; }
.detail-title { color: #302922; font-size: 32rpx; font-weight: 700; margin-bottom: 16rpx; }
.detail-row { display: flex; justify-content: space-between; gap: 20rpx; min-height: 72rpx; align-items: center; border-bottom: 1rpx solid #eee7de; color: #6f6358; font-size: 25rpx; }
</style>
