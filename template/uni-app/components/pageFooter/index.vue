<template>
	<!-- 底部导航 -->
	<view v-if="businessMode">
		<view class="fixed-lb w-full pb-safe z-999 business-footer-fixed">
			<view class="business-footer">
				<view
					v-for="(item, index) in businessNavs"
					:key="index"
					class="business-footer-item"
					:class="{ active: isBusinessNavActive(item) }"
					@click="goBusinessRouter(item)"
				>
					{{ item.title }}
				</view>
			</view>
		</view>
		<view class="business-footer-space"></view>
		<view class="safe-area-inset-bottom"></view>
	</view>
	<view v-else-if="showTabBar">
		<view class="fixed-lb w-full pb-safe z-999" :class="{ 'centered-h5-footer': centeredH5 }" :style="[bgColor]">
			<view class="page-footer-wrapper">
				<view
					class="page-footer"
					:class="{
						'page-footer2': newData.navStyleConfig.tabVal == 1,
						'page-footer3': newData.navStyleConfig.tabVal == 2
					}"
					id="target"
					:style="[componentStyle]"
				>
					<view class="foot-item flex-1 flex-col flex-center h-96 relative" v-for="(item, index) in newData.menuList" :key="index" @click="goRouter(item)">
						<template v-if="item.link.split('?')[0] == activeRouter">
							<image v-if="newData.navStyleConfig.tabVal != 1" :src="item.imgList[0]"></image>
							<view v-if="newData.navStyleConfig.tabVal != 2" class="txt active" :style="[txtActiveColor]">{{ item.name }}</view>
						</template>
						<template v-else>
							<image v-if="newData.navStyleConfig.tabVal != 1" :src="item.imgList[1]"></image>
							<view v-if="newData.navStyleConfig.tabVal != 2" class="txt" :style="[txtColor]">{{ item.name }}</view>
						</template>
						<BaseBadge v-if="item.link === '/pages/order_addcart/order_addcart' && cartNum > 0" class="uni-badge-left-margin" :text="cartNum" absolute="rightTop"></BaseBadge>
					</view>
				</view>
			</view>
		</view>
		<view :style="{ height: `${footerHeight}px` }"></view>
		<view class="safe-area-inset-bottom"></view>
	</view>
</template>

