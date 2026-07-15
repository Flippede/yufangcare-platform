const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const userPage = fs.readFileSync(path.join(root, 'pages/user/index.vue'), 'utf8');
const categoryPage = fs.readFileSync(path.join(root, 'pages/goods_cate/goods_cate1.vue'), 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(!userPage.includes('copyRightPic'), 'customer center must not render the legacy copyright image');
assert(!userPage.includes('/static/images/support.png'), 'customer center must not use the CRMEB support image');
assert(!userPage.includes('/pages/annex/vip_paid/index'), 'customer center must not expose the legacy paid-membership surface');
assert(!userPage.includes("$t('未开通会员')"), 'customer center must not use legacy membership wording');
assert(userPage.includes('getMenuList()'), 'customer service menu must remain backend configurable');
assert(userPage.includes('购买康养套餐'), 'YFTH package purchase entry must remain available');
assert(!userPage.includes('class="yfth-entry-card"'), 'YFTH business entries must not remain as stacked top cards');
assert(userPage.includes('class="customer-profile"'), 'customer center must have one unified profile surface');
assert(userPage.includes('getYfthPackageMembershipMe'), 'customer center membership status must use the existing YFTH authority API');
assert(userPage.includes('class="membership-summary"'), 'customer center must render one compact membership summary');
assert(userPage.includes('class="user-menus customer-services"'), 'YFTH and configured entries must share one service section');
assert(userPage.includes('serviceMenuInitial(item.name)'), 'configured service menus must retain a visible fallback when no icon is configured');
['我的归属', '套餐会员与一级推荐', '购买康养套餐', '御方通和合作中心'].forEach((name) => {
  assert(userPage.includes(name), `customer service grid must include ${name}`);
});
assert(categoryPage.includes('getCategoryList'), 'category page must keep using the CRMEB category API');
assert(categoryPage.includes("window.location.origin}/api/category"), 'H5 category page must read the production category API');
assert(categoryPage.includes('暂无商品分类'), 'category page must retain an empty state');

console.log('YFTH customer surface cleanup contract check passed.');
