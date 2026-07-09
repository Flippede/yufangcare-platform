<template>
	<view class="page">
		<view class="hero">
			<view class="eyebrow">5980 家庭康养套餐</view>
			<view class="title">月度权益领取</view>
			<view class="sub">产品类权益领取后进入总部配送或门店自提履约流程。</view>
		</view>
		<view class="actions">
			<button @click="load">刷新</button>
			<button @click="goHistory">履约历史</button>
		</view>
		<view class="form-card">
			<view class="muted">快递配送需填写当前账号真实收货地址 ID；到店自提默认使用权益归属门店，也可填写指定自提门店 ID。</view>
			<input v-model="addressId" type="number" placeholder="收货地址 ID" />
			<input v-model="pickupStoreId" type="number" placeholder="自提门店 ID，可留空" />
		</view>
		<view v-if="loading" class="empty">正在读取权益...</view>
		<view v-else-if="!items.length" class="empty">暂无可领取的产品类权益</view>
		<view v-for="item in items" :key="item.id" class="card">
			<view class="row">
				<view>
					<view class="name">{{ item.benefit_name || '产品权益' }}</view>
					<view class="muted">第 {{ item.month_no }} 月 · {{ item.benefit_code }}</view>
				</view>
				<view class="status">{{ item.fulfillment ? statusText(item.fulfillment.status) : '可领取' }}</view>
			</view>
			<view class="muted">数量 {{ item.quantity_available }} / {{ item.quantity_total }}</view>
			<view class="button-row" v-if="item.claimable">
				<button class="primary" @click="claim(item, 'express_delivery')">快递配送</button>
				<button @click="claim(item, 'self_pickup')">到店自提</button>
			</view>
			<view class="button-row" v-else-if="item.fulfillment">
				<button @click="goDetail(item.fulfillment.id)">查看履约</button>
			</view>
		</view>
	</view>
</template>

<script>
import { claimYfthMonthlyBenefit, getYfthMonthlyBenefitCurrent } from '@/api/yfth.js';

export default {
	data() {
		return { loading: false, items: [], addressId: '', pickupStoreId: '' };
	},
	onShow() {
		this.load();
	},
	methods: {
		load() {
			this.loading = true;
			getYfthMonthlyBenefitCurrent().then((res) => {
				this.items = (res.data && res.data.product_items) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.loading = false;
			});
		},
		claim(item, method) {
			const payload = {
				benefit_item_id: item.id,
				fulfillment_method: method,
				client_operation_key: 'monthly_claim_' + item.id + '_' + Date.now()
			};
			if (method === 'express_delivery') {
				payload.address_id = Number(this.addressId || 0);
			} else {
				payload.pickup_store_id = Number(this.pickupStoreId || item.store_id || 0);
			}
			claimYfthMonthlyBenefit(payload).then((res) => {
				const fulfillment = res.data && res.data.fulfillment;
				uni.showToast({ title: '领取成功', icon: 'success' });
				if (fulfillment && fulfillment.id) this.goDetail(fulfillment.id);
				this.load();
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		goHistory() {
			uni.navigateTo({ url: '/pages/yfth/monthly_benefit/history' });
		},
		goDetail(id) {
			uni.navigateTo({ url: '/pages/yfth/monthly_benefit/detail?id=' + id });
		},
		statusText(status) {
			const map = { pending_confirm: '待确认', confirmed: '已确认', preparing: '备货中', shipped: '已发货', completed: '已完成', cancelled: '已取消', rejected: '已驳回', exception: '异常' };
			return map[status] || status;
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f8f0e5; padding: 24rpx; }
.hero { background: linear-gradient(135deg, #65442d, #b18245); color: #fff; border-radius: 20rpx; padding: 30rpx; }
.eyebrow { font-size: 22rpx; color: #f6dfb7; }
.title { font-size: 42rpx; font-weight: 700; margin-top: 8rpx; }
.sub { font-size: 24rpx; margin-top: 10rpx; color: #fff2dd; }
.actions, .button-row { display: flex; gap: 18rpx; margin: 22rpx 0; }
button { flex: 1; background: #fff7e8; color: #6b4a30; border-radius: 12rpx; font-size: 26rpx; }
.primary { background: #6f4c2f; color: #fff; }
.card, .empty, .form-card { background: #fff; border-radius: 18rpx; padding: 24rpx; margin-top: 18rpx; box-shadow: 0 10rpx 24rpx rgba(72, 45, 25, .06); }
input { margin-top: 16rpx; height: 66rpx; line-height: 66rpx; border-radius: 12rpx; background: #fff7e8; padding: 0 20rpx; font-size: 26rpx; }
.row { display: flex; justify-content: space-between; gap: 20rpx; }
.name { color: #2b2320; font-size: 31rpx; font-weight: 700; }
.muted { color: #806d61; font-size: 24rpx; margin-top: 8rpx; }
.status { color: #8a5a2b; font-size: 25rpx; font-weight: 700; }
.empty { text-align: center; color: #806d61; }
</style>
