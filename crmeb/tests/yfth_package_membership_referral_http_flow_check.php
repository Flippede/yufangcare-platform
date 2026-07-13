<?php

use crmeb\utils\JwtAuth;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

$failures = [];
$server = [];
$assert = function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

if ((string)getenv('YFTH_PACKAGE_MEMBERSHIP_REFERRAL_HTTP_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] http_flow_skipped_set_YFTH_PACKAGE_MEMBERSHIP_REFERRAL_HTTP_FLOW_EXECUTE=1\n";
    exit(0);
}

try {
    packageMembershipReferralBootTestApp();
    $database = (string)Config::get('database.connections.' . Config::get('database.default') . '.database');
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated');
    $assert(strpos($version, '8.0.46') === 0, 'mysql_community_8_0_46');
    $assert((int)Db::name('user')->where('uid', 920001)->count() === 1, 'real_flow_fixture_exists');
    if ($failures) {
        throw new RuntimeException('http_flow_precondition_failed');
    }

    $token = (string)app()->make(JwtAuth::class)->createToken(920001, 'api', ['pwd' => md5('')])['token'];
    $server = pmrHttpStartServer();
    $me = pmrHttpRequest($server['base_url'], $token, 'GET', '/api/yfth/package_membership/me');
    $assert($me['http_code'] === 200 && (int)($me['json']['status'] ?? 0) === 200,
        'membership_me_http_success:' . pmrHttpFailureSummary($me));
    pmrHttpAssertForbiddenAbsent($assert, (array)($me['json']['data'] ?? []), 'membership_me');

    $candidates = pmrHttpRequest($server['base_url'], $token, 'GET', '/api/yfth/package_membership/candidate');
    $assert($candidates['http_code'] === 200 && (int)($candidates['json']['status'] ?? 0) === 200,
        'candidate_list_http_success:' . pmrHttpFailureSummary($candidates));
    pmrHttpAssertForbiddenAbsent($assert, (array)($candidates['json']['data'] ?? []), 'candidate_list');

    $invite = pmrHttpRequest($server['base_url'], $token, 'POST', '/api/yfth/package_membership/invite', [
        'request_id' => 'pmr-http-invite-' . getmypid(),
    ]);
    $assert($invite['http_code'] === 200 && (int)($invite['json']['status'] ?? 0) === 200,
        'invite_issue_http_success:' . pmrHttpFailureSummary($invite));
    $assert((bool)preg_match('/^[a-f0-9]{64}$/', (string)($invite['json']['data']['invite_token'] ?? '')), 'invite_issue_returns_opaque_token');
    pmrHttpAssertForbiddenAbsent($assert, (array)($invite['json']['data'] ?? []), 'invite_issue');
} catch (Throwable $e) {
    $failures[] = 'http_flow_exception:' . $e->getMessage() . ':' . $e->getLine();
} finally {
    pmrHttpStopServer($server);
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}
echo "[OK] YFTH package membership referral real HTTP core flow verified.\n";

function pmrHttpFailureSummary(array $response): string
{
    $body = preg_replace('/\s+/', ' ', strip_tags((string)($response['body'] ?? '')));
    return 'http=' . (int)($response['http_code'] ?? 0) . ',body=' . substr(trim((string)$body), 0, 240);
}

function pmrHttpRequest(string $baseUrl, string $token, string $method, string $path, array $data = []): array
{
    $headers = [
        'Authori-zation: Bearer ' . $token,
        'Authorization: Bearer ' . $token,
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $options = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 20,
    ]];
    if ($method !== 'GET') {
        $options['http']['content'] = http_build_query($data);
    }
    $body = @file_get_contents(rtrim($baseUrl, '/') . $path, false, stream_context_create($options));
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        $code = (int)$matches[1];
    }
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['http_code' => $code, 'body' => (string)$body, 'json' => is_array($json) ? $json : []];
}

function pmrHttpAssertForbiddenAbsent(callable $assert, array $payload, string $label): void
{
    $forbidden = ['referrer_uid', 'referred_uid', 'owner_uid', 'reward_sequence_no', 'rule_version_id'];
    $walk = function (array $value) use (&$walk, $assert, $forbidden, $label): void {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $forbidden, true)) {
                $assert(false, $label . '_leaks_' . $key);
            }
            if (is_array($item)) {
                $walk($item);
            }
        }
    };
    $walk($payload);
}

