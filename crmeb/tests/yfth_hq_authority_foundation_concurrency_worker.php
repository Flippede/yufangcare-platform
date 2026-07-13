<?php

use think\facade\Db;

require __DIR__ . '/yfth_hq_authority_foundation_test_bootstrap.php';
hqAuthorityBootTestApp();

$mode = (string)($argv[1] ?? '');
$args = array_slice($argv, 2);
try {
    $services = hqAuthorityTestServices(true);
    if ($mode === 'assign') {
        [$uid, $storeId, $sourceId, $key] = $args;
        $result = $services['attribution']->assignFirst(
            (int)$uid,
            (int)$storeId,
            hqAuthorityTestMutation('test_concurrency', (int)$sourceId, (string)$key)
        );
        hqWorkerOut(true, $result);
    } elseif ($mode === 'referral') {
        [$referrerUid, $referredUid, $storeId, $sourceId, $key] = $args;
        $result = $services['referral']->create(
            (int)$referrerUid,
            (int)$referredUid,
            (int)$storeId,
            hqAuthorityTestMutation('test_concurrency', (int)$sourceId, (string)$key)
        );
        hqWorkerOut(true, $result);
    } elseif ($mode === 'pause_referral') {
        [$relationId, $expectedVersion, $sourceId, $key] = $args;
        $result = $services['referral']->pause(
            (int)$relationId,
            (int)$expectedVersion,
            hqAuthorityTestMutation('test_concurrency', (int)$sourceId, (string)$key)
        );
        hqWorkerOut(true, $result);
    } elseif ($mode === 'hold_attribution') {
        [$uid, $milliseconds] = $args;
        Db::transaction(function () use ($uid, $milliseconds) {
            Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$uid)->lock(true)->find();
            usleep(max(1, (int)$milliseconds) * 1000);
        });
        hqWorkerOut(true, ['held_uid' => (int)$uid]);
    } elseif ($mode === 'assign_lock_wait') {
        [$uid, $storeId, $sourceId, $key] = $args;
        Db::execute('SET SESSION innodb_lock_wait_timeout = 1');
        $result = $services['attribution']->assignFirst(
            (int)$uid,
            (int)$storeId,
            hqAuthorityTestMutation('test_retry', (int)$sourceId, (string)$key)
        );
        hqWorkerOut(true, $result);
    } elseif ($mode === 'deadlock_probe') {
        [$firstUid, $secondUid, $sourceId, $key] = $args;
        $mutation = hqAuthorityTestMutation('test_retry', (int)$sourceId, (string)$key);
        $result = $services['runner']->run('deadlock_probe', $mutation, [
            'first_uid' => (int)$firstUid,
            'second_uid' => (int)$secondUid,
        ], 'deadlock:' . $key, function () use ($firstUid, $secondUid) {
            Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$firstUid)->lock(true)->find();
            usleep(250000);
            Db::name('yfth_hq_customer_attribution_current')->where('uid', (int)$secondUid)->lock(true)->find();
            return ['locked' => [(int)$firstUid, (int)$secondUid]];
        });
        hqWorkerOut(true, $result);
    } else {
        throw new InvalidArgumentException('unknown_worker_mode');
    }
} catch (Throwable $e) {
    hqWorkerOut(false, ['error' => $e->getMessage(), 'code' => (string)$e->getCode()]);
    exit(2);
}

function hqWorkerOut(bool $ok, array $payload): void
{
    echo json_encode(['ok' => $ok, 'payload' => $payload], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
