const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const readSource = (file) => fs.readFileSync(path.join(root, file), 'utf8').replace(/\r\n/g, '\n');
const categoryPage = readSource('pages/goods_cate/goods_cate.vue');
const categoryFirst = readSource('pages/goods_cate/goods_cate1.vue');
const customHome = readSource('pages/index/components/yfthCustomHome.vue');
const goodsList = readSource('pages/goods/goods_list/index.vue');
const requestUtil = readSource('utils/request.js');

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
  ['const rect = res && res[0];', 'category_layout_measurement_guard'],
  ['// #ifdef H5\n\t\t\t\treturn;', 'h5_scroll_measurement_skip'],
  ['<image mode="aspectFill" :src="item.pic || defimg"></image>', 'native_category_image'],
].forEach(([text, name]) => requireText(categoryFirst, text, name));

[
  ['/pages/goods/goods_list/index?cid=', 'category_navigation'],
  ['/pages/goods_details/index?id=', 'product_navigation'],
  ["/pages/yfth/package/list", 'package_navigation'],
].forEach(([text, name]) => requireText(customHome, text, name));

[
  ["@click.stop='addToCart(item, index)'", 'goods_list_cart_action'],
  ['postCartNum({', 'goods_list_existing_cart_api'],
  ["this.$set(this.productList[index], 'cart_num'", 'goods_list_cart_count_feedback'],
  ['{{$t(`销量`)}} {{item.sales || 0}}', 'goods_list_sales_display'],
  ['if (item.spec_type || !item.cart_button', 'goods_list_spec_guard'],
].forEach(([text, name]) => requireText(goodsList, text, name));

[
  ['function h5FetchRequest', 'h5_fetch_adapter'],
  ["credentials: 'same-origin'", 'h5_same_origin_credentials'],
  ["requestBase.replace(/\\/$/, '') + '/api/' + url", 'existing_api_base_path'],
].forEach(([text, name]) => requireText(requestUtil, text, name));

console.log('YFTH category page contract check passed.');
