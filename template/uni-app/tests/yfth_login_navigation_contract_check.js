const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function requireText(source, needle, label) {
  if (!source.includes(needle)) {
    throw new Error(`Missing ${label}: ${needle}`);
  }
}

const login = read('pages/users/login/index.vue');
const verifyPoint = read('pages/users/components/verify/verifyPoint/verifyPoint.vue');
const category = read('pages/goods_cate/goods_cate.vue');
const footer = read('components/pageFooter/index.vue');
const pages = read('pages.json');

[
  ['loginDisabled()', 'explicit login button state'],
  ['@tap="handleLogin"', 'single login action'],
  ['maxlength="32"', 'long staging account support'],
  ["/^[a-z0-9_]{5,32}$/i", 'account syntax validation'],
  ["loginLoading ? '登录中...'", 'visible login progress'],
  ["error || '登录后读取用户信息失败'", 'post-login user error feedback'],
  ['mode-switch', 'login mode switch'],
	['protocol-check', 'stable protocol selection control'],
	['@tap="toggleProtocol"', 'protocol text and control interaction'],
  ['copyRight && copyRight.copyrightContext', 'safe copyright storage access'],
].forEach(([needle, label]) => requireText(login, needle, label));

[
  ['getH5MousePos(e, rect)', 'H5 pointer coordinate helper'],
  ['clientX', 'desktop pointer support'],
  ['changedTouches', 'mobile touch support'],
  ['verify-image-hitbox', 'pointer hit area'],
  ['@tap.stop="canvasClick($event)"', 'tap binding'],
	['ref="hitbox"', 'component scoped captcha target'],
	["'pointerup'", 'native H5 mouse and touch pointer listener'],
	['this.bindH5Pointer();', 'captcha lifecycle pointer binding'],
  ['验证失败，请刷新后重试', 'captcha retry feedback'],
].forEach(([needle, label]) => requireText(verifyPoint, needle, label));

[
  [':isTabBar="false"', 'category DIY footer isolation'],
  ['uni.showTabBar();', 'native category tab restoration'],
].forEach(([needle, label]) => requireText(category, needle, label));

[
	["uni.setStorageSync('footerNavigation', res.data)", 'fresh navigation cache'],
	['this.getNavigationInfo(footerNavigation)', 'server-first navigation refresh'],
	['uni.showTabBar();', 'native four-tab fallback'],
].forEach(([needle, label]) => requireText(footer, needle, label));

requireText(pages, '"pagePath": "pages/goods_cate/goods_cate"', 'native category tab page');
console.log('YFTH login and navigation contract check passed.');
