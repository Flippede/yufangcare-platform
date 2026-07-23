<template>
	<view class="page">
		<view v-if="loading" class="empty">加载中...</view>
		<block v-else-if="detail.order">
			<view class="status-card"><b>{{ detail.order.status_text || detail.order.status }}</b><span>{{ statusHint }}</span></view>
			<view class="address card"><view class="pin">⌖</view><view><b>{{ detail.order.real_name }}　{{ detail.order.user_phone }}</b><span>{{ detail.order.user_address || '未记录收货地址' }}</span></view></view>
			<view class="card">
				<view v-for="item in detail.items" :key="item.id" class="goods">
					<image v-if="item.product_image" class="product-image" :src="item.product_image" mode="aspectFill"></image>
					<view v-else class="placeholder">采</view>
					<view class="goods-info"><b>{{ item.product_name_snapshot }}</b><span>{{ item.sku_name_snapshot || item.sku_unique }}</span><small>¥{{ item.purchase_price_snapshot }} × {{ item.quantity }}</small></view>
					<strong>¥{{ item.amount_snapshot }}</strong>
				</view>
			</view>
			<view class="card rows">
				<view><span>商品金额</span><b>¥{{ detail.order.amount_snapshot }}</b></view>
				<view><span>运费</span><b>¥{{ detail.order.freight_price || '0.00' }}</b></view>
				<view v-if="detail.quota_payment"><span>商品额度</span><b>-¥{{ money(detail.quota_payment.quota_amount_cent) }}</b></view>
				<view v-if="detail.quota_payment"><span>在线/线下应付</span><b class="price">¥{{ money(detail.quota_payment.online_amount_cent) }}</b></view>
				<view><span>付款状态</span><b>{{ payStatusText }}</b></view>
			</view>
			<view class="card">
				<view class="section-title">物流信息</view>
				<view v-if="!detail.shipments.length" class="muted">总部审核发货后，可在这里查看物流公司和运单号。</view>
				<view v-for="item in detail.shipments" :key="item.id" class="shipment">
					<b>{{ item.logistics_company || '总部配送' }}</b>
					<span>运单号：{{ item.logistics_no || '待录入' }}</span>
					<small>发货单 {{ item.shipment_no }} · {{ item.status }}</small>
				</view>
			</view>
			<view class="card rows">
				<view><span>采购单号</span><b>{{ detail.order.purchase_no }}</b></view>
				<view><span>下单时间</span><b>{{ formatTime(detail.order.create_time) }}</b></view>
				<view v-if="detail.order.buyer_mark"><span>采购备注</span><b>{{ detail.order.buyer_mark }}</b></view>
			</view>
			<button v-if="detail.order.status==='shipped'" class="receive" @click="receive">确认收货并入库</button>
		</block>
		<view v-else class="empty">采购订单不存在</view>
	</view>
</template>
<script>
import { getYfthPurchaseOrderDetail, receiveYfthPurchaseOrder } from '@/api/yfth.js';import { currentContext } from '@/libs/yfthContext.js';
export default{data(){return{id:0,detail:{},loading:false,context:{}};},computed:{statusHint(){return{submitted:'采购单已提交，等待总部审核',approved:'总部已审核，等待仓库发货',rejected:'采购单未通过审核',shipped:'商品已发出，请注意查收',stocked:'已收货并计入门店库存',cancelled:'采购单已取消'}[this.detail.order.status]||'';},payStatusText(){return{pending:'待结算',paid:'已支付',offline:'线下结算'}[this.detail.order.pay_status]||'待结算';}},onLoad(o){this.id=Number(o.id||0);this.context=currentContext();this.load();},methods:{money(v){return(Number(v||0)/100).toFixed(2);},load(){this.loading=true;getYfthPurchaseOrderDetail(this.id,{role_code:this.context.role_code,store_id:this.context.store_id}).then(res=>{this.detail=res.data||{};}).catch(err=>uni.showToast({title:String((err&&err.msg)||err),icon:'none'})).finally(()=>{this.loading=false;});},receive(){uni.showModal({title:'确认收货',content:'确认商品已经收到并入库？',success:r=>{if(!r.confirm)return;receiveYfthPurchaseOrder(this.id,{role_code:this.context.role_code,store_id:this.context.store_id,idempotency_key:'yfth_receipt_'+this.id+'_'+Date.now()}).then(()=>{uni.showToast({title:'已收货入库',icon:'success'});this.load();});}});},formatTime(v){return v?new Date(Number(v)*1000).toLocaleString():'-';}}};
</script>
<style scoped>
.page{min-height:100vh;background:#f5f1e9;padding:20rpx 20rpx 50rpx}.status-card{padding:42rpx 28rpx;background:#805b32;color:#fff;border-radius:10rpx}.status-card b,.status-card span{display:block}.status-card b{font-size:38rpx}.status-card span{margin-top:12rpx;font-size:23rpx;opacity:.85}.card,.empty{margin-top:16rpx;padding:24rpx;background:#fff;border-radius:10rpx}.address{display:flex;align-items:center;gap:20rpx}.pin{font-size:40rpx;color:#a97942}.address b,.address span{display:block}.address span{margin-top:10rpx;color:#82776d;font-size:23rpx}.goods{display:flex;align-items:center;gap:18rpx;padding:16rpx 0;border-bottom:1rpx solid #eee}.goods:last-child{border:0}.placeholder,.product-image{width:100rpx;height:100rpx;background:#eee6da;border-radius:8rpx;flex:none}.placeholder{color:#9e7547;text-align:center;line-height:100rpx;font-size:34rpx}.goods-info{flex:1}.goods-info b,.goods-info span,.goods-info small{display:block}.goods-info span{color:#928579;font-size:21rpx;margin-top:6rpx}.goods-info small{color:#a5753f;margin-top:12rpx}.rows>view{display:flex;justify-content:space-between;gap:20rpx;padding:16rpx 0;border-bottom:1rpx solid #eee;font-size:23rpx}.rows>view:last-child{border:0}.rows b{text-align:right}.price{color:#b57630;font-size:28rpx}.section-title{font-size:29rpx;font-weight:700;margin-bottom:16rpx}.muted,.shipment span,.shipment small{display:block;color:#8f8175;font-size:22rpx;margin-top:8rpx}.shipment{padding:16rpx 0;border-top:1rpx solid #eee}.receive{margin-top:24rpx;background:#805b32;color:#fff}.empty{text-align:center;color:#8f8175;padding:80rpx 20rpx}
</style>
