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
assert(userPage.includes('getMenuList()'), 'customer service menu must remain backend configurable');
assert(userPage.includes('购买康养套餐'), 'YFTH package purchase entry must remain available');
assert(categoryPage.includes('getCategoryList'), 'category page must keep using the CRMEB category API');
assert(categoryPage.includes("window.location.origin}/api/category"), 'H5 category page must read the production category API');
assert(categoryPage.includes('暂无商品分类'), 'category page must retain an empty state');

console.log('YFTH customer surface cleanup contract check passed.');
