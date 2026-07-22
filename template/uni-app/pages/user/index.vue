<template>
	<view class="new-users copy-data" :style="{ height: pageHeight }">
		<view class="top" :style="colorStyle">
			<!-- #ifdef MP || APP-PLUS -->
			<view class="sys-head">
				<view class="sys-bar" :style="{ height: sysHeight }"></view>
				<!-- #ifdef MP -->
				<view class="sys-title" :style="member_style == 3 ? 'color:#333' : ''">{{ $t('个人中心') }}</view>
				<!-- #endif -->
				<view class="bg" :style="member_style == 3 ? 'background:#f5f5f5' : ''"></view>
			</view>
			<!-- #endif -->
		</view>
		<view class="mid" style="flex: 1; overflow: hidden" :style="colorStyle">
			<scroll-view scroll-y="true" style="height: 100%">
				<view class="head">
					<view class="customer-profile">
						<view class="profile-actions">
							<view class="profile-action" aria-label="扫一扫" @click="goYfthReferralScan">
								<view class="iconfont icon-saoma"></view>
							</view>
							<navigator v-if="isLogin" url="/pages/users/user_info/index" hover-class="none">
								<view class="iconfont icon-shezhi"></view>
							</navigator>
							<navigator v-if="isLogin" url="/pages/users/message_center/index" hover-class="none">
								<view v-if="userInfo.service_num" class="message-count">
									{{ userInfo.service_num >= 100 ? '99+' : userInfo.service_num }}
								</view>
								<view class="iconfont icon-s-kefu"></view>
							</navigator>
						</view>
						<view class="user-info">
							<view>
								<view class="avatar-box">
									<image class="avatar" :src="userInfo.avatar" v-if="userInfo.avatar" @click="goEdit()"></image>
									<image v-else class="avatar" src="/static/images/f.png" mode="" @click="goEdit()"></image>
								</view>
							</view>
							<view class="info">
								<!-- #ifdef MP || APP-PLUS -->
								<view class="name" v-if="!userInfo.uid" @click="openAuto" style="height: 100%; display: flex; align-items: center">
									{{ $t('请点击授权') }}
								</view>
								<!-- #endif -->
								<!-- #ifdef H5 -->
								<view class="name" v-if="!userInfo.uid" @click="openAuto" style="height: 100%; display: flex; align-items: center">
									{{ $t(isWeixin ? '请点击授权' : '请点击登录') }}
								</view>
								<!-- #endif -->
								<view class="name" v-if="userInfo.uid">
									<text class="line1 nickname">{{ userInfo.nickname }}</text>
								</view>
								<view class="num" v-if="userInfo.phone" @click="goEdit()">
									<view class="num-txt">{{ userInfo.phone }}</view>
								</view>
								<!-- #ifdef MP -->
								<button class="phone" v-if="!userInfo.phone && isLogin" open-type="getPhoneNumber" @getphonenumber="getphonenumber">{{ $t(`绑定手机号`) }}</button>
								<!-- #endif -->
								<!-- #ifndef MP -->
								<view class="phone" v-if="!userInfo.phone && isLogin" @tap="bindPhone">
									{{ $t('绑定手机号') }}
								</view>
								<!-- #endif -->
							</view>
						</view>
						<view class="membership-summary" @click="goYfthPackageMembership">
							<view class="membership-copy">
								<view class="membership-eyebrow">御方通和会员</view>
								<view class="membership-label">{{ yfthMembershipLabel }}</view>
								<view class="membership-desc">{{ yfthMembershipDescription }}</view>
							</view>
							<view class="membership-link">查看 <text class="iconfont icon-jiantou"></text></view>
						</view>
						<view v-if="isLogin" class="identity-summary">
							<view>
								<text class="identity-eyebrow">当前身份</text>
								<text class="identity-name">{{ yfthCurrentIdentityText }}</text>
							</view>
							<view v-if="yfthCurrentContext.is_business_role" class="identity-actions" @click.stop="goYfthCurrentWorkbench">进入{{ yfthCurrentIdentityText }}工作台</view>
						</view>
					</view>
					<view class="mall-assets" v-if="isLogin">
						<view class="asset-item" @click="goYfthCommissionAccount">
							<text class="asset-value">{{ yfthUnifiedBalance }}</text>
							<text class="asset-label">账户余额</text>
						</view>
						<view class="asset-item" @click="goMenuPage('/pages/users/user_integral/index')">
							<text class="asset-value">{{ userInfo.integral || 0 }}</text>
							<text class="asset-label">商城积分</text>
						</view>
						<view class="asset-item" @click="goMenuPage('/pages/users/user_coupon/index')">
							<text class="asset-value">{{ userInfo.couponCount || 0 }}</text>
							<text class="asset-label">优惠券</text>
						</view>
						<view class="asset-note">商城资产与御方通和推荐佣金分别记录，互不混用</view>
					</view>
					<view class="member-exclusive" v-if="isLogin">
						<view class="section-title">会员专属</view>
						<view v-if="isYfthPermanentMember || isYfthStoreOperator" class="exclusive-grid">
							<view @click="goYfthReferralCode"><text class="exclusive-icon">码</text><text>我的推广码</text></view>
							<view @click="goYfthAttribution"><text class="exclusive-icon">归</text><text>我的归属</text></view>
							<view @click="goYfthPackageMembership"><text class="exclusive-icon">会</text><text>套餐会员</text></view>
							<view @click="goYfthRewards"><text class="exclusive-icon">奖</text><text>我的奖励</text></view>
						</view>
						<view v-else class="membership-prompt" @click="goYfthPackagePurchase">
							<view><text class="prompt-title">购买套餐后获得推广资格</text><text class="prompt-copy">激活永久会员，使用一级邀请与奖励查询</text></view>
							<text class="prompt-arrow">›</text>
						</view>
					</view>
					<view class="order-wrapper">
						<view class="order-hd flex">
							<view class="left">{{ $t('订单中心') }}</view>
							<view class="right flex" @click="goMenuPage('/pages/goods/order_list/index')" >
								{{ $t('查看全部') }}
								<text class="iconfont icon-jiantou"></text>
							</view>
						</view>
						<view class="order-bd">
							<block v-for="(item, index) in orderMenu" :key="index">
								<view class="order-item" @click="goMenuPage(item.url)">
									<view class="pic">
										<!-- <image :src="item.img" mode=""></image> -->
										<text class="iconfont" :class="item.img"></text>
										<text class="order-status-num" v-if="item.num > 0">{{ item.num }}</text>
									</view>
									<view class="txt">{{ $t(item.title) }}</view>
								</view>
							</block>
						</view>
					</view>
				</view>
				<!-- 轮播 -->
				<view class="slider-wrapper" v-if="imgUrls.length > 0 && my_banner_status">
					<swiper
						indicator-dots="true"
						:autoplay="autoplay"
						:circular="circular"
						:interval="interval"
						:duration="duration"
						indicator-color="rgba(255,255,255,0.6)"
						indicator-active-color="#fff"
					>
						<block v-for="(item, index) in imgUrls" :key="index">
							<swiper-item>
								<view @click="goPages(item.url)" class="slide-navigator acea-row row-between-wrapper" hover-class="none">
									<image :src="item.pic" class="slide-image"></image>
								</view>
							</swiper-item>
						</block>
					</swiper>
				</view>
				<!-- 我的服务：YFTH 固定业务入口与后台配置菜单统一展示 -->
				<view class="user-menus customer-services" v-if="isLogin || (my_menus_status && MyMenus.length)">
					<view class="menu-title">{{ $t('我的服务') }}</view>
					<view class="list-box">
						<view class="item yfth-service-item" v-if="isLogin && hasYfthBusinessIdentity" @click="goYfthWorkbench">
							<view class="service-icon service-icon-work">营</view>
							<text class="name">经营工作台</text>
						</view>
						<view class="item yfth-service-item" v-if="isLogin" @click="goYfthFranchiseApplications">
							<view class="service-icon service-icon-cooperate">合</view>
							<text class="name">御方通和合作中心</text>
						</view>
						<!-- #ifdef APP-PLUS || H5 -->
						<block v-for="(item, index) in MyMenus" :key="index">
							<view class="item" v-if="item.url != '#' && item.url != '/pages/service/index'" @click="goMenuPage(item.url, item.name)">
								<view class="configured-service-icon">
									<text>{{ serviceMenuInitial(item.name) }}</text>
									<image v-if="item.pic" :src="item.pic"></image>
								</view>
								<text class="name">{{ $t(item.name) }}</text>
							</view>
						</block>
						<!-- #endif -->
						<!-- #ifdef MP -->
						<block v-for="(item, index) in MyMenus" :key="index">
							<view
								class="item"
								v-if="
									(item.url != '#' && item.url != '/pages/service/index' && item.url != '/pages/extension/customer_list/chat') ||
									(item.url == '/pages/extension/customer_list/chat' && routineContact == 0)
								"
								@click="goMenuPage(item.url, item.name)"
							>
								<view class="configured-service-icon">
									<text>{{ serviceMenuInitial(item.name) }}</text>
									<image v-if="item.pic" :src="item.pic"></image>
								</view>
								<text class="name">{{ $t(item.name) }}</text>
							</view>
						</block>

						<button class="item" open-type="contact" v-if="routineContact == 1">
							<image src="/static/images/contact.png"></image>
							<text class="name">{{ $t('联系客服') }}</text>
						</button>
						<!-- #endif -->
						<!-- #ifdef APP-PLUS -->
						<view class="item" hover-class="none" @click="goMenuPage('/pages/users/privacy/index?type=3')">
							<image src="/static/images/menu.png"></image>
							<text class="name">{{ $t('隐私协议') }}</text>
						</view>
						<!-- #endif -->
					</view>
				</view>
				<view class="user-menus" style="margin-top: 20rpx" v-if="business_status && storeMenu.length">
					<view class="menu-title" v-if="business_status == 1">{{ $t('商家管理') }}</view>
					<view :class="{ 'list-box': business_status == 1, 'column-box': business_status == 2 }">
						<block v-for="(item, index) in storeMenu" :key="index">
							<view class="item" :url="item.url" hover-class="none" v-if="item.url != '#' && item.url != '/pages/service/index'" @click="goMenuPage(item.url, item.name)">
								<image :src="item.pic"></image>
								<text class="name">{{ $t(item.name) }}</text>
								<text class="iconfont icon-jiantou" v-if="business_status == 2"></text>
							</view>
						</block>
					</view>
				</view>
				<view class="uni-p-b-98"></view>
			</scroll-view>
			<editUserModal :isShow="editModal" @closeEdit="closeEdit" @editSuccess="editSuccess"></editUserModal>
		</view>
		<pageFooter :style="colorStyle" :centered-h5="true"></pageFooter>
	</view>
