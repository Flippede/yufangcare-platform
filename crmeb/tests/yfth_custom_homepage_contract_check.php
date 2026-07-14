<?php

$root = dirname(__DIR__);
$checks = [
    'service' => $root . '/app/services/yfth/HomepageServices.php',
    'public_controller' => $root . '/app/api/controller/v1/yfth/HomepageController.php',
    'admin_controller' => $root . '/app/adminapi/controller/v1/yfth/Homepage.php',
    'public_route' => $root . '/app/api/route/yfth_service.php',
    'admin_route' => $root . '/app/adminapi/route/yfth.php',
    'migration' => $root . '/database/migrations/20260714170000_create_yfth_homepage_config.php',
    'uni_home' => dirname($root) . '/template/uni-app/pages/index/components/yfthCustomHome.vue',
    'uni_index' => dirname($root) . '/template/uni-app/pages/index/index.vue',
    'uni_cache' => dirname($root) . '/template/uni-app/utils/cache.js',
    'uni_app' => dirname($root) . '/template/uni-app/App.vue',
    'admin_home' => dirname($root) . '/template/admin/src/pages/yfth/homepage/index.vue',
];

foreach ($checks as $name => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing:$name\n");
        exit(1);
    }
}

$service = file_get_contents($checks['service']);
$publicRoute = file_get_contents($checks['public_route']);
$adminRoute = file_get_contents($checks['admin_route']);
$uniHome = file_get_contents($checks['uni_home']);
$uniIndex = file_get_contents($checks['uni_index']);
$uniCache = file_get_contents($checks['uni_cache']);
$uniApp = file_get_contents($checks['uni_app']);
$adminHome = file_get_contents($checks['admin_home']);

foreach ([
    'public_config' => 'publicConfig',
    'real_product_lookup' => "Db::name('store_product')",
    'real_package_lookup' => "Db::name('yfth_package_template')",
    'no_hardcoded_product_id' => "product_ids' => []",
] as $name => $needle) {
    if (strpos($service, $needle) === false) {
        fwrite(STDERR, "missing_service_contract:$name\n");
        exit(1);
    }
}

$categoryQuery = substr($service, strpos($service, "Db::name('store_category')"), 300);
if (strpos($categoryQuery, "->where('is_del'") !== false) {
    fwrite(STDERR, "invalid_category_soft_delete_filter\n");
    exit(1);
}

foreach (["yfth/homepage", 'HomepageController/index'] as $needle) {
    if (strpos($publicRoute, $needle) === false) {
        fwrite(STDERR, "missing_public_route:$needle\n");
        exit(1);
    }
}
foreach (["Route::group('yfth'", "Route::group('homepage'", 'v1.yfth.Homepage/config', 'v1.yfth.Homepage/save'] as $needle) {
    if (strpos($adminRoute, $needle) === false) {
        fwrite(STDERR, "missing_admin_route:$needle\n");
        exit(1);
    }
}
foreach (['/pages/yfth/package/list', '/pages/goods_details/index', '/pages/goods/goods_list/index'] as $needle) {
    if (strpos($uniHome, $needle) === false) {
        fwrite(STDERR, "missing_customer_navigation:$needle\n");
        exit(1);
    }
}
foreach (["Array.isArray(cahceValue)", "homepageState === 'error'", '首页内容暂时不可用', 'const query = queryData.query || {}', 'const query = (option && option.query) || {}'] as $needle) {
    if (strpos($uniCache . $uniIndex . $uniApp, $needle) === false) {
        fwrite(STDERR, "missing_h5_safety_guard:$needle\n");
        exit(1);
    }
}
foreach (['快捷入口', '双列内容卡片', '真实 CRMEB 商品/分类/套餐绑定'] as $needle) {
    if (strpos($adminHome, $needle) === false) {
        fwrite(STDERR, "missing_admin_surface:$needle\n");
        exit(1);
    }
}

echo "YFTH custom homepage contract check passed.\n";