<script>
import { mapState, mapGetters } from 'vuex';
import { getNavigation } from '@/api/public.js';
// import {getCartCounts} from '@/api/order.js';
import BaseBadge from '@/components/BaseBadge/index.vue';
import {
	currentContext,
	enterYfthBusinessMall,
	enterYfthBusinessUserCenter,
	isBusinessRole,
	isYfthBusinessMallBrowsing,
	isYfthBusinessUserCenterBrowsing,
	leaveYfthBusinessMall,
	leaveYfthBusinessUserCenter,
	roleNav
} from '@/libs/yfthContext.js';
export default {
	name: 'pageFooter',
	components: { BaseBadge },
	props: {
		businessPane: {
			type: String,
			default: ''
		},
		businessContext: {
			type: Object,
			default: () => ({})
		},
		centeredH5: {
			type: Boolean,
			default: false
		},
		isTabBar: {
			type: Boolean,
			default: true
		},
		configData: {
			type: Object,
			default: () => {}
		}
	},
	computed: {
		...mapGetters(['isLogin', 'cartNum']),
		txtActiveColor() {
			let styleObject = {};
			if (this.newData.toneConfig && this.newData.toneConfig.tabVal) {
				styleObject['color'] = this.newData.activeTxtColor.color[0].item;
			}
			return styleObject;
		},
		txtColor() {
			let styleObject = {};
			if (this.newData.toneConfig && this.newData.toneConfig.tabVal) {
				styleObject['color'] = this.newData.txtColor.color[0].item;
			}
			return styleObject;
		},
		bgColor() {
			let styleObject = {};
			if (!this.newData.name) {
				return styleObject;
			}
			if (!this.newData.navConfig.tabVal) {
				styleObject['background'] = this.newData.bgColor.color[0].item;
			}
			return styleObject;
		},
		componentStyle() {
			let styleObject = {};
			let borderRadius = ``;
			if (!this.newData.name) {
				return styleObject;
			}
			if (this.newData.navConfig.tabVal) {
				borderRadius = `${this.newData.fillet.val * 2}rpx`;
				if (this.newData.fillet.type) {
					borderRadius = `${this.newData.fillet.valList[0].val * 2}rpx ${this.newData.fillet.valList[1].val * 2}rpx ${this.newData.fillet.valList[3].val * 2}rpx ${
						this.newData.fillet.valList[2].val * 2
					}rpx`;
				}
				styleObject['right'] = `${this.newData.prConfig.val * 2}rpx`;
				styleObject['bottom'] = `${this.newData.mbConfig.val * 2}rpx`;
				styleObject['left'] = `${this.newData.prConfig.val * 2}rpx`;
				styleObject['padding-top'] = `${this.newData.topConfig.val * 2}rpx`;
				styleObject['padding-bottom'] = `${this.newData.bottomConfig.val * 2}rpx`;
				styleObject['border-radius'] = borderRadius;
				styleObject['background'] = this.newData.bgColor2.color[0].item;
			} else {
				styleObject['padding-top'] = `${this.newData.topConfig.val * 2}rpx`;
				styleObject['padding-bottom'] = `${this.newData.bottomConfig.val * 2}rpx`;
				styleObject['background'] = this.newData.bgColor.color[0].item;
			}
			return styleObject;
		}
	},
	watch: {
		businessPane() {
			this.refreshBusinessNavigation();
		},
		businessContext: {
			deep: true,
			handler() {
				this.refreshBusinessNavigation();
			}
		},
		configData(newVal) {
			if (!this.showTabBar && newVal) {
				let configData = newVal;
				this.newData = configData;
				this.showTabBar = configData.effectConfig.tabVal;
			}
		}
	},
	created() {
		this.setupBusinessNavigation();
		let routes = getCurrentPages(); //获取当前打开过的页面路由数组
		let curRoute = routes[routes.length - 1].route; //获取当前页面路由
		this.activeRouter = '/' + curRoute;
	},
	pageLifetimes: {
		show() {
			this.refreshBusinessNavigation();
		}
	},
	mounted() {
		if (this.businessMode) {
			uni.hideTabBar();
			this.$emit('newDataStatus', true, 3);
		} else {
			this.navigationInfo();
		}
		// if (this.isLogin) {
		// 	this.getCartNum()
		// }
	},
	data() {
		return {
			newData: {},
			activeRouter: '',
			showTabBar: false,
			footerHeight: 0,
			businessMode: false,
			businessNavs: [],
			businessActiveAction: '',
			businessActivePane: ''
		};
	},
	methods: {
		refreshBusinessNavigation() {
			this.businessMode = false;
			this.businessNavs = [];
			this.businessActiveAction = '';
			this.businessActivePane = '';
			this.setupBusinessNavigation();
			if (this.businessMode) {
				uni.hideTabBar();
				return;
			}
			this.navigationInfo();
		},
		setupBusinessNavigation() {
			const mallBrowsing = isYfthBusinessMallBrowsing();
			const userCenterBrowsing = isYfthBusinessUserCenterBrowsing();
			const explicitPane = String(this.businessPane || '').trim();
			if (!mallBrowsing && !userCenterBrowsing && !explicitPane) return;
			const suppliedContext = this.businessContext || {};
			const context = explicitPane && isBusinessRole(suppliedContext.role_code)
				? suppliedContext
				: currentContext();
			if (!context || !isBusinessRole(context.role_code)) {
				this.leaveBusinessSurface();
				return;
			}
			this.businessMode = true;
			this.businessNavs = roleNav(context.role_code);
			this.businessActiveAction = userCenterBrowsing ? 'user_center' : (mallBrowsing ? 'mall' : '');
			this.businessActivePane = explicitPane;
		},
		isBusinessNavActive(item) {
			if (!item) return false;
			return (item.action && item.action === this.businessActiveAction)
				|| (item.pane && item.pane === this.businessActivePane);
		},
		leaveBusinessSurface() {
			leaveYfthBusinessMall();
			leaveYfthBusinessUserCenter();
		},
		goBusinessRouter(item) {
			if (!item || this.isBusinessNavActive(item)) return;
			this.leaveBusinessSurface();
			if (item.action === 'mall') {
				enterYfthBusinessMall();
				uni.switchTab({ url: '/pages/index/index' });
				return;
			}
			if (item.action === 'user_center') {
				enterYfthBusinessUserCenter();
				uni.switchTab({ url: '/pages/user/index' });
				return;
			}
			if (item.pane) {
				uni.reLaunch({ url: `/pages/yfth/workbench/index?pane=${encodeURIComponent(item.pane)}` });
				return;
			}
			if (!item.url) return;
			const fn = item.type === 'switchTab' ? uni.switchTab : uni.navigateTo;
			fn({ url: item.url });
		},
		hasValidNavigation(data) {
			return !!(data && data.effectConfig && Array.isArray(data.menuList) && data.menuList.length && data.menuList.every((item) => item && item.name && item.link));
		},
		setNavigationInfo(data) {
			if (!this.hasValidNavigation(data)) {
				this.newData = {};
				this.showTabBar = false;
				this.$emit('newDataStatus', false, 0);
				uni.showTabBar();
				return;
			}
			if (this.isTabBar) {
				this.newData = data;
				this.showTabBar = data.effectConfig.tabVal;
				let pdHeight = data.topConfig.val + data.bottomConfig.val;
				this.$emit('newDataStatus', data.effectConfig.tabVal, pdHeight);
				if (data.effectConfig.tabVal) {
					uni.hideTabBar();
				} else {
					uni.showTabBar();
				}
			}
		},
		keepCurrentNavigation(fallbackData) {
			const currentData = this.hasValidNavigation(this.newData) ? this.newData : null;
			const cachedData = this.hasValidNavigation(fallbackData) ? fallbackData : null;
			if (currentData || cachedData) {
				this.setNavigationInfo(currentData || cachedData);
			}
		},
		getNavigationInfo(fallbackData) {
			return getNavigation()
				.then((res) => {
					uni.setStorageSync('footerNavigation', res.data);
					this.setNavigationInfo(res.data);
				})
				.catch(() => {
					if (this.hasValidNavigation(fallbackData)) this.setNavigationInfo(fallbackData);
					else this.setNavigationInfo(null);
				});
		},
		navigationInfo() {
			const footerNavigation = uni.getStorageSync('footerNavigation');
			this.getNavigationInfo(footerNavigation);
		},
		goRouter(item) {
			var pages = getCurrentPages();
			var page = pages[pages.length - 1].$page.fullPath;
			if (item.link == page) return;
			if (item.link == '/pages/short_video/appSwiper/index' || item.link == '/pages/short_video/nvueSwiper/index') {
				//#ifdef APP
				item.link = '/pages/short_video/appSwiper/index';
				//#endif
				//#ifndef APP
				item.link = '/pages/short_video/nvueSwiper/index';
				//#endif
			}
			uni.switchTab({
				url: item.link,
				fail(err) {
					uni.redirectTo({
						url: item.link
					});
				}
			});
		}
		// getCartNum: function() {
		// 	getCartCounts().then(res => {
		// 		this.$store.commit('indexData/setCartNum', res.data.count + '')
		// 	}).catch(err=>{
		// 		return this.$util.Tips({
		// 			title: err.msg
		// 		});
		// 	})
		// },
	}
};
</script>

