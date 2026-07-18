<template>
  <view class="yfth-home">
    <view class="yfth-home__hero">
      <view class="yfth-home__brand">{{ config.header.title }}</view>
      <view class="yfth-home__search" @tap="openSearch">
        <text class="iconfont icon-sousuo"></text>
        <text>{{ config.header.search_placeholder }}</text>
      </view>
    </view>

    <view class="yfth-home__shortcuts">
      <view v-for="(entry, index) in config.quick_entries" :key="index" class="yfth-home__shortcut" @tap="go(entry.target)">
        <image v-if="entry.icon_url" :src="entry.icon_url" class="yfth-home__shortcut-image" mode="aspectFit" />
        <view v-else class="yfth-home__shortcut-icon" :class="{ active: index === 0 }">{{ shortcutMark(entry.title) }}</view>
        <text class="yfth-home__shortcut-title">{{ entry.title }}</text>
      </view>
    </view>

    <view class="yfth-home__sections">
      <view v-for="(section, index) in config.sections" :key="index" class="yfth-home__section" @tap="go(section.target)">
        <view class="yfth-home__section-title">
          <text>{{ section.title }}</text>
          <text class="iconfont icon-jiantou"></text>
        </view>
        <image v-if="section.image_url" :src="section.image_url" class="yfth-home__section-cover" mode="aspectFill" />
        <view v-else-if="section.items && section.items.length" class="yfth-home__product-grid" :class="{ packages: isPackage(section) }">
          <view v-for="item in section.items" :key="item.id" class="yfth-home__product" @tap.stop="openItem(section, item)">
            <view v-if="isPackage(section)" class="yfth-home__package-copy">
              <text class="yfth-home__package-name">{{ item.package_title || item.package_name }}</text>
              <text class="yfth-home__package-action">查看套餐</text>
            </view>
            <image v-else :src="item.image" class="yfth-home__product-image" mode="aspectFill" />
          </view>
        </view>
        <view v-else class="yfth-home__empty">内容配置后将在这里展示</view>
      </view>
    </view>
    <view class="yfth-home__footer-space"></view>
    <pageFooter :configData="footerConfigData" @newDataStatus="onFooter" />
  </view>
</template>

<script>
import pageFooter from '@/components/pageFooter/index.vue';

export default {
  name: 'YfthCustomHome',
  components: { pageFooter },
  props: {
    config: { type: Object, required: true },
    footerConfigData: { type: Object, default: () => ({}) },
  },
  methods: {
    shortcutMark(title) {
      return (title || '御').slice(0, 1);
    },
    isPackage(section) {
      return section.target && ['package_list', 'package_detail'].includes(section.target.type);
    },
    openSearch() {
      uni.navigateTo({ url: '/pages/goods/goods_list/index' });
    },
    openItem(section, item) {
      if (this.isPackage(section)) {
        this.go({ type: 'package_detail', id: item.id });
        return;
      }
      this.go({ type: 'product', id: item.id });
    },
    go(target) {
      if (!target) return;
      let url = '';
      if (target.type === 'category' && target.id) url = `/pages/goods/goods_list/index?cid=${target.id}`;
      if (target.type === 'product' && target.id) url = `/pages/goods_details/index?id=${target.id}`;
      if (target.type === 'package_list') url = '/pages/yfth/package/list';
      if (target.type === 'package_detail' && target.id) url = `/pages/yfth/package/detail?id=${target.id}`;
      if (target.type === 'path' && /^\/pages\//.test(target.path || '')) url = target.path;
      if (url) uni.navigateTo({ url });
    },
    onFooter() {},
  },
};
</script>

<style scoped lang="scss">
.yfth-home { min-height: 100vh; background: #fffdf8; color: #2e2117; }
.yfth-home__hero { background: #d6a15e; padding: calc(var(--status-bar-height) + 30rpx) 32rpx 146rpx; }
.yfth-home__brand { color: #fffaf2; font-size: 42rpx; font-weight: 600; line-height: 1.2; margin-bottom: 24rpx; }
.yfth-home__search { height: 70rpx; border-radius: 38rpx; background: rgba(255,255,255,.35); color: #fffaf2; display: flex; align-items: center; padding: 0 28rpx; font-size: 26rpx; }
.yfth-home__search .iconfont { margin-right: 14rpx; font-size: 28rpx; }
.yfth-home__shortcuts { margin: -104rpx 22rpx 28rpx; padding: 26rpx 12rpx 20rpx; border-radius: 28rpx; background: #fff; display: grid; grid-template-columns: repeat(6, 1fr); box-shadow: 0 16rpx 38rpx rgba(120, 76, 31, .10); position: relative; z-index: 1; }
.yfth-home__shortcut { min-width: 0; text-align: center; margin: 0 0 20rpx; }
.yfth-home__shortcut-image, .yfth-home__shortcut-icon { width: 64rpx; height: 64rpx; margin: 0 auto 10rpx; border-radius: 16rpx; }
.yfth-home__shortcut-image { display: block; }
.yfth-home__shortcut-icon { display: flex; align-items: center; justify-content: center; border: 3rpx solid #a9743d; color: #a9743d; font-size: 28rpx; font-weight: 600; }
.yfth-home__shortcut-icon.active { background: #d6a15e; border-color: #d6a15e; color: #fff; }
.yfth-home__shortcut-title { display: block; color: #493629; font-size: 22rpx; line-height: 1.32; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.yfth-home__sections { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22rpx; padding: 0 22rpx 30rpx; }
.yfth-home__section { min-height: 286rpx; border: 2rpx solid #ecd8b2; border-radius: 24rpx; background: #fffdf6; overflow: hidden; padding: 24rpx 20rpx 18rpx; box-sizing: border-box; }
.yfth-home__section-title { display: flex; align-items: center; justify-content: space-between; font-size: 30rpx; font-weight: 600; line-height: 1.25; color: #2f2118; margin-bottom: 18rpx; }
.yfth-home__section-title .iconfont { color: #b98751; font-size: 28rpx; }
.yfth-home__section-cover { width: 100%; height: 208rpx; border-radius: 14rpx; display: block; }
.yfth-home__product-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12rpx; }
.yfth-home__product { min-width: 0; }
.yfth-home__product-image { width: 100%; height: 106rpx; border-radius: 50%; background: #f4ead9; display: block; }
.yfth-home__product-grid.packages { grid-template-columns: 1fr; gap: 14rpx; }
.yfth-home__package-copy { min-height: 76rpx; border-radius: 14rpx; padding: 17rpx 16rpx; box-sizing: border-box; background: #d6a15e; color: #fff; display: flex; flex-direction: column; justify-content: center; }
.yfth-home__package-name { font-size: 23rpx; font-weight: 600; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.yfth-home__package-action { margin-top: 6rpx; font-size: 19rpx; color: #fff7e9; }
.yfth-home__empty { height: 184rpx; border-radius: 16rpx; color: #a48c70; background: #faf4e8; display: flex; align-items: center; justify-content: center; text-align: center; padding: 20rpx; font-size: 22rpx; box-sizing: border-box; }
.yfth-home__footer-space { height: calc(120rpx + env(safe-area-inset-bottom)); }
</style>
