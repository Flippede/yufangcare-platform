<template>
	<view class="page">
		<view class="hero">
			<view class="title">{{ detail.package_name || '御方通和康养会员套餐' }}</view>
			<view class="price">¥ {{ detail.rule ? detail.rule.package_price : detail.base_price || '0.00' }}</view>
			<view class="meta">{{ detail.benefit_months || 0 }}个月套餐权益</view>
			<view v-if="detail.rule && detail.rule.grants_permanent_membership" class="membership-badge">购买后激活永久会员资格</view>
		</view>
		<view class="section">
			<view class="section-title">套餐说明</view>
			<view class="summary">{{ detail.service_summary || '购买后生成独立套餐权益，已是会员也可以再次购买。' }}</view>
			<view class="notice">每次购买都会生成独立订单和权益记录；永久会员资格保持有效，不会阻止再次购买。</view>
		</view>
		<view class="section">
			<view class="section-title">套餐权益</view>
			<view v-for="item in benefits" :key="item.id" class="benefit">
				<text>{{ item.benefit_name }}</text><text>第{{ item.month_no }}月 · {{ item.quantity }}份</text>
			</view>
			<view v-if="!benefits.length" class="empty">套餐权益以当前发布规则为准</view>
		</view>
		<view class="section store-section">
			<view class="section-title">归属门店</view>
			<view v-if="storeLoading" class="empty">正在读取当前权威归属...</view>
			<view v-else-if="store.store_id" class="store-name">{{ store.store_name || ('门店 ' + store.store_id) }}</view>
			<view v-else-if="isLogin" class="empty">{{ storeError || '当前账号尚未绑定归属门店，请先扫描门店获客码完成绑定。' }}</view>
			<view v-else class="empty">登录后读取扫码绑定的归属门店</view>
		</view>
		<view class="footer">
			<button class="ghost" @click="goMine">我的套餐</button>
			<button class="btn" :disabled="storeLoading" @click="goPurchase">{{ store.store_id ? '在归属门店购买' : '确认归属并购买' }}</button>
		</view>
	</view>
</template>

<script>
import { mapGetters } from 'vuex';
import { toLogin } from '@/libs/login.js';
import { getYfthPackageDetail, getYfthPackageMembershipMe } from '@/api/yfth.js';

export default {
	data() { return { id: 0, detail: {}, benefits: [], store: {}, storeLoading: false, storeError: '' }; },
	computed: { ...mapGetters(['isLogin']) },
	onLoad(options) {
		this.id = Number(options.id || 0);
		getYfthPackageDetail(this.id).then((res) => {
			this.detail = res.data || {};
			this.benefits = this.detail.benefits || [];
		}).catch((err) => this.$util.Tips({ title: (err && (err.msg || err.message)) || '套餐加载失败' }));
	},
	onShow() {
		if (this.isLogin) this.loadAuthoritativeStore(false).catch(() => {});
	},
	methods: {
		loadAuthoritativeStore(showError) {
			if (this.storeLoading) return Promise.resolve(this.store);
			this.storeLoading = true;
			this.storeError = '';
			return getYfthPackageMembershipMe().then((res) => {
				const profile = (res && res.data) || {};
				const attribution = profile.attribution || {};
				const promotion = profile.promotion || {};
				const storeId = Number(promotion.store_id || attribution.store_id || 0);
				if (attribution.status !== 'active' || storeId < 1) {
					throw new Error('当前账号尚未绑定归属门店，请先扫描门店获客码完成绑定');
				}
				this.store = { store_id: storeId, store_name: promotion.store_name || '' };
				return this.store;
			}).catch((err) => {
				this.store = {};
				this.storeError = (err && (err.msg || err.message)) || '归属门店读取失败';
				if (showError) this.$util.Tips({ title: this.storeError });
				throw err;
			}).finally(() => { this.storeLoading = false; });
		},
		goPurchase() {
			if (!this.isLogin) { toLogin(); return; }
			this.loadAuthoritativeStore(true).then((store) => {
				const name = encodeURIComponent(store.store_name || '');
				uni.navigateTo({ url: `/pages/yfth/package/agreement_confirm?id=${this.id}&store_id=${store.store_id}&store_name=${name}` });
			}).catch(() => {});
		},
		goMine() { uni.navigateTo({ url: '/pages/yfth/package/my_packages' }); }
	}
};
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f2ed; padding-bottom: 140rpx; color: #312a22; }
.hero { padding: 56rpx 32rpx 44rpx; background: #b88b4f; color: #fff; }
.title { font-size: 42rpx; font-weight: 700; }.price { margin-top: 24rpx; font-size: 56rpx; font-weight: 700; }
.meta { margin-top: 12rpx; font-size: 26rpx; opacity: .9; }.membership-badge { display: inline-block; margin-top: 18rpx; padding: 10rpx 18rpx; border: 1px solid rgba(255,255,255,.62); border-radius: 8rpx; font-size: 24rpx; }
.section { margin: 24rpx; padding: 24rpx; background: #fff; border-radius: 12rpx; }.section-title { margin-bottom: 18rpx; font-size: 32rpx; font-weight: 600; }
.summary,.notice,.empty { color: #756b60; font-size: 26rpx; line-height: 1.65; }.notice { margin-top: 18rpx; padding: 18rpx; border: 1px solid #ead6b5; border-radius: 8rpx; background: #fff8ea; }
.store-name { color: #79562f; font-size: 30rpx; font-weight: 600; }
.benefit { display: flex; justify-content: space-between; padding: 18rpx 0; border-top: 1px solid #edf0f2; font-size: 26rpx; }
.footer { position: fixed; left: 0; right: 0; bottom: 0; display: flex; gap: 16rpx; padding: 18rpx 24rpx calc(28rpx + env(safe-area-inset-bottom)); background: #fff; box-shadow: 0 -8rpx 24rpx rgba(60,45,30,.08); }
.btn,.ghost { flex: 1; height: 84rpx; line-height: 84rpx; border-radius: 10rpx; font-size: 28rpx; }.btn { color: #fff; background: #9a7342; }.ghost { color: #79562f; background: #f4ecdf; }
.btn[disabled] { opacity: .55; }
</style>
