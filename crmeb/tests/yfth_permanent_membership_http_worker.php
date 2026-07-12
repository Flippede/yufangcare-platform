<?php

[$script, $url, $token, $confirmationToken, $key] = array_pad($argv, 5, '');
$body = http_build_query(['confirmation_token' => $confirmationToken, 'idempotency_key' => $key]);
$context = stream_context_create(['http' => [
    'method' => 'POST', 'ignore_errors' => true, 'timeout' => 30,
    'header' => "Content-Type: application/x-www-form-urlencoded\r\nAuthorization: Bearer {$token}\r\nAuthori-zation: Bearer {$token}\r\n",
    'content' => $body,
]]);
$response = @file_get_contents($url, false, $context);
echo (string)$response . PHP_EOL;
exit(is_string($response) ? 0 : 2);
