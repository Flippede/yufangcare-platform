<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'app' => $root . '/template/franchise-h5/src/App.vue',
    'api' => $root . '/template/franchise-h5/src/api.js',
    'login' => $root . '/template/franchise-h5/src/pages/LoginPage.vue',
    'profile' => $root . '/template/franchise-h5/src/pages/ProfilePage.vue',
    'apply' => $root . '/template/franchise-h5/src/pages/ApplyPage.vue',
];

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
};

$source = [];
foreach ($files as $key => $path) {
    $assert(is_file($path), "{$key} file exists");
    $source[$key] = (string) file_get_contents($path);
}

$allFrontend = implode("\n", $source);
$assert(strpos($source['app'], 'login: LoginPage') !== false, 'dedicated login route is registered');
$assert(strpos($source['app'], "['apply', 'login']") !== false, 'login page does not render portal tabbar');
$assert(strpos($source['api'], "request('login'") !== false, 'account login reuses canonical API');
$assert(strpos($source['api'], 'v2/wechat/auth_login') !== false, 'wechat callback reuses canonical API');
$assert(strpos($source['api'], '/join/#/login') !== false, 'portal login URL remains inside portal');
$assert(strpos($source['login'], '/join/#/home') !== false, 'successful login returns to portal home');
$assert(strpos($source['login'], 'LOGIN_STATUS_TOKEN') === false, 'login page delegates token storage to shared helper');
$assert(strpos($source['login'], 'bindPhone') !== false, 'wechat phone-binding response is handled explicitly');
$assert(strpos($source['login'], '微信快捷登录') !== false, 'wechat login is visible');
$assert(strpos($source['login'], '手机号或账号') !== false, 'phone or account login is visible');
$assert(strpos($source['login'], '暂不登录') !== false, 'skip login returns to portal');
$assert(strpos($allFrontend, '/pages/users/login/index') === false, 'portal never links to legacy mall login page');
$assert(strpos($allFrontend, "'/pages/index/index'") === false, 'portal never redirects to mall home');

fwrite(STDOUT, "YFTH franchise login contract check passed ({$assertions} assertions).\n");