</template>
<script>
let sysHeight = uni.getSystemInfoSync().statusBarHeight + 'px';
import { getMenuList, getUserInfo, setVisit, mpBindingPhone } from '@/api/user.js';
import { wechatAuthV2, silenceAuth } from '@/api/public.js';
import { toLogin } from '@/libs/login.js';
import { mapState, mapGetters } from 'vuex';
// #ifdef H5
import Auth from '@/libs/wechat';
// #endif
const app = getApp();
import dayjs from '@/plugin/dayjs/dayjs.min.js';
import Routine from '@/libs/routine';
import colors from '@/mixins/color';
import pageFooter from '@/components/pageFooter/index.vue';
import { getCustomer } from '@/utils/index.js';
import editUserModal from '@/components/eidtUserModal/index.vue';
import { currentContext, dominantYfthIdentities, isBusinessRole, isYfthBusinessUserCenterBrowsing, leaveYfthBusinessUserCenter, loadYfthIdentities, resolveDominantYfthContext, roleLabel } from '@/libs/yfthContext.js';
import { getYfthPackageMembershipMe, getYfthCommissionSummary } from '@/api/yfth.js';
export default {
	components: {
		pageFooter,
		editUserModal
	},
	// computed: mapGetters(['isLogin','cartNum']),
	computed: {
		...mapGetters({
			cartNum: 'cartNum',
			isLogin: 'isLogin'
		}),
		yfthMembershipLabel() {
			if (!this.isLogin) return '登录后查看';
			if (this.yfthMembershipState === 'loading') return '正在读取';
			if (this.yfthMembershipState === 'error') return '状态暂不可用';
			return this.yfthMembershipProfile.membership && this.yfthMembershipProfile.membership.is_member ? '永久会员' : '未开通会员';
		},
		yfthMembershipDescription() {
			if (!this.isLogin) return '登录后查看会员资格与康养权益';
			if (this.yfthMembershipState === 'loading') return '正在同步真实会员资格';
			if (this.yfthMembershipState === 'error') return '点击进入会员中心重新加载';
			return this.yfthMembershipProfile.membership && this.yfthMembershipProfile.membership.is_member
				? '永久有效，查看邀请与奖励记录'
				: '购买并激活康养套餐后获得资格';
		},
		yfthCurrentIdentityText() {
			const context = this.yfthCurrentContext || {};
			const role = roleLabel(context.role_code || 'customer');
			return context.store_name ? `${role} · ${context.store_name}` : role;
		},
		isYfthPermanentMember() {
			return Boolean(this.yfthMembershipProfile.membership && this.yfthMembershipProfile.membership.is_member);
		},
		isYfthStoreOperator() {
			return ['store_manager', 'store_staff'].includes(String((this.yfthCurrentContext || {}).role_code || ''));
		},
		yfthUnifiedBalance() {
			return (Number(this.userInfo.now_money || 0) + Number(this.yfthCommissionProfile.account && this.yfthCommissionProfile.account.available || 0)).toFixed(2);
		}
	},
	filters: {
		coundTime(val) {
			var setTime = val * 1000;
			var nowTime = new Date();
			var rest = setTime - nowTime.getTime();
			var day = parseInt(rest / (60 * 60 * 24 * 1000));
			// var hour = parseInt(rest/(60*60*1000)%24) //小时
			return day + this.$t('day');
		},
		dateFormat: function (value) {
			return dayjs(value * 1000).format('YYYY-MM-DD');
		}
	},
	mixins: [colors],
	data() {
		return {
			editModal: false, // 编辑头像信息
			storeMenu: [], // 商家管理
			orderMenu: [
				{
					img: 'icon-daifukuan',
					title: '待付款',
					url: '/pages/goods/order_list/index?status=0'
				},
				{
					img: 'icon-daifahuo',
					title: '待发货',
					url: '/pages/goods/order_list/index?status=1'
				},
				{
					img: 'icon-daishouhuo',
					title: '待收货',
					url: '/pages/goods/order_list/index?status=2'
				},
				{
					img: 'icon-daipingjia',
					title: '待评价',
					url: '/pages/goods/order_list/index?status=3'
				},
				{
					img: 'icon-a-shouhoutuikuan',
					title: '售后/退款',
					url: '/pages/users/user_return_list/index'
				}
			],
			imgUrls: [],
			autoplay: true,
			circular: true,
			interval: 3000,
			duration: 500,
			isAuto: false, //没有授权的不会自动授权
			isShowAuth: false, //是否隐藏授权
			orderStatusNum: {},
			userInfo: {},
			MyMenus: [],
			sysHeight: sysHeight,
			mpHeight: 0,
			showStatus: 1,
			activeRouter: '',
			// #ifdef H5 || MP
			pageHeight: '100%',
			routineContact: 0,
			// #endif
			// #ifdef APP-PLUS
			pageHeight: app.globalData.windowHeight,
			// #endif
			// #ifdef H5
			isWeixin: Auth.isWeixin(),
			//#endif
			footerSee: false,
			my_menus_status: 0,
			business_status: 0,
			member_style: 0,
			hasYfthBusinessIdentity: false,
			yfthIdentities: [],
			yfthCurrentContext: {},
			yfthBusinessIdentityRequestSeq: 0,
			yfthMembershipProfile: {},
			yfthMembershipState: 'idle',
			yfthMembershipRequestSeq: 0,
			yfthCommissionProfile: {},
			my_banner_status: 0,
			is_diy: uni.getStorageSync('is_diy')
		};
	},
	onLoad(option) {
		uni.hideTabBar();
		let that = this;
		// #ifdef MP
		// 小程序静默授权
		if (!this.$store.getters.isLogin) {
			// Routine.getCode()
			// 	.then(code => {
			// 		Routine.silenceAuth(code).then(res => {
			// 			this.onLoadFun();
			// 		})
			// 	})
			// 	.catch(res => {
			// 		uni.hideLoading();
			// 	});
		}
		// #endif

		// #ifdef H5 || APP-PLUS
		// if (that.isLogin == false) {
		// 	toLogin();
		// }
		//获取用户信息回来后授权
		let cacheCode = this.$Cache.get('snsapi_userinfo_code');
		let res1 = cacheCode ? option.code != cacheCode : true;
		if (this.isWeixin && option.code && res1 && option.scope === 'snsapi_userinfo') {
			this.$Cache.set('snsapi_userinfo_code', option.code);
			Auth.auth(option.code)
				.then((res) => {
					this.getUserInfo().then(() => {
						this.loadYfthBusinessEntry();
					});
				})
				.catch((err) => {});
		}
		// #endif
		// #ifdef APP-PLUS
		that.$set(that, 'pageHeight', app.globalData.windowHeight);
		// #endif

		let routes = getCurrentPages(); // 获取当前打开过的页面路由数组
		let curRoute = routes[routes.length - 1].route; //获取当前页面路由
		this.activeRouter = '/' + curRoute;
	},
	onReady() {
		let self = this;
		// #ifdef MP
		let info = uni.createSelectorQuery().select('.sys-head');
		info
			.boundingClientRect(function (data) {
				//data - 各种参数
				self.mpHeight = data.height;
			})
			.exec();
		// #endif
	},
	onShow: function () {
		let that = this;
		// #ifdef APP-PLUS
		uni.getSystemInfo({
			success: function (res) {
				that.pageHeight = res.windowHeight + 'px';
			}
		});
		// #endif
		if (that.isLogin) {
			this.getUserInfo().then(() => {
				this.loadYfthBusinessEntry();
				this.loadYfthMembership();
				this.loadYfthCommission();
			}).catch(() => {
				this.resetYfthBusinessEntry();
				this.resetYfthMembership();
			});
			this.setVisit();
		} else {
			this.resetYfthBusinessEntry();
			this.resetYfthMembership();
		}
		this.getMyMenus();
	},
	onPullDownRefresh() {
		this.onLoadFun();
	},
	onHide() {
		leaveYfthBusinessUserCenter();
	},
	methods: {
		getWechatuserinfo() {
			//#ifdef H5
			Auth.isWeixin() && Auth.toAuth('snsapi_userinfo', '/pages/user/index');
			//#endif
		},
		editSuccess() {
			this.editModal = false;
			this.getUserInfo();
		},
		closeEdit() {
			this.editModal = false;
		},
		// 记录会员访问
		setVisit() {
			setVisit({
				url: '/pages/user/index'
			}).then((res) => {}).catch(() => {});
		},
		// 打开授权
		openAuto() {
			toLogin();
		},
		// 授权回调
		onLoadFun() {
			this.resetYfthBusinessEntry();
			this.resetYfthMembership();
			this.getUserInfo().then(() => {
				this.loadYfthBusinessEntry();
				this.loadYfthMembership();
				this.loadYfthCommission();
			}).catch(() => {
				this.resetYfthBusinessEntry();
				this.resetYfthMembership();
			});
			this.getMyMenus();
			this.setVisit();
		},
		resetYfthBusinessEntry() {
			this.hasYfthBusinessIdentity = false;
			this.yfthIdentities = [];
			this.yfthCurrentContext = {};
			this.yfthBusinessIdentityRequestSeq += 1;
		},
		loadYfthBusinessEntry() {
			const keepUserCenter = isYfthBusinessUserCenterBrowsing();
			const requestSeq = this.yfthBusinessIdentityRequestSeq + 1;
			this.yfthBusinessIdentityRequestSeq = requestSeq;
			this.hasYfthBusinessIdentity = false;
			if (!this.isLogin) {
				return Promise.resolve(false);
			}
			const requestUid = Number((this.userInfo && this.userInfo.uid) || this.$store.state.app.uid || 0);
			if (!requestUid) {
				return Promise.resolve(false);
			}
			return loadYfthIdentities().then((list) => {
				const currentUid = Number((this.userInfo && this.userInfo.uid) || this.$store.state.app.uid || 0);
				if (requestSeq !== this.yfthBusinessIdentityRequestSeq || Number(currentUid) !== Number(requestUid)) {
					return false;
				}
				this.yfthIdentities = list;
				const dominantRows = dominantYfthIdentities(list);
				this.hasYfthBusinessIdentity = dominantRows.some((item) => isBusinessRole(item.role_code));
				if (!this.hasYfthBusinessIdentity) {
					this.yfthCurrentContext = currentContext();
					return false;
				}
				return resolveDominantYfthContext(list).then((context) => {
					this.yfthCurrentContext = context;
					if (!keepUserCenter) uni.reLaunch({ url: '/pages/yfth/workbench/index' });
					return true;
				});
			}).catch(() => {
				if (requestSeq === this.yfthBusinessIdentityRequestSeq) {
					const cached = currentContext();
					this.hasYfthBusinessIdentity = Boolean(cached.is_business_role);
					if (cached.is_business_role && !keepUserCenter) uni.reLaunch({ url: '/pages/yfth/workbench/index' });
				}
				return false;
			});
		},
		resetYfthMembership() {
			this.yfthMembershipRequestSeq += 1;
			this.yfthMembershipProfile = {};
			this.yfthMembershipState = 'idle';
		},
		loadYfthMembership() {
			const requestSeq = this.yfthMembershipRequestSeq + 1;
			this.yfthMembershipRequestSeq = requestSeq;
			if (!this.isLogin) {
				this.resetYfthMembership();
				return Promise.resolve(false);
			}
			this.yfthMembershipState = 'loading';
			return getYfthPackageMembershipMe().then((res) => {
				if (requestSeq !== this.yfthMembershipRequestSeq) return false;
				this.yfthMembershipProfile = res.data || {};
				this.yfthMembershipState = 'ready';
				return true;
			}).catch(() => {
				if (requestSeq === this.yfthMembershipRequestSeq) {
					this.yfthMembershipProfile = {};
					this.yfthMembershipState = 'error';
				}
				return false;
			});
		},
		loadYfthCommission() {
			if (!this.isLogin) { this.yfthCommissionProfile = {}; return Promise.resolve(false); }
			return getYfthCommissionSummary().then((res) => {
				this.yfthCommissionProfile = res.data || {};
				return true;
			}).catch(() => { this.yfthCommissionProfile = {}; return false; });
		},
		goYfthCommissionAccount() {
			if (!this.isLogin) { toLogin(); return; }
			uni.navigateTo({ url: '/pages/yfth/commission/account' });
		},
		serviceMenuInitial(name) {
			const text = String(name || '服务').trim();
			return text ? text.slice(0, 1) : '服';
		},
		Setting: function () {
			uni.openSetting({
				success: function (res) {}
			});
		},
		// 授权关闭
		authColse: function (e) {
			this.isShowAuth = e;
		},
		// 绑定手机
		bindPhone() {
			uni.navigateTo({
				url: '/pages/users/user_phone/index'
			});
		},
		getphonenumber(e) {
			if (e.detail.errMsg == 'getPhoneNumber:ok') {
				Routine.getCode()
					.then((code) => {
						let data = {
							code,
							iv: e.detail.iv,
							encryptedData: e.detail.encryptedData
						};
						mpBindingPhone(data)
							.then((res) => {
								this.getUserInfo();
								this.$util.Tips({
									title: res.msg,
									icon: 'success'
								});
							})
							.catch((err) => {
								return this.$util.Tips({
									title: err
								});
							});
					})
					.catch((error) => {
						uni.hideLoading();
					});
			}
		},
		/**
		 * 获取个人用户信息
		 */
		getUserInfo: function () {
			let that = this;
			return getUserInfo().then((res) => {
				that.userInfo = res.data;
				that.$store.commit('SETUID', res.data.uid);
				that.orderMenu.forEach((item, index) => {
					switch (item.title) {
						case '待付款':
							item.num = res.data.orderStatusNum.unpaid_count;
							break;
						case '待发货':
							item.num = res.data.orderStatusNum.unshipped_count;
							break;
						case '待收货':
							item.num = res.data.orderStatusNum.received_count;
							break;
						case '待评价':
							item.num = res.data.orderStatusNum.evaluated_count;
							break;
						case '售后/退款':
							item.num = res.data.orderStatusNum.refunding_count;
							break;
					}
				});
				uni.stopPullDownRefresh();
				return res.data;
			}).catch((err) => {
				uni.stopPullDownRefresh();
				throw err;
			});
		},
		//小程序授权api替换 getUserInfo
		getUserProfile() {
			toLogin();
		},
		/**
		 *
		 * 获取个人中心图标
		 */
		switchTab(order) {
			this.orderMenu.forEach((item, index) => {
				switch (item.title) {
					case '待付款':
						item.img = order.dfk;
						break;
					case '待发货':
						item.img = order.dfh;
						break;
					case '待收货':
						item.img = order.dsh;
						break;
					case '待评价':
						item.img = order.dpj;
						break;
					case '售后/退款':
						item.img = order.sh;
						break;
				}
			});
		},
		getMyMenus: function () {
			let that = this;
			// if (this.MyMenus.length) return;
			getMenuList().then((res) => {
				this.member_style = Number(res.data.diy_data.value);
				this.my_banner_status = res.data.diy_data.my_banner_status;
				this.my_menus_status = res.data.diy_data.my_menus_status;
				this.business_status = res.data.diy_data.business_status;
				let storeMenu = [];
				let myMenu = [];
				res.data.routine_my_menus.forEach((el, index, arr) => {
					if (el.url == '/pages/admin/order/index' || el.url == '/pages/admin/order_cancellation/index' || el.name == '客服接待') {
						storeMenu.push(el);
					} else {
						myMenu.push(el);
					}
				});

				let order01 = {
					dfk: 'icon-daifukuan',
					dfh: 'icon-daifahuo',
					dsh: 'icon-daishouhuo',
					dpj: 'icon-daipingjia',
					sh: 'icon-a-shouhoutuikuan'
				};
				let order02 = {
					dfk: 'icon-daifukuan-lan',
					dfh: 'icon-daifahuo-lan',
					dsh: 'icon-daishouhuo-lan',
					dpj: 'icon-daipingjia-lan',
					sh: 'icon-shouhou-tuikuan-lan'
				};
				let order03 = {
					dfk: 'icon-daifukuan-ju',
					dfh: 'icon-daifahuo-ju',
					dsh: 'icon-daishouhuo-ju',
					dpj: 'icon-daipingjia-ju',
					sh: 'icon-shouhou-tuikuan-ju'
				};
				let order04 = {
					dfk: 'icon-daifukuan-fen',
					dfh: 'icon-daifahuo-fen',
					dsh: 'icon-daishouhuo-fen',
					dpj: 'icon-daipingjia-fen',
					sh: 'icon-a-shouhoutuikuan-fen'
				};
				let order05 = {
					dfk: 'icon-daifukuan-lv',
					dfh: 'icon-daifahuo-lv',
					dsh: 'icon-daishouhuo-lv',
					dpj: 'icon-daipingjia-lv',
					sh: 'icon-shouhou-tuikuan-lv'
				};
				switch (res.data.diy_data.order_status) {
					case 1:
						this.switchTab(order01);
						break;
					case 2:
						this.switchTab(order02);
						break;
					case 3:
						this.switchTab(order03);
						break;
					case 4:
						this.switchTab(order04);
						break;
					case 5:
						this.switchTab(order05);
						break;
				}
				that.$set(that, 'MyMenus', myMenu);
				that.$set(that, 'storeMenu', storeMenu);
				this.imgUrls = res.data.routine_my_banner;
				this.routineContact = Number(res.data.routine_contact_type);
			});
		},
		// 编辑页面
		goEdit() {
			if (this.isLogin == false) {
				toLogin();
			} else {
				// #ifdef MP
				if (this.userInfo.is_default_avatar) {
					this.editModal = true;
					return;
				}
				// #endif
				uni.navigateTo({
					url: '/pages/users/user_info/index'
				});
			}
		},
		// 签到
		goSignIn() {
			uni.navigateTo({
				url: '/pages/users/user_sgin/index'
			});
		},

		goYfthWorkbench() {
			if (!this.isLogin) {
				toLogin();
				return;
			}
			loadYfthIdentities().then((list) => resolveDominantYfthContext(list)).then((context) => {
				uni.reLaunch({ url: context.is_business_role ? '/pages/yfth/workbench/index' : '/pages/index/index' });
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err || '身份读取失败'), icon: 'none' });
			});
		},

		goYfthFranchiseApplications() {
			if (!this.isLogin) {
				toLogin();
				return;
			}
			uni.navigateTo({
				url: '/pages/yfth/franchise/index'
			});
		},

		goYfthAttribution() {
			if (!this.isLogin) {
				toLogin();
				return;
			}
			uni.navigateTo({
				url: '/pages/yfth/authority/index'
			});
		},

		goYfthPackageMembership() {
			if (!this.isLogin) {
				toLogin();
				return;
			}
			uni.navigateTo({
				url: '/pages/yfth/package_membership/index'
			});
		},

		goYfthPackagePurchase() {
			if (!this.isLogin) {
				toLogin();
				return;
			}
			uni.navigateTo({
				url: '/pages/yfth/package/list'
			});
		},

		goYfthCurrentWorkbench() {
			if (!this.isLogin) { toLogin(); return; }
			if (!this.yfthCurrentContext || !this.yfthCurrentContext.is_business_role) {
				this.goYfthWorkbench();
				return;
			}
			uni.navigateTo({ url: '/pages/yfth/workbench/index' });
		},

		goYfthReferralCode() {
			if (!this.isLogin) { toLogin(); return; }
			if (this.isYfthStoreOperator) {
				const context = this.yfthCurrentContext || {};
				uni.navigateTo({ url: `/pages/yfth/store_acquisition/code?role_code=${encodeURIComponent(context.role_code)}&store_id=${Number(context.store_id || 0)}` });
				return;
			}
			if (!this.isYfthPermanentMember) { this.goYfthPackagePurchase(); return; }
			uni.navigateTo({ url: '/pages/yfth/referral/code' });
		},

		goYfthReferralScan() {
			uni.navigateTo({ url: '/pages/yfth/referral/scan' });
		},

		goYfthRewards() {
			if (!this.isLogin) { toLogin(); return; }
			uni.navigateTo({ url: '/pages/yfth/referral/ledger' });
		},

		goPages(url) {
			this.$util.JumpPath(url);
		},

		// goMenuPage
		goMenuPage(url, name) {
			if (this.isLogin) {
				if (url.indexOf('http') === -1) {
					// #ifdef H5 || APP-PLUS
					if (name && name === '客服接待') {
						// return window.location.href = `${location.origin}${url}`
						return uni.navigateTo({
							url: `/pages/annex/web_view/index?url=${location.origin}${url}`
						});
					} else if (name && name === '联系客服') {
						return getCustomer(url);
					} else if (name === '订单核销') {
						return uni.navigateTo({
							url: url
						});
						// return window.location.href = `${location.origin}${url}`
					}
					// #endif

					// #ifdef MP
					if (name && name === '联系客服') {
						return getCustomer(url);
					}
					if (url != '#' && url == '/pages/users/user_info/index') {
						uni.openSetting({
							success: function (res) {}
						});
					}
					// #endif
					uni.navigateTo({
						url: url,
						fail(err) {
							uni.switchTab({
								url: url
							});
						}
					});
				} else {
					uni.navigateTo({
						url: `/pages/annex/web_view/index?url=${url}`
					});
				}
			} else {
				// #ifdef MP
				this.openAuto();
				// #endif
				// #ifndef MP
				toLogin();
				// #endif
			}
		},
		goRouter(item) {
			var pages = getCurrentPages();
			var page = pages[pages.length - 1].$page.fullPath;
			if (item.link == page) return;
			uni.switchTab({
				url: item.link,
				fail(err) {
					uni.redirectTo({
						url: item.link
					});
				}
			});
		}
	}
};
</script>

