<template>
	<view class="page">
		<view class="panel">
			<view class="row"><text>套餐</text><text>{{ detail.package_name }}</text></view>
			<view class="row"><text>价格</text><text>¥{{ price }}</text></view>
			<view class="row"><text>权益周期</text><text>{{ monthCount }}个月</text></view>
			<view class="row"><text>服务门店</text><text>{{ storeId }}</text></view>
			<view class="row" v-if="orderSn"><text>订单号</text><text>{{ orderSn }}</text></view>
		</view>
		<button class="btn" :disabled="submitting" @click="createOrderAndPay">{{ submitting ? '处理中' : '确认并支付' }}</button>
		<payment
			:payMode="payMode"
			:pay_close="payClose"
			@onChangeFun="onPayChange"
			:order_id="orderSn"
			:totalPrice="price"
		></payment>
	</view>
</template>

<script>
import payment from '@/components/payment';
import {
	createYfthPackageIntent,
	createYfthPackageOrder,
	getYfthPackageDetail,
	getYfthPackageRulePreview
} from '@/api/yfth.js';

export default {
	components: { payment },
	data() {
		return {
			id: 0,
			storeId: 0,
			detail: {},
			preview: { rule: {} },
			intentNo: '',
			purchaseNo: '',
			orderSn: '',
			submitting: false,
			payClose: false,
			payMode: [
				{ name: '微信支付', icon: 'icon-weixin2', value: 'weixin', title: '微信安全支付', payStatus: true },
				{ name: '支付宝支付', icon: 'icon-zhifubao', value: 'alipay', title: '支付宝安全支付', payStatus: true },
				{ name: '余额支付', icon: 'icon-yuezhifu', value: 'yue', title: '可用余额', number: 0, payStatus: true }
			]
		};
	},
	computed: {
		price() {
			return this.preview.rule.package_price || '0.00';
		},
		monthCount() {
			return this.preview.rule.month_count || 0;
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.storeId = Number(options.store_id || 0);
		getYfthPackageDetail(this.id).then((res) => {
			this.detail = res.data || {};
		});
		getYfthPackageRulePreview(this.id).then((res) => {
			this.preview = res.data || { rule: {} };
		});
	},
	methods: {
		createOrderAndPay() {
			if (this.submitting) return;
			this.submitting = true;
			createYfthPackageIntent({
				template_id: this.id,
				store_id: this.storeId,
				source: 'mobile'
			}).then((intentRes) => {
				this.intentNo = intentRes.data.intent_no;
				return createYfthPackageOrder({
					intent_no: this.intentNo,
					pay_type: 'weixin',
					shipping_type: 2,
					source: 'mobile'
				});
			}).then((orderRes) => {
				const data = orderRes.data || {};
				this.purchaseNo = data.purchase ? data.purchase.purchase_no : '';
				this.orderSn = data.order ? data.order.order_id : '';
				this.payClose = true;
			}).catch((err) => {
				this.$util.Tips({ title: err });
			}).finally(() => {
				this.submitting = false;
			});
		},
		onPayChange(e) {
			if (e.action === 'payClose') {
				this.payClose = false;
			}
			if (e.action === 'pay_complete') {
				this.payClose = false;
				uni.redirectTo({
					url: '/pages/yfth/package/payment_result?purchase_no=' + this.purchaseNo
				});
			}
			if (e.action === 'pay_fail') {
				this.payClose = false;
				uni.navigateTo({
					url: '/pages/yfth/package/payment_result?purchase_no=' + this.purchaseNo
				});
			}
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	padding: 24rpx;
	background: #f5f7f8;
}
.panel {
	background: #fff;
	border-radius: 12rpx;
	padding: 24rpx;
	margin-bottom: 20rpx;
}
.row {
	display: flex;
	justify-content: space-between;
	gap: 24rpx;
	padding: 16rpx 0;
	border-bottom: 1px solid #edf0f2;
	font-size: 28rpx;
	color: #263238;
}
.row text:last-child {
	text-align: right;
	word-break: break-all;
}
.btn {
	margin-top: 16rpx;
	height: 86rpx;
	line-height: 86rpx;
	background: #2f7668;
	color: #fff;
	border-radius: 10rpx;
}
.btn[disabled] {
	background: #9fb9b4;
	color: #fff;
}
</style>
