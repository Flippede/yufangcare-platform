<template>
	<view class="page">
		<view class="hero">
			<view class="title">{{ detail.package_title || detail.package_name }}</view>
			<view class="price">¥{{ price }}</view>
			<view class="meta">{{ monthCount }}个月权益计划</view>
			<view v-if="grantsMembership" class="membership-badge">激活后获得永久会员资格</view>
		</view>
		<view class="section">
			<view class="section-title">套餐内容</view>
			<view class="summary">{{ detail.service_summary || '御方通和家庭康养套餐' }}</view>
			<view v-if="isSimulationPackage" class="simulation-notice">本套餐仅用于0.1元模拟购买验收，不会发起真实支付或扣款。</view>
			<view v-for="item in benefits" :key="item.id" class="benefit">
				<text>第{{ item.month_no }}月</text>
				<text>{{ item.benefit_name }}</text>
				<text>x{{ item.quantity }}</text>
			</view>
		</view>
		<view v-if="isSimulationPackage" class="section merchant-section">
			<view class="section-title">上级商家</view>
			<view v-if="simulationLoading" class="merchant-state">正在读取当前归属...</view>
			<template v-else-if="simulationContext.store_bound">
				<view class="merchant-name">{{ simulationContext.store.store_name }}</view>
				<view class="merchant-state">模拟购买将直接归属该商家，无需再次选择门店。</view>
			</template>
			<view v-else class="merchant-state">{{ simulationContext.unavailable_reason || '登录后可查看已绑定的上级商家' }}</view>
		</view>
		<view class="footer">
			<button class="btn" :disabled="simulationLoading" @click="goStores">{{ primaryLabel }}</button>
			<button class="ghost" @click="goMine">我的套餐</button>
		</view>
	</view>
</template>

<script>
import { mapGetters } from 'vuex';
import { toLogin } from '@/libs/login.js';
import { getYfthPackageDetail, getYfthPackageSimulationContext } from '@/api/yfth.js';

export default {
	data() {
		return {
			id: 0,
			detail: {},
			benefits: [],
			simulationContext: {},
			simulationLoading: false
		};
	},
	computed: {
		...mapGetters(['isLogin']),
		price() {
			return (this.detail.rule && this.detail.rule.package_price) || this.detail.base_price || '0.00';
		},
		monthCount() {
			return (this.detail.rule && this.detail.rule.month_count) || this.detail.benefit_months || 0;
		},
		grantsMembership() {
			return Boolean(this.detail.rule && this.detail.rule.grants_permanent_membership);
		},
		isSimulationPackage() {
			return this.detail.package_code === 'YFTH-TEST-PACKAGE-V1';
		},
		primaryLabel() {
			if (!this.isSimulationPackage) return '选择门店并购买';
			if (!this.isLogin) return '登录后模拟购买';
			if (this.simulationContext.is_member) return '已是永久会员';
			return '确认上级商家并模拟购买';
		}
	},
	onLoad(options) {
		this.id = Number(options.id || 0);
		this.load();
	},
	methods: {
		load() {
			getYfthPackageDetail(this.id).then((res) => {
				this.detail = res.data || {};
				this.benefits = this.detail.benefits || [];
				if (this.isSimulationPackage && this.isLogin) this.loadSimulationContext();
			});
		},
		loadSimulationContext() {
			if (this.simulationLoading) return Promise.resolve(this.simulationContext);
			this.simulationLoading = true;
			return getYfthPackageSimulationContext(this.id)
				.then((res) => {
					this.simulationContext = res.data || {};
					return this.simulationContext;
				})
				.catch((err) => {
					this.simulationContext = { unavailable_reason: (err && (err.msg || err.message)) || String(err || '暂时无法读取上级商家') };
					return this.simulationContext;
				})
				.finally(() => { this.simulationLoading = false; });
		},
		goStores() {
			if (this.isSimulationPackage) {
				if (!this.isLogin) { toLogin(); return; }
				this.loadSimulationContext().then((context) => {
					if (context.is_member) {
						this.$util.Tips({ title: '该账号已是永久会员，无需重复购买' });
						return;
					}
					if (!context.can_simulate || !context.store_bound) {
						this.$util.Tips({ title: context.unavailable_reason || '请先绑定上级商家' });
						return;
					}
					uni.navigateTo({
						url: '/pages/yfth/package/agreement_confirm?id=' + this.id
							+ '&store_id=' + context.store.store_id + '&simulation=1'
					});
				});
				return;
			}
			uni.navigateTo({ url: '/pages/yfth/package/store_select?id=' + this.id });
		},
		goMine() {
			uni.navigateTo({ url: '/pages/yfth/package/my_packages' });
		}
	}
};
</script>

<style scoped>
.page {
	min-height: 100vh;
	background: #f5f7f8;
	padding-bottom: 140rpx;
}
.hero {
	padding: 56rpx 32rpx 44rpx;
	background: linear-gradient(135deg, #204b45, #2f7668);
	color: #fff;
}
.title {
	font-size: 42rpx;
	font-weight: 700;
}
.price {
	margin-top: 24rpx;
	font-size: 56rpx;
	font-weight: 700;
}
.meta {
	margin-top: 12rpx;
	font-size: 26rpx;
	opacity: 0.86;
}
.membership-badge {
	display: inline-block;
	margin-top: 18rpx;
	padding: 10rpx 18rpx;
	border: 1px solid rgba(255, 255, 255, 0.56);
	border-radius: 8rpx;
	font-size: 24rpx;
}
.section {
	margin: 24rpx;
	padding: 24rpx;
	background: #fff;
	border-radius: 12rpx;
}
.section-title {
	font-size: 32rpx;
	font-weight: 600;
	margin-bottom: 18rpx;
}
.summary {
	color: #5c6670;
	line-height: 1.6;
	margin-bottom: 18rpx;
}
.simulation-notice {
	margin-top: 18rpx;
	padding: 18rpx 20rpx;
	border: 1px solid #ead6b5;
	border-radius: 10rpx;
	color: #815b2d;
	background: #fff8ea;
	font-size: 25rpx;
	line-height: 1.6;
}
.merchant-section {
	border: 1px solid #ead6b5;
}
.merchant-name {
	color: #6f4b23;
	font-size: 32rpx;
	font-weight: 700;
}
.merchant-state {
	margin-top: 10rpx;
	color: #756b60;
	font-size: 26rpx;
	line-height: 1.6;
}
.benefit {
	display: flex;
	justify-content: space-between;
	padding: 18rpx 0;
	border-top: 1px solid #edf0f2;
	font-size: 26rpx;
}
.footer {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	gap: 16rpx;
	padding: 18rpx 24rpx 28rpx;
	background: #fff;
	box-shadow: 0 -8rpx 24rpx rgba(20, 35, 50, 0.08);
}
.btn,
.ghost {
	flex: 1;
	height: 84rpx;
	line-height: 84rpx;
	border-radius: 10rpx;
	font-size: 28rpx;
}
.btn {
	color: #fff;
	background: #2f7668;
}
.btn[disabled] {
	color: #fff;
	background: #b7a58d;
}
.ghost {
	color: #2f7668;
	background: #eef7f5;
}
</style>