<style lang="scss">
page,
body {
	height: 100%;
}

.height {
	margin-top: -100rpx !important;
}

.unBg {
	background-color: unset !important;

	.user-info {
		.info {
			.name {
				color: #333333 !important;
				font-weight: 600;
			}

			.num {
				color: #333 !important;

				.num-txt {
					height: 38rpx;
					background-color: rgba(51, 51, 51, 0.13);
					padding: 0 12rpx;
					border-radius: 16rpx;
				}
			}
		}
	}

	.num-wrapper {
		color: #333 !important;
		font-weight: 600;

		.num-item {
			.txt {
				color: rgba(51, 51, 51, 0.7) !important;
			}
		}
	}

	.message {
		.iconfont {
			color: #333 !important;
		}

		.num {
			color: #fff !important;
			background-color: var(--view-theme) !important;
		}
	}

	.setting {
		.iconfont {
			color: #333 !important;
		}
	}
}

.cardVipB {
	background-color: #343a48;
	width: 100%;
	height: 124rpx;
	border-radius: 16rpx 16rpx 0 0;
	padding: 22rpx 30rpx 0 30rpx;
	margin-top: 16px;

	.left-box {
		.small {
			color: #f8d5a8;
			font-size: 28rpx;
			margin-left: 18rpx;
		}

		.pictrue {
			width: 40rpx;
			height: 45rpx;

			image {
				width: 100%;
				height: 100%;
			}
		}
	}

	.btn {
		color: #bbbbbb;
		font-size: 26rpx;
	}

	.icon-jiantou {
		margin-top: 6rpx;
	}
}

