const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const categoryPage = fs.readFileSync(path.join(root, 'pages/goods_cate/goods_cate.vue'), 'utf8');
const categoryFirst = fs.readFileSync(path.join(root, 'pages/goods_cate/goods_cate1.vue'), 'utf8');
const customHome = fs.readFileSync(path.join(root, 'pages/index/components/yfthCustomHome.vue'), 'utf8');

function requireText(source, text, name) {
  if (!source.includes(text)) throw new Error(`missing:${name}`);
}

[
  ['category: 1', 'default_category_surface'],
  ['mounted()', 'h5_mounted_initializer'],
  ['initializeCategory()', 'category_initializer'],
  ['/api/v2/diy/color_change/category', 'h5_category_style_endpoint'],
  ["credentials: 'same-origin'", 'same_origin_request'],
].forEach(([text, name]) => requireText(categoryPage, text, name));

[
  ['requestCategoryList()', 'category_request_helper'],
  ['/api/category', 'h5_category_endpoint'],
  ['category-empty', 'category_empty_state'],
  ['categoryLoadError', 'category_failure_state'],
].forEach(([text, name]) => requireText(categoryFirst, text, name));

[
  ['/pages/goods/goods_list/index?cid=', 'category_navigation'],
  ['/pages/goods_details/index?id=', 'product_navigation'],
  ["/pages/yfth/package/list", 'package_navigation'],
].forEach(([text, name]) => requireText(customHome, text, name));

console.log('YFTH category page contract check passed.');
