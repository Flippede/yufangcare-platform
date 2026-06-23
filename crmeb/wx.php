<?php
// 2025年终极纯净版 - 专治 echostr 加双引号/空格问题（基于CSDN腾讯云实测）
ignore_user_abort(true);
error_reporting(0);

// 多重清空缓冲 + 强制纯文本头（关键：防止JSON序列化加""）
ob_start();
ob_clean();
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 0);

define("TOKEN", "yfth123456");  // 与后台一致

if (isset($_GET['echostr'])) {
    $signature = $_GET['signature'] ?? '';
    $timestamp = $_GET['timestamp'] ?? '';
    $nonce = $_GET['nonce'] ?? '';
    
    $arr = [TOKEN, $timestamp, $nonce];
    sort($arr, SORT_STRING);
    
    if (sha1(implode($arr)) === $signature) {
        ob_clean();  // 最终清空
        echo $_GET['echostr'];  // 原样返回（强制字符串）
        exit;
    }
}

// POST 消息处理（防止超时）
ob_clean();
echo 'success';
?>