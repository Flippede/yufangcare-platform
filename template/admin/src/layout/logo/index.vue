<template>
  <div
    class="layout-logo"
    v-if="$store.state.themeConfig.themeConfig.layout !== 'columns' && !$store.state.themeConfig.themeConfig.isCollapse"
    v-db-click
    @click="onThemeConfigChange"
  >
    <img class="layout-logo-medium-img" :src="brandLogo" alt="养郎中" />
  </div>
  <div class="layout-logo-size" v-else v-db-click @click="onThemeConfigChange">
    <img class="layout-logo-size-img" :src="brandLogo" alt="养郎中" />
  </div>
</template>

<script>
import brandLogo from '@/assets/brand/yanglangzhong-logo.png';

export default {
  name: 'layoutLogo',
  data() {
    return {
      brandLogo,
    };
  },
  computed: {
    // 获取布局配置信息
    getThemeConfig() {
      return this.$store.state.themeConfig.themeConfig;
    },
    // 设置 logo 是否显示
    setShowLogo() {
      let { isCollapse, layout } = this.$store.state.themeConfig.themeConfig;
      return !isCollapse || layout === 'classic' || document.body.clientWidth < 1000;
    },
  },
  methods: {
    // logo 点击实现菜单展开/收起
    onThemeConfigChange() {
      if (
        this.$store.state.themeConfig.themeConfig.layout == 'columns' &&
        !this.$store.state.menus.childMenuList.length &&
        this.$store.state.themeConfig.themeConfig.isCollapse
      )
        return;
      if (
        this.$store.state.themeConfig.themeConfig.layout === 'transverse' ||
        this.$store.state.themeConfig.themeConfig.layout === 'classic'
      )
        return false;
      this.$store.state.themeConfig.themeConfig.isCollapse = !this.$store.state.themeConfig.themeConfig.isCollapse;
    },
  },
};
</script>

<style scoped lang="scss">
.layout-logo {
  width: 180px;
  height: 50px;
  display: flex;
  align-items: center;
  justify-content: center;
  //   box-shadow: 0px 1px 4px rgba(0, 21, 41, 2%);
  color: var(--prev-color-primary);
  font-size: 16px;
  cursor: pointer;
  animation: logoAnimation 0.3s ease-in-out;
  &:hover {
    span {
      opacity: 0.9;
    }
  }
  &-medium-img {
    width: 160px;
    height: 44px;
    object-fit: contain;
    background: #fff;
    padding: 3px 8px;
    border-radius: 4px;
  }

  &-text {
    font-size: 17px;
    font-weight: 600;
    color: #ffffff;
  }
}
.layout-logo-size {
  width: 50px;
  height: 50px;
  display: flex;
  cursor: pointer;
  margin: auto;

  &-img {
    width: 50px;
    height: 44px;
    object-fit: contain;
    background: #fff;
    padding: 2px;
    margin: auto;
    animation: logoAnimation 0.3s ease-in-out;
  }

  &-text {
    margin: auto;
    font-size: 18px;
    font-weight: 600;
    color: #ffffff;
  }
}
</style>
