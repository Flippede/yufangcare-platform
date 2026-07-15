<template>
	<view :style="colorStyle">
		<goodsCate1 v-if="category == 1" ref="classOne" :isNew="isNew"></goodsCate1>
		<goodsCate2 v-if="category == 2" ref="classTwo" :isNew="isNew" @jumpIndex="jumpIndex"></goodsCate2>
		<goodsCate3 v-if="category == 3" ref="classThree" :isNew="isNew" @jumpIndex="jumpIndex"></goodsCate3>
		<!-- Keep category on the native four-tab navigation instead of replacing it with DIY footer data. -->
		<pageFooter v-if="category == 1" :isTabBar="false" @newDataStatus="newDataStatus" v-show="false"></pageFooter>
	</view>
</template>

<script>
import colors from '@/mixins/color';
import goodsCate1 from './goods_cate1';
import goodsCate2 from './goods_cate2';
import goodsCate3 from './goods_cate3';
import { colorChange } from '@/api/api.js';
import { mapGetters } from 'vuex';
import { getCategoryVersion } from '@/api/public.js';
import pageFooter from '@/components/pageFooter/index.vue';
export default {
	computed: mapGetters(['isLogin', 'uid']),
	components: {
		goodsCate1,
		goodsCate2,
		goodsCate3,
		pageFooter
	},
	mixins: [colors],
	data() {
		return {
			category: 1,
			is_diy: uni.getStorageSync('is_diy'),
			status: 0,
			version: '',
			isNew: false,
			isFooter: false,
			showBar: false,
			categoryInitialized: false
		};
	},
	mounted() {
		this.initializeCategory();
	},
	onLoad() {},
	onReady() {},
	onShow() {
		this.initializeCategory();
	},
	methods: {
		initializeCategory() {
			if (this.categoryInitialized) return;
			this.categoryInitialized = true;
			// #ifdef H5
			this.loadH5CategoryStyle();
			// #endif
			// #ifndef H5
			this.getCategoryVersion();
			// #endif
		},
		loadH5CategoryStyle() {
			window.fetch(`${window.location.origin}/api/v2/diy/color_change/category`, {
				credentials: 'same-origin'
			}).then((response) => {
				if (!response.ok) throw new Error('category_style_request_failed');
				return response.json();
			}).then((res) => {
				this.classStyle(res.data || {});
			}).catch(() => {
				this.classStyle({ status: 1, is_diy: 1 });
			});
		},
		newDataStatus(val, num) {
			this.isFooter = val ? true : false;
			this.showBar = val ? true : false;
			this.pdHeight = num;
		},
		getCategoryVersion() {
			uni.$emit('uploadFooter');
			getCategoryVersion().then((res) => {
				if (!uni.getStorageSync('CAT_VERSION') || res.data.version != uni.getStorageSync('CAT_VERSION')) {
					uni.setStorageSync('CAT_VERSION', res.data.version);
					uni.$emit('uploadCatData');
				}
				this.classStyle();
			});
		},
		jumpIndex() {
			uni.reLaunch({
				url: '/pages/index/index'
			})
		},
		classStyle(styleData) {
			const applyStyle = (data) => {
				let status = Number(data.status) || 1;
				this.category = status;
				this.is_diy = data.is_diy;
				uni.setStorageSync('is_diy', data.is_diy);
				this.$nextTick((e) => {
					if (status == 2 || status == 3) {
						uni.hideTabBar();
					} else {
						const firstCategory = this.$refs.classOne;
						if (!firstCategory) return;
						firstCategory.is_diy = data.is_diy;
						uni.showTabBar();
						firstCategory.getNav();
					}
				});
			};
			if (styleData) {
				applyStyle(styleData);
				return;
			}
			colorChange('category').then((res) => {
				applyStyle(res.data || {});
			}).catch(() => {
				applyStyle({ status: 1, is_diy: 1 });
			});
		}
	},
	onReachBottom: function () {
		if (this.category == 2) {
			this.$refs.classTwo.productslist();
		}
		if (this.category == 3) {
			this.$refs.classThree.productslist();
		}
	}
};
</script>
<style scoped lang="scss">
/deep/.mask {
	z-index: 99;
}
::-webkit-scrollbar {
	width: 0;
	height: 0;
	color: transparent;
	display: none;
}
</style>