function pmrHttpStartServer(): array
{
    $root = dirname(__DIR__);
    $installLock = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'install.lock';
    $createdInstallLock = false;
    if (!is_file($installLock)) {
        file_put_contents($installLock, 'stage2_v2_http_validation');
        $createdInstallLock = true;
    }
    $host = '127.0.0.1';
    $port = (int)(getenv('YFTH_PACKAGE_MEMBERSHIP_REFERRAL_HTTP_PORT') ?: 18161);
    $router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_stage2_v2_router_' . getmypid() . '.php';
    pmrHttpWriteRouter($router, $root);
    $cmd = [PHP_BINARY];
    $loadedIni = php_ini_loaded_file();
    if (is_string($loadedIni) && $loadedIni !== '') {
        $cmd[] = '-c';
        $cmd[] = $loadedIni;
    }
    array_push($cmd, '-S', $host . ':' . $port, '-t', sys_get_temp_dir(), $router);
    $env = array_merge($_ENV, [
        'DATABASE_HOSTNAME' => (string)Config::get('database.connections.mysql.hostname'),
        'DATABASE_HOSTPORT' => (string)Config::get('database.connections.mysql.hostport'),
        'DATABASE_USERNAME' => (string)Config::get('database.connections.mysql.username'),
        'DATABASE_PASSWORD' => (string)Config::get('database.connections.mysql.password'),
        'DATABASE_DATABASE' => (string)Config::get('database.connections.mysql.database'),
        'DATABASE_PREFIX' => (string)Config::get('database.connections.mysql.prefix'),
        'DATABASE_CHARSET' => (string)Config::get('database.connections.mysql.charset'),
        'CACHE_DRIVER' => 'file',
    ]);
    if ((string)Config::get('database.connections.mysql.password') === '') {
        $env['DATABASE_PASSWORD_EMPTY'] = '1';
    }
    foreach (['SystemRoot', 'WINDIR', 'PATH', 'PATHEXT', 'TEMP', 'TMP', 'PHPRC'] as $key) {
        if (getenv($key) !== false) {
            $env[$key] = (string)getenv($key);
        }
    }
    $process = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_stage2_v2_http_stdout.log', 'a'],
        2 => ['file', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yfth_stage2_v2_http_stderr.log', 'a'],
    ], $pipes, sys_get_temp_dir(), $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('local_php_server_start_failed');
    }
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $socket = @fsockopen($host, $port, $errno, $error, 0.25);
        if (is_resource($socket)) {
            fclose($socket);
            return compact('process', 'router', 'installLock', 'createdInstallLock') + [
                'base_url' => 'http://' . $host . ':' . $port,
            ];
        }
        usleep(250000);
    }
    proc_terminate($process);
    throw new RuntimeException('local_php_server_not_ready');
}

function pmrHttpStopServer(array $server): void
{
    if (!empty($server['process']) && is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
    }
    if (!empty($server['createdInstallLock']) && !empty($server['installLock'])) {
        @unlink($server['installLock']);
    }
    if (!empty($server['router'])) {
        @unlink($server['router']);
    }
}

function pmrHttpWriteRouter(string $router, string $root): void
{
    $autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $code = <<<'PHP'
<?php
namespace think;
require __AUTOLOAD__;
$_SERVER['DOCUMENT_ROOT'] = __ROOT__ . DIRECTORY_SEPARATOR . 'public';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['PATH_INFO'] = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$app = new class(__ROOT__) extends App {
    public function loadEnv(string $envName = ''): void
    {
        foreach ([
            'DATABASE_HOSTNAME'=>'database.hostname','DATABASE_HOSTPORT'=>'database.hostport',
            'DATABASE_USERNAME'=>'database.username','DATABASE_PASSWORD'=>'database.password',
            'DATABASE_DATABASE'=>'database.database','DATABASE_PREFIX'=>'database.prefix',
            'DATABASE_CHARSET'=>'database.charset','CACHE_DRIVER'=>'cache.driver',
        ] as $envKey => $configKey) {
            if (getenv($envKey) !== false) $this->env->set($configKey, getenv($envKey));
        }
        if ((string)getenv('DATABASE_PASSWORD_EMPTY') === '1') $this->env->set('database.password', '');
    }
};
$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
PHP;
    file_put_contents($router, str_replace(
        ['__AUTOLOAD__', '__ROOT__'],
        [var_export($autoload, true), var_export($root, true)],
        $code
    ));
}