<style scoped lang="scss">
.safe-area-inset-bottom {
	height: 0;
	height: constant(safe-area-inset-bottom);
	height: env(safe-area-inset-bottom);
}

.page-footer-wrapper {
	position: relative;
}

.business-footer-fixed {
	background: #fffaf4;
	border-top: 1rpx solid #eadfce;
}

.business-footer {
	display: flex;
	height: 106rpx;
}

.business-footer-item {
	min-width: 0;
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #786b73;
	font-size: 23rpx;
	white-space: nowrap;
}

.business-footer-item.active {
	color: #6f4c2f;
	font-weight: 700;
}

.business-footer-space {
	height: 106rpx;
}

/* #ifdef H5 */
@media screen and (min-width: 768px) {
	.business-footer-fixed {
		left: 50%;
		right: auto;
		width: 540px !important;
		max-width: 100%;
		transform: translateX(-50%);
	}

	.fixed-lb.centered-h5-footer {
		left: 50%;
		right: auto;
		width: 375px !important;
		max-width: 100%;
		transform: translateX(-50%);
	}

	.fixed-lb.centered-h5-footer .page-footer-wrapper {
		width: 100%;
	}
}
/* #endif */

.page-footer {
	position: absolute;
	right: 0;
	bottom: 0;
	left: 0;
	display: flex;

	.foot-item image {
		display: block;
		height: 48rpx;
		width: 48rpx;
		margin: 0 auto;
	}

	.foot-item .txt {
		margin-top: 4rpx;
		font-size: 20rpx;
		line-height: 28rpx;
		color: #333333;

		&.active {
			color: var(--view-theme);
		}
	}
}
.page-footer /deep/.uni-badge--x {
	position: absolute !important;
	top: 0rpx;
}
.page-footer .uni-badge-left-margin{
	position: absolute;
	/* #ifdef MP */
	margin-left: 40rpx;
	top: -10rpx;
	/* #endif */
}
.page-footer /deep/ .uni-badge-left-margin .uni-badge--error {
	color: #fff !important;
	background-color: var(--view-theme) !important;
	z-index: 8;
}
.page-footer /deep/ .uni-badge {
	right: unset !important;
	top: unset !important;
}
.page-footer2 .foot-item .txt {
	margin-top: 0;
	font-size: 32rpx;
	line-height: 44rpx;
	color: #333333;

	&.active {
		color: var(--view-theme);
	}
}

.page-footer2.float .foot-item::before,
.page-footer3.float .foot-item::before {
	content: '';
	position: absolute;
	top: 50%;
	left: 0;
	width: 2rpx;
	height: 32rpx;
	background: #cccccc;
	transform: translateY(-50%);
}

.page-footer2.float .foot-item:first-child::before,
.page-footer3.float .foot-item:first-child::before {
	display: none;
}
</style>