.cardVipA {
	position: absolute;
	background: url('~@/static/images/member.png') no-repeat;
	background-size: 100% 100%;
	width: 750rpx;
	height: 84rpx;
	bottom: -2rpx;
	left: 0;
	padding: 0 56rpx 0 135rpx;

	.left-box {
		font-size: 26rpx;
		color: #905100;
		font-weight: 400;
	}

	.btn {
		color: #905100;
		font-weight: 400;
		font-size: 24rpx;
	}

	.iconfont {
		font-size: 20rpx;
		margin: 4rpx 0 0 4rpx;
	}
}

.new-users {
	display: flex;
	flex-direction: column;
	height: 100%;

	.sys-head {
		position: relative;
		width: 100%;
		// background: linear-gradient(90deg, $bg-star1 0%, $bg-end1 100%);

		.bg {
			position: absolute;
			left: 0;
			top: 0;
			width: 100%;
			height: 100%;
			background: var(--view-theme);
			background-size: 100% auto;
			background-position: left bottom;
		}

		.sys-title {
			z-index: 10;
			position: relative;
			height: 43px;
			text-align: center;
			line-height: 43px;
			font-size: 36rpx;
			color: #ffffff;
		}
	}

	.head {
		// background: #fff;

		.customer-profile {
			position: relative;
			width: 690rpx;
			margin: 24rpx auto 0;
			padding: 34rpx 30rpx 28rpx;
			border-radius: 20rpx;
			box-sizing: border-box;
			background: linear-gradient(135deg, #c89b5c 0%, #ad7a40 100%);
			box-shadow: 0 14rpx 34rpx rgba(96, 69, 37, 0.14);

			.profile-actions {
				position: absolute;
				right: 28rpx;
				top: 28rpx;
				display: flex;
				align-items: center;
				gap: 24rpx;
				z-index: 30;

				navigator,
				.profile-action {
					position: relative;
					display: flex;
					align-items: center;
					justify-content: center;
					width: 42rpx;
					height: 42rpx;
				}

				.iconfont {
					font-size: 36rpx;
					color: #fff;
				}

				.message-count {
					position: absolute;
					top: -12rpx;
					right: -14rpx;
					min-width: 28rpx;
					height: 28rpx;
					padding: 0 6rpx;
					border-radius: 14rpx;
					box-sizing: border-box;
					background: #fff;
					color: #a66d2f;
					font-size: 18rpx;
					line-height: 28rpx;
					text-align: center;
				}
			}

			.user-info {
				z-index: 20;
				position: relative;
				display: flex;

				.avatar-box {
					position: relative;
					display: flex;
					align-items: center;
					justify-content: center;
					width: 104rpx;
					height: 104rpx;
					border-radius: 50%;
					border: 4rpx solid rgba(255, 255, 255, 0.78);
					box-sizing: border-box;
					overflow: hidden;
				}

				.avatar {
					position: relative;
					width: 100%;
					height: 100%;
					border-radius: 50%;
				}

				.info {
					flex: 1;
					display: flex;
					flex-direction: column;
					justify-content: space-between;
					margin-left: 22rpx;
					padding: 8rpx 150rpx 8rpx 0;

					.name {
						display: flex;
						align-items: center;
						color: #fff;
						font-size: 32rpx;
						font-weight: 600;

						.nickname {
							max-width: 8em;
						}

					}

					.num {
						display: flex;
						align-items: center;
						font-size: 26rpx;
						color: rgba(255, 255, 255, 0.78);

						image {
							width: 22rpx;
							height: 23rpx;
							margin-left: 20rpx;
						}
					}
				}
			}

			.membership-summary {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-top: 26rpx;
				padding: 24rpx 24rpx;
				border: 1rpx solid rgba(255, 255, 255, 0.28);
				border-radius: 14rpx;
				background: rgba(255, 255, 255, 0.14);
				color: #fff;

				.membership-eyebrow {
					font-size: 22rpx;
					opacity: 0.76;
				}

				.membership-label {
					margin-top: 4rpx;
					font-size: 32rpx;
					font-weight: 700;
				}

				.membership-desc {
					margin-top: 6rpx;
					font-size: 22rpx;
					opacity: 0.82;
				}

				.membership-link {
					font-size: 23rpx;
					white-space: nowrap;

					.iconfont {
						font-size: 21rpx;
					}
				}
			}

			.identity-summary {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 18rpx;
				margin-top: 18rpx;
				padding: 18rpx 22rpx;
				border-radius: 12rpx;
				background: rgba(255, 255, 255, 0.92);
				color: #654824;

				> view:first-child { display: flex; flex-direction: column; min-width: 0; }
				.identity-eyebrow { color: #99866f; font-size: 20rpx; }
				.identity-name { overflow: hidden; margin-top: 4rpx; font-size: 25rpx; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
				.identity-actions { display: flex; flex-shrink: 0; gap: 18rpx; color: #8b642f; font-size: 22rpx; }
			}
		}

		.order-wrapper {
			background: #fff;
			margin: 0 30rpx;
			border-radius: 16rpx;
			position: relative;
			margin-top: 20rpx;

			.order-hd {
				justify-content: space-between;
				padding: 30rpx 20rpx 10rpx 30rpx;
				margin-top: 0;
				font-size: 30rpx;
				color: #282828;

				.left {
					font-weight: bold;
				}

				.right {
					display: flex;
					align-items: center;
					color: #666666;
					font-size: 26rpx;

					.icon-jiantou {
						margin-left: 5rpx;
						font-size: 26rpx;
					}
				}
			}

			.order-bd {
				display: flex;
				padding: 0 0;

				.order-item {
					display: flex;
					flex-direction: column;
					justify-content: center;
					align-items: center;
					width: 20%;
					height: 140rpx;

					.pic {
						position: relative;
						text-align: center;

						.iconfont {
							font-size: 48rpx;
							color: var(--view-theme);
						}

						image {
							width: 58rpx;
							height: 48rpx;
						}
					}

					.txt {
						margin-top: 6rpx;
						font-size: 26rpx;
						color: #333;
					}
				}
			}
		}
	}

	.slider-wrapper {
		margin: 20rpx 30rpx;
		height: 130rpx;

		swiper,
		swiper-item {
			height: 100%;
		}

		image {
			width: 100%;
			height: 130rpx;
			border-radius: 16rpx;
		}
	}

	.user-menus {
		background-color: #fff;
		margin: 0 30rpx;
		border-radius: 16rpx;
		&.customer-services {
			margin-top: 20rpx;
		}
		.column-box {
			padding: 30rpx 20rpx 10rpx 30rpx;
			.item {
				display: flex;
				align-items: center;
				margin-bottom: 40rpx;
				font-size: 26rpx;
				.name {
					flex: 1;
					text-align: left;
				}
				image {
					width: 40rpx;
					height: 40rpx;
					margin-right: 20rpx;
				}
				.icon-jiantou {
					font-size: 26rpx;
					color: rgb(96, 98, 102);
				}
				&:last-child::before {
					display: none;
				}
				&:last-child {
					margin-bottom: 20rpx;
				}
			}
		}
		.menu-title {
			padding: 30rpx 30rpx 40rpx;
			font-size: 30rpx;
			color: #282828;
			font-weight: bold;
		}

		.list-box {
			display: flex;
			flex-wrap: wrap;
			align-items: flex-start;
			padding: 0 8rpx 8rpx;
			.item {
				position: relative;
				display: flex;
				align-items: center;
				justify-content: flex-start;
				flex-direction: column;
				width: 25%;
				min-height: 156rpx;
				margin-bottom: 28rpx;
				padding: 0 4rpx;
				box-sizing: border-box;
				font-size: 24rpx;
				line-height: 34rpx;
				color: #333333;
				.name {
					display: flex;
					align-items: center;
					justify-content: center;
					width: 100%;
					min-height: 68rpx;
					padding: 0 4rpx;
					box-sizing: border-box;
					text-align: center;
					line-height: 34rpx;
					white-space: normal;
					word-break: break-word;
				}
				image {
					width: 64rpx;
					height: 64rpx;
					margin-bottom: 14rpx;
				}

				&:last-child::before {
					display: none;
				}
			}

			button.item {
				margin-top: 0;
				padding-top: 0;
				background: transparent;
				line-height: 34rpx;

				&::after {
					display: none;
				}
			}
		}

		.yfth-service-item {
			.service-icon {
				display: flex;
				align-items: center;
				justify-content: center;
				width: 64rpx;
				height: 64rpx;
				margin-bottom: 14rpx;
				border-radius: 20rpx;
				background: #f5ead8;
				color: #a66f33;
				font-size: 26rpx;
				font-weight: 700;
			}

			.service-icon-member,
			.service-icon-work {
				background: #e9f0eb;
				color: #416755;
			}

			.service-icon-package {
				background: #f4e8d4;
				color: #9b642d;
			}

			.service-icon-cooperate {
				background: #ece8df;
				color: #706554;
			}
		}

		.configured-service-icon {
			position: relative;
			display: flex;
			align-items: center;
			justify-content: center;
			width: 64rpx;
			height: 64rpx;
			margin-bottom: 14rpx;
			border-radius: 20rpx;
			background: #f1eee8;
			color: #766956;
			font-size: 25rpx;
			font-weight: 700;

			image {
				position: absolute;
				top: 6rpx;
				left: 6rpx;
				width: 52rpx;
				height: 52rpx;
				margin-bottom: 0;
			}
		}

		button {
			font-size: 28rpx;
		}
	}

	.phone {
		color: #fff;
		background-color: #ffffff80;
		border-radius: 15px;
		width: max-content;
		font-size: 24rpx;
		padding: 2px 10px;
		margin-top: 8rpx;
	}

	.order-status-num {
		min-width: 12rpx;
		background-color: #fff;
		color: var(--view-theme);
		border-radius: 15px;
		position: absolute;
		right: -14rpx;
		top: -15rpx;
		font-size: 20rpx;
		padding: 0 8rpx;
		border: 1px solid var(--view-theme);
	}

	.support {
		width: 219rpx;
		height: 74rpx;
		margin: 54rpx auto;
		display: block;
	}
}

.card-vip {
	display: flex;
	align-items: center;
	justify-content: space-between;
	position: relative;
	width: 690rpx;
	height: 134rpx;
	margin: -72rpx auto 0;
	background: url('~@/static/images/user_vip.png');
	background-size: cover;
	padding-left: 118rpx;
	padding-right: 34rpx;

	.left-box {
		font-size: 24rpx;
		color: #ae5a2a;

		.big {
			font-size: 28rpx;
		}

		.small {
			opacity: 0.8;
			margin-top: 10rpx;
		}
	}

	.btn {
		height: 52rpx;
		line-height: 52rpx;
		padding: 0 10rpx;
		text-align: center;
		background: #fff;
		border-radius: 28rpx;
		font-size: 26rpx;
		color: #ae5a2a;
	}
}

.setting {
	margin-top: 15rpx;
	margin-left: 15rpx;
	color: #fff;

	.iconfont {
		font-size: 40rpx;
	}
}

.new-users {
	padding-bottom: 0;
	padding-bottom: constant(safe-area-inset-bottom);
	padding-bottom: env(safe-area-inset-bottom);
}

/* #ifdef H5 */
@media screen and (min-width: 768px) {
	.new-users .mid {
		width: 375px;
		max-width: 100%;
		margin-right: auto;
		margin-left: auto;
		background: #f5f5f5;
	}
}
/* #endif */
.mall-assets,
.member-exclusive {
	margin: 20rpx 20rpx 0;
	border-radius: 12rpx;
	background: #fff;
	box-shadow: 0 8rpx 24rpx rgba(104, 77, 42, 0.06);
}

.mall-assets {
	position: relative;
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	padding: 24rpx 18rpx 52rpx;

	.asset-item {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8rpx;
		border-right: 1px solid #eee8df;
	}

	.asset-item:nth-child(3) { border-right: 0; }
	.asset-value { color: #6e4f2d; font-size: 31rpx; font-weight: 700; }
	.asset-label { color: #6f6a63; font-size: 23rpx; }
	.asset-note { position: absolute; right: 20rpx; bottom: 14rpx; color: #a1998f; font-size: 19rpx; }
}

.member-exclusive {
	padding: 26rpx 24rpx;

	.section-title { margin-bottom: 22rpx; color: #312a22; font-size: 29rpx; font-weight: 650; }
	.exclusive-grid { display: grid; grid-template-columns: repeat(4, 1fr); }
	.exclusive-grid > view { display: flex; flex-direction: column; align-items: center; gap: 11rpx; color: #4f4942; font-size: 22rpx; text-align: center; }
	.exclusive-icon { width: 66rpx; height: 66rpx; border-radius: 18rpx; color: #7a552d; background: #f4eadc; font-size: 27rpx; font-weight: 700; line-height: 66rpx; text-align: center; }
	.membership-prompt { display: flex; align-items: center; justify-content: space-between; padding: 20rpx 22rpx; border-radius: 10rpx; background: #faf4e9; }
	.membership-prompt > view { display: flex; flex-direction: column; gap: 8rpx; }
	.prompt-title { color: #704d28; font-size: 27rpx; font-weight: 650; }
	.prompt-copy { color: #9a8a78; font-size: 21rpx; }
	.prompt-arrow { color: #a67842; font-size: 44rpx; }
}

</style>
