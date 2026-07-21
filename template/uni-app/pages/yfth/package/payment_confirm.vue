<template>
	<view class="page">
		<view class="panel">
			<view class="row"><text>套餐</text><text>{{ detail.package_name }}</text></view>
			<view class="row"><text>价格</text><text>¥{{ price }}</text></view>
			<view class="row"><text>权益周期</text><text>{{ monthCount }}个月</text></view>
			<view class="row"><text>服务门店</text><text>门店 {{ storeId }}</text></view>
			<view v-if="orderSn" class="row"><text>订单号</text><text>{{ orderSn }}</text></view>
			<view class="notice">已是永久会员仍可再次购买，每笔订单分别生成套餐权益。</view>
		</view>
		<button class="btn" :disabled="submitting" @click="createOrderAndPay">{{ submitting ? '处理中' : '确认并支付' }}</button>
		<payment :payMode="payMode" :pay_close="payClose" @onChangeFun="onPayChange" :order_id="orderSn" :totalPrice="price"></payment>
	</view>
</template>

<script>
import payment from '@/components/payment';
import { createYfthPackageIntent, createYfthPackageOrder, getYfthPackageDetail, getYfthPackageRulePreview } from '@/api/yfth.js';
export default {
	components: { payment },
	data() {
		return { id: 0, storeId: 0, detail: {}, preview: { rule: {} }, intentNo: '', purchaseNo: '', orderSn: '', submitting: false, payClose: false,
			payMode: [
				{ name: '微信支付', icon: 'icon-weixin2', value: 'weixin', title: '微信安全支付', payStatus: true },
				{ name: '支付宝支付', icon: 'icon-zhifubao', value: 'alipay', title: '支付宝安全支付', payStatus: true },
				{ name: '余额支付', icon: 'icon-yuezhifu', value: 'yue', title: '可用余额', number: 0, payStatus: true }
			]
		};
	},
	computed: { price() { return this.preview.rule.package_price || '0.00'; }, monthCount() { return this.preview.rule.month_count || 0; } },
	onLoad(options) {
		this.id = Number(options.id || 0); this.storeId = Number(options.store_id || 0);
		getYfthPackageDetail(this.id).then((res) => { this.detail = res.data || {}; });
		getYfthPackageRulePreview(this.id).then((res) => { this.preview = res.data || { rule: {} }; });
	},
	methods: {
		createOrderAndPay() {
			if (this.submitting) return;
			this.submitting = true;
			createYfthPackageIntent({ template_id: this.id, store_id: this.storeId, source: 'mobile' })
				.then((res) => { this.intentNo = res.data.intent_no; return createYfthPackageOrder({ intent_no: this.intentNo, pay_type: 'weixin', shipping_type: 2, source: 'mobile' }); })
				.then((res) => { const data = res.data || {}; this.purchaseNo = data.purchase ? data.purchase.purchase_no : ''; this.orderSn = data.order ? data.order.order_id : ''; this.payClose = true; })
				.catch((err) => this.$util.Tips({ title: (err && (err.msg || err.message)) || String(err || '订单创建失败') }))
				.finally(() => { this.submitting = false; });
		},
		onPayChange(e) {
			if (e.action === 'payClose') this.payClose = false;
			if (e.action === 'pay_complete') { this.payClose = false; uni.redirectTo({ url: '/pages/yfth/package/payment_result?purchase_no=' + this.purchaseNo }); }
			if (e.action === 'pay_fail') { this.payClose = false; uni.navigateTo({ url: '/pages/yfth/package/payment_result?purchase_no=' + this.purchaseNo }); }
		}
	}
};
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx; background: #f5f2ed; }.panel { margin-bottom: 20rpx; padding: 24rpx; border-radius: 12rpx; background: #fff; }
.row { display: flex; justify-content: space-between; gap: 24rpx; padding: 16rpx 0; border-bottom: 1px solid #edf0f2; color: #312a22; font-size: 28rpx; }.row text:last-child { text-align: right; word-break: break-all; }
.notice { margin-top: 20rpx; padding: 18rpx; border: 1px solid #ead6b5; border-radius: 8rpx; background: #fff8ea; color: #79562f; font-size: 24rpx; line-height: 1.6; }
.btn { margin-top: 16rpx; height: 86rpx; line-height: 86rpx; border-radius: 10rpx; background: #9a7342; color: #fff; }.btn[disabled] { opacity: .5; }
</style>
