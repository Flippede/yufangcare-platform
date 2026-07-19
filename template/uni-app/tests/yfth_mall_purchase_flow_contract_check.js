const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const failures = [];
const assertContains = (source, fragment, label) => {
  if (!source.includes(fragment)) failures.push(label);
};

const detail = read('pages/goods_details/index.vue');
const storeApi = read('api/store.js');
const orderApi = read('api/order.js');
const cart = read('pages/order_addcart/order_addcart.vue');
const confirmation = read('pages/goods/order_confirm/index.vue');
const baseCss = read('static/css/base.css');

assertContains(detail, '<productWindow', 'crm_product_sku_window_is_mounted');
assertContains(detail, '@submit="joinCart"', 'detail_has_add_to_cart_action');
assertContains(detail, '@submit="goBuy"', 'detail_has_buy_now_action');
assertContains(detail, ':iScart="1"', 'sku_window_has_confirm_action');
assertContains(detail, '@goCat="goCat"', 'sku_window_confirm_uses_crmeb_cart_action');
assertContains(detail, 'purchaseMode: 0', 'detail_tracks_cart_or_buy_intent_through_sku_window');
assertContains(detail, 'const buyNow = news === true || that.purchaseMode === 1;', 'sku_confirmation_preserves_buy_now_intent');
assertContains(detail, 'new: buyNow ? 1 : 0,', 'cart_add_receives_existing_crmeb_buy_now_flag');
assertContains(detail, "url = '/pages/goods/order_confirm/index?new=1&cartId=' + res.data.cartId", 'buy_now_uses_crmeb_order_confirmation');
assertContains(detail, "background-color: var(--view-bntColor, #c99b5a)", 'add_to_cart_has_theme_fallback');
assertContains(detail, "background-color: var(--view-theme, #a4773f)", 'buy_now_has_theme_fallback');
assertContains(detail, '暂无商品图文介绍', 'empty_product_description_is_explicit');
assertContains(storeApi, "request.get('product/detail/' + id", 'detail_uses_crmeb_product_api');
assertContains(storeApi, "request.post('cart/add'", 'detail_uses_crmeb_cart_api');
assertContains(cart, 'cartList', 'existing_crmeb_cart_page_remains_available');
assertContains(confirmation, 'orderCreate(that.orderKey, data)', 'order_confirmation_uses_crmeb_order_api');
assertContains(orderApi, "request.post('order/create/' + key", 'existing_crmeb_order_creation_remains_available');
assertContains(baseCss, '--view-bntColor: #c99b5a;', 'h5_and_mp_have_safe_theme_defaults');

if (failures.length) {
  failures.forEach((failure) => console.error(`[FAIL] ${failure}`));
  process.exit(1);
}

console.log('YFTH CRMEB mall purchase flow contract check passed.');
