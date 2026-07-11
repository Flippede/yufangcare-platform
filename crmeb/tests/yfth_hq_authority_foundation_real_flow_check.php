<?php

use app\services\yfth\HqAuthoritySource;
use app\services\yfth\HqAuthoritySourceCanonicalizer;
use think\facade\Config;
use think\facade\Db;

require __DIR__ . '/yfth_hq_authority_foundation_test_bootstrap.php';

$failures = [];
$passes = [];
$notes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};
$expect = function (callable $callback, string $needle, string $label) use ($assert): void {
    try {
        $callback();
        $assert(false, $label . ':no_exception');
    } catch (Throwable $e) {
        $assert(strpos($e->getMessage(), $needle) !== false, $label . ':' . $e->getMessage());
    }
};

if ((string)getenv('YFTH_HQ_AUTHORITY_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] real_flow_skipped_set_YFTH_HQ_AUTHORITY_REAL_FLOW_EXECUTE=1\n";
    exit(0);
}

$fixture = [];
try {
    hqAuthorityBootTestApp();
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $database = (string)Config::get('database.connections.' . Config::get('database.default') . '.database');
    $port = (string)Config::get('database.connections.' . Config::get('database.default') . '.hostport');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard_enabled');
    $assert(strpos($version, '8.0.46') === 0 && stripos($version, 'mariadb') === false, 'mysql_community_8_0_46:' . $version);
    $assert((bool)preg_match('/(validation|sandbox|test)/i', $database), 'database_name_is_isolated:' . $database);
    $notes[] = 'mysql_port:' . $port;
    $notes[] = 'database:' . $database;

    hqAssertSchema($assert, $database);
    $fixture = hqSeedFixture();
    putenv('YFTH_HQ_TEST_OPERATOR_UID=' . $fixture['users'][0]);
    $services = hqAuthorityTestServices(true);
    $sourceSeq = 1000;
    $key = function (string $name) use ($fixture): string { return $fixture['run_id'] . ':' . $name; };
    $mutation = function (string $type, string $name) use (&$sourceSeq, $key) {
        return hqAuthorityTestMutation($type, ++$sourceSeq, $key($name));
    };
    $assign = function (int $uid, int $storeId, string $name) use ($services, $mutation) {
        return $services['attribution']->assignFirst($uid, $storeId, $mutation('test_attribution', $name));
    };

    $expect(function () use ($services) {
        $services['attribution']->ensurePlaceholder(4294960000);
    }, 'authority_user_not_found', 'nonexistent_uid_rejected');
    $expect(function () {
        HqAuthoritySource::fromTrusted('test_attribution', 1, ['source_unique_key' => str_repeat('a', 64)]);
    }, 'authority_client_source_key_forbidden', 'client_source_key_rejected');
    $expect(function () {
        HqAuthoritySource::fromTrusted('', 1);
    }, 'authority_source_type_invalid', 'empty_source_rejected');
    $expect(function () {
        (new HqAuthoritySourceCanonicalizer())->attributionEvent('attribution_created', HqAuthoritySource::fromTrusted('test_attribution', 1));
    }, 'authority_source_type_not_allowed', 'production_source_allowlist_is_empty');

    $uidA = $fixture['users'][0];
    $uidB = $fixture['users'][1];
    $storeA = $fixture['stores'][0];
    $storeB = $fixture['stores'][1];
    $placeholder = $services['attribution']->ensurePlaceholder($uidA);
    $assert((string)$placeholder['status'] === 'unassigned' && (int)$placeholder['authority_version'] === 0, 'initial_placeholder_version_zero');
    $firstSourceId = ++$sourceSeq;
    $firstMutation = hqAuthorityTestMutation('test_attribution', $firstSourceId, $key('assign-a'));
    $first = $services['attribution']->assignFirst($uidA, $storeA, $firstMutation);
    $assert((int)$first['current']['authority_version'] === 1 && (string)$first['current']['status'] === 'active', 'pristine_assignment_moves_version_zero_to_one');
    $assert(hqCount('yfth_hq_customer_attribution_event', ['uid' => $uidA, 'authority_version' => 1, 'event_type' => 'attribution_created']) === 1, 'version_one_attribution_event_written');
    $replay = $services['attribution']->assignFirst($uidA, $storeA, $firstMutation);
    $assert(!empty($replay['idempotent_replay']) && hqCount('yfth_hq_customer_attribution_event', ['uid' => $uidA]) === 1, 'same_request_replays_without_event');
    $expect(function () use ($services, $uidA, $storeB, $mutation) {
        $services['attribution']->assignFirst($uidA, $storeB, $mutation('test_attribution', 'cross-store-a'));
    }, 'attribution_store_conflict', 'cross_store_attribution_rejected');

    $atomicUid = $fixture['users'][2];
    $services['attribution']->ensurePlaceholder($atomicUid);
    $expect(function () use ($services, $atomicUid, $storeA, $firstSourceId, $key) {
        $services['attribution']->assignFirst(
            $atomicUid,
            $storeA,
            hqAuthorityTestMutation('test_attribution', $firstSourceId, $key('atomic-event-conflict'))
        );
    }, 'attribution_event_unique_conflict', 'event_unique_failure_raised');
    $atomicCurrent = hqOne('yfth_hq_customer_attribution_current', ['uid' => $atomicUid]);
    $assert((int)$atomicCurrent['authority_version'] === 0 && (string)$atomicCurrent['status'] === 'unassigned', 'event_failure_rolls_back_current');

    $pauseUid = $fixture['users'][3];
    $assign($pauseUid, $storeA, 'assign-pause');
    $paused = $services['attribution']->pause($pauseUid, 1, 'temporary_risk_pause', $mutation('test_transition', 'pause'));
    $assert((string)$paused['current']['status'] === 'paused' && (int)$paused['current']['store_id'] === $storeA, 'paused_attribution_keeps_store_ownership');
    $expect(function () use ($services, $pauseUid, $storeB, $mutation) {
        $services['attribution']->assignFirst($pauseUid, $storeB, $mutation('test_attribution', 'paused-cross-store'));
    }, 'attribution_not_pristine', 'paused_attribution_cannot_be_rebound');
    $services['attribution']->resume($pauseUid, 2, $mutation('test_transition', 'resume'));

    $historicalUid = $fixture['users'][4];
    $assign($historicalUid, $storeA, 'assign-historical');
    $historical = $services['attribution']->markHistoricalUnassigned($historicalUid, 1, $mutation('test_transition', 'historical'));
    $assert((string)$historical['current']['status'] === 'unassigned' && (int)$historical['current']['authority_version'] === 2, 'historical_unassigned_has_positive_version');
    $expect(function () use ($services, $historicalUid, $storeA, $mutation) {
        $services['attribution']->assignFirst($historicalUid, $storeA, $mutation('test_attribution', 'historical-rebind'));
    }, 'attribution_not_pristine', 'historical_unassigned_rebind_rejected');

    $closedUid = $fixture['users'][5];
    $assign($closedUid, $storeA, 'assign-closed');
    $services['attribution']->close($closedUid, 1, 'account_closed', $mutation('test_transition', 'close-attribution'));
    $expect(function () use ($services, $closedUid, $storeA, $mutation) {
        $services['attribution']->assignFirst($closedUid, $storeA, $mutation('test_attribution', 'closed-rebind'));
    }, 'attribution_not_pristine', 'closed_attribution_rebind_rejected');

    $assign($uidB, $storeA, 'assign-b');
    $productionServices = hqAuthorityTestServices(false);
    $expect(function () use ($productionServices, $uidA, $uidB, $storeA, $mutation) {
        $productionServices['referral']->create($uidA, $uidB, $storeA, $mutation('test_referral', 'production-fail-closed'));
    }, 'permanent_membership_authority_unavailable', 'production_referral_qualification_fails_closed');

    $relationMutation = $mutation('test_referral', 'relation-created');
    $relation = $services['referral']->create($uidA, $uidB, $storeA, $relationMutation);
    $relationId = (int)$relation['relation']['id'];
    $assert((int)$relation['relation']['relation_version'] === 1 && (string)$relation['relation']['status'] === 'active', 'referral_starts_active_at_version_one');
    $assert(hqCount('yfth_hq_active_referral_event', ['referral_current_id' => $relationId, 'relation_version' => 1, 'event_type' => 'relation_created']) === 1, 'relation_created_event_version_one');
    $rawRelation = hqOne('yfth_hq_active_referral_current', ['id' => $relationId]);
    $creationKey = (string)$rawRelation['source_unique_key'];
    $services['referral']->pause($relationId, 1, $mutation('test_referral', 'relation-pause'));
    $services['referral']->resume($relationId, 2, $mutation('test_referral', 'relation-resume'));
    $closedRelation = $services['referral']->close($relationId, 3, 'membership_activated', $mutation('test_referral', 'relation-close'));
    $rawClosed = hqOne('yfth_hq_active_referral_current', ['id' => $relationId]);
    $assert($creationKey !== '' && hash_equals($creationKey, (string)$rawClosed['source_unique_key']), 'referral_creation_source_key_is_immutable');
    $assert((string)$closedRelation['relation']['status'] === 'closed' && (string)$closedRelation['relation']['close_reason'] === 'membership_activated', 'membership_activation_uses_relation_closed_semantics');
    $assert(hqCount('yfth_hq_active_referral_event', ['referral_current_id' => $relationId]) === 4, 'referral_current_and_event_versions_are_complete');
    $eventKeys = Db::name('yfth_hq_active_referral_event')->where('referral_current_id', $relationId)->column('source_unique_key');
    $assert(count($eventKeys) === count(array_unique($eventKeys)), 'referral_event_keys_are_separated_by_event_type');

    $reverseA = $fixture['users'][6];
    $reverseB = $fixture['users'][7];
    $assign($reverseA, $storeA, 'assign-reverse-a');
    $assign($reverseB, $storeA, 'assign-reverse-b');
    $services['referral']->create($reverseA, $reverseB, $storeA, $mutation('test_referral', 'reverse-a-b'));
    $expect(function () use ($services, $reverseA, $reverseB, $storeA, $mutation) {
        $services['referral']->create($reverseB, $reverseA, $storeA, $mutation('test_referral', 'reverse-b-a'));
    }, 'referral_direct_reverse_relation_forbidden', 'direct_reverse_referral_rejected');
    $expect(function () use ($services, $reverseA, $storeA, $mutation) {
        $services['referral']->create($reverseA, $reverseA, $storeA, $mutation('test_referral', 'self'));
    }, 'referral_self_or_invalid_relation', 'self_referral_rejected');

    $invalidRef = $fixture['users'][8];
    $invalidTarget = $fixture['users'][9];
    $assign($invalidRef, $storeA, 'assign-invalid-ref');
    $assign($invalidTarget, $storeA, 'assign-invalid-target');
    $invalidRelation = $services['referral']->create($invalidRef, $invalidTarget, $storeA, $mutation('test_referral', 'invalid-create'));
    $invalidated = $services['referral']->invalidate((int)$invalidRelation['relation']['id'], 1, 'test_invalid', $mutation('test_referral', 'invalid-transition'));
    $assert((string)$invalidated['relation']['status'] === 'invalid' && $invalidated['relation']['active_referred_uid'] === null, 'invalid_relation_releases_active_slot');

    hqAssertNullAndAuditSafety($assert, $fixture, $creationKey);
    hqRunConcurrencyMatrix($assert, $fixture, $services, $assign, $mutation, $notes);
} catch (Throwable $e) {
    $failures[] = 'real_flow_exception:' . $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
} finally {
    if ($fixture) {
        try {
            hqCleanupFixture($fixture);
            $notes[] = 'temporary_fixture_cleanup:success';
        } catch (Throwable $e) {
            $failures[] = 'fixture_cleanup_failed:' . $e->getMessage();
        }
    }
}

foreach ($notes as $note) {
    echo "[NOTE] {$note}\n";
}
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}
foreach ($passes as $pass) {
    echo "[PASS] {$pass}\n";
}
echo "[OK] YFTH headquarters authority foundation real flow verified on isolated MySQL.\n";

function hqAssertSchema(callable $assert, string $database): void
{
    foreach ([
        'yfth_hq_customer_attribution_current', 'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current', 'yfth_hq_active_referral_event',
    ] as $table) {
        $assert((int)Db::query('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$database, 'eb_' . $table])[0]['c'] === 1, 'schema_table_exists:' . $table);
    }
    $column = Db::query("SELECT CHARACTER_SET_NAME,COLLATION_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='eb_yfth_hq_customer_attribution_event' AND COLUMN_NAME='source_unique_key'", [$database])[0] ?? [];
    $assert(($column['CHARACTER_SET_NAME'] ?? '') === 'ascii' && ($column['COLLATION_NAME'] ?? '') === 'ascii_bin' && ($column['IS_NULLABLE'] ?? '') === 'YES', 'source_key_schema_is_nullable_ascii_bin');
    $attrCurrentKey = Db::query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='eb_yfth_hq_customer_attribution_current' AND COLUMN_NAME='source_unique_key'", [$database]);
    $assert((int)$attrCurrentKey[0]['c'] === 0, 'attribution_current_schema_has_no_source_key');
}

function hqSeedFixture(): array
{
    $runId = 'hqauth' . date('YmdHis') . random_int(1000, 9999);
    $users = [];
    for ($i = 0; $i < 32; $i++) {
        $users[] = (int)Db::name('user')->insertGetId([
            'account' => $runId . 'u' . $i,
            'pwd' => '', 'nickname' => 'HQ Authority Test ' . $i, 'avatar' => '', 'phone' => '',
            'add_time' => time(), 'status' => 1, 'is_del' => 0,
        ]);
    }
    $stores = [];
    for ($i = 0; $i < 3; $i++) {
        $stores[] = (int)Db::name('system_store')->insertGetId([
            'name' => $runId . '-store-' . $i, 'introduction' => '', 'phone' => '', 'address' => '',
            'detailed_address' => '', 'image' => '', 'oblong_image' => '', 'latitude' => '', 'longitude' => '',
            'valid_time' => '', 'day_time' => '', 'add_time' => time(), 'is_show' => 1, 'is_del' => 0,
        ]);
    }
    return compact('runId', 'users', 'stores') + ['run_id' => $runId];
}

function hqRunConcurrencyMatrix(callable $assert, array $fixture, array $services, callable $assign, callable $mutation, array &$notes): void
{
    $u = $fixture['users'];
    $storeA = $fixture['stores'][0];
    $storeB = $fixture['stores'][1];
    $runId = $fixture['run_id'];

    $competeUid = $u[10];
    $services['attribution']->ensurePlaceholder($competeUid);
    $pair = hqRunWorkers([
        ['assign', $competeUid, $storeA, 5001, $runId . ':compete-store-a'],
        ['assign', $competeUid, $storeB, 5002, $runId . ':compete-store-b'],
    ]);
    $notes[] = 'two_store_competition:' . json_encode($pair, JSON_UNESCAPED_UNICODE);
    $assert(hqWorkerSuccessCount($pair) === 1, 'two_store_first_attribution_has_one_winner');
    $assert(hqCount('yfth_hq_customer_attribution_event', ['uid' => $competeUid]) === 1, 'two_store_competition_writes_one_event');

    foreach ([$u[11], $u[12], $u[13]] as $index => $uid) {
        $assign($uid, $storeA, 'competition-assign-' . $index);
    }
    $pair = hqRunWorkers([
        ['referral', $u[11], $u[13], $storeA, 5101, $runId . ':ref-compete-a'],
        ['referral', $u[12], $u[13], $storeA, 5102, $runId . ':ref-compete-b'],
    ]);
    $notes[] = 'referrer_competition:' . json_encode($pair, JSON_UNESCAPED_UNICODE);
    $assert(hqWorkerSuccessCount($pair) === 1, 'two_referrers_compete_for_one_referred_has_one_winner');
    $assert(hqCount('yfth_hq_active_referral_current', ['active_referred_uid' => $u[13]]) === 1, 'active_referred_uid_unique_guard_holds');

    foreach ([$u[14], $u[15]] as $index => $uid) {
        $assign($uid, $storeA, 'cycle-assign-' . $index);
    }
    $pair = hqRunWorkers([
        ['referral', $u[14], $u[15], $storeA, 5201, $runId . ':cycle-a-b'],
        ['referral', $u[15], $u[14], $storeA, 5202, $runId . ':cycle-b-a'],
    ]);
    $notes[] = 'cycle_competition:' . json_encode($pair, JSON_UNESCAPED_UNICODE);
    $assert(hqWorkerSuccessCount($pair) === 1, 'concurrent_direct_cycle_has_one_winner');

    foreach ([$u[16], $u[17], $u[18]] as $index => $uid) {
        $assign($uid, $storeA, 'multi-assign-' . $index);
    }
    $pair = hqRunWorkers([
        ['referral', $u[16], $u[17], $storeA, 5301, $runId . ':multi-a'],
        ['referral', $u[16], $u[18], $storeA, 5302, $runId . ':multi-b'],
    ]);
    $notes[] = 'same_referrer_multiple:' . json_encode($pair, JSON_UNESCAPED_UNICODE);
    $assert(hqWorkerSuccessCount($pair) === 2, 'same_referrer_can_concurrently_bind_distinct_referred_users');

    $lockUid = $u[19];
    $services['attribution']->ensurePlaceholder($lockUid);
    $holder = hqStartWorker(['hold_attribution', $lockUid, 1400]);
    usleep(200000);
    $waiter = hqStartWorker(['assign_lock_wait', $lockUid, $storeA, 5401, $runId . ':lock-wait']);
    $waitResult = hqCollectWorker($waiter);
    $holderResult = hqCollectWorker($holder);
    $notes[] = 'lock_wait_workers:' . json_encode([$holderResult, $waitResult], JSON_UNESCAPED_UNICODE);
    $assert(!empty($waitResult['ok']) && (int)($waitResult['payload']['transaction_attempts'] ?? 0) >= 2, 'real_lock_wait_timeout_retries_same_operation');
    $idem = hqOne('yfth_idempotency_record', ['business_domain' => 'yfth_hq_authority', 'idempotency_key' => $runId . ':lock-wait']);
    $assert((int)($idem['attempt_count'] ?? 0) === 1, 'lock_wait_retry_uses_single_idempotency_begin');

    foreach ([$u[20], $u[21]] as $uid) {
        $services['attribution']->ensurePlaceholder($uid);
    }
    $pair = hqRunWorkers([
        ['deadlock_probe', $u[20], $u[21], 5501, $runId . ':deadlock-a'],
        ['deadlock_probe', $u[21], $u[20], 5502, $runId . ':deadlock-b'],
    ]);
    $notes[] = 'deadlock_workers:' . json_encode($pair, JSON_UNESCAPED_UNICODE);
    $attempts = array_map(function ($result) { return (int)($result['payload']['transaction_attempts'] ?? 0); }, $pair);
    $assert(hqWorkerSuccessCount($pair) === 2 && max($attempts) >= 2, 'real_deadlock_retried_and_both_workers_completed');
    foreach ([$runId . ':deadlock-a', $runId . ':deadlock-b'] as $idempotencyKey) {
        $row = hqOne('yfth_idempotency_record', ['business_domain' => 'yfth_hq_authority', 'idempotency_key' => $idempotencyKey]);
        $assert((int)($row['attempt_count'] ?? 0) === 1, 'deadlock_retry_single_begin:' . $idempotencyKey);
    }
    $notes[] = 'concurrency_workers_used:' . PHP_BINARY;
}

function hqAssertNullAndAuditSafety(callable $assert, array $fixture, string $knownDigest): void
{
    $base = [
        'event_no' => 'NULLA' . random_int(100000, 999999), 'attribution_current_id' => 900000001,
        'uid' => $fixture['users'][0], 'authority_version' => 1, 'event_type' => 'test_null',
        'before_store_id' => 0, 'after_store_id' => 0, 'before_status' => '', 'after_status' => '',
        'before_status_reason_code' => '', 'after_status_reason_code' => '', 'source_type' => '', 'source_id' => '',
        'source_unique_key' => null, 'operator_uid' => $fixture['users'][0], 'operator_role_code' => 'test_operator',
        'reason' => 'null_test', 'request_id' => '', 'add_time' => time(),
    ];
    Db::name('yfth_hq_customer_attribution_event')->insert($base);
    $base['event_no'] = 'NULLB' . random_int(100000, 999999);
    $base['attribution_current_id'] = 900000002;
    Db::name('yfth_hq_customer_attribution_event')->insert($base);
    $assert(hqCount('yfth_hq_customer_attribution_event', ['source_unique_key' => null]) >= 2, 'nullable_unique_source_key_allows_multiple_null_rows');
    Db::name('yfth_hq_customer_attribution_event')->whereIn('attribution_current_id', [900000001, 900000002])->delete();
    $auditRows = Db::name('yfth_audit_event')->whereIn('business_domain', ['yfth_hq_customer_attribution', 'yfth_hq_active_referral'])->select()->toArray();
    $leaked = false;
    foreach ($auditRows as $row) {
        if ($knownDigest !== '' && (strpos((string)$row['before_state'], $knownDigest) !== false || strpos((string)$row['after_state'], $knownDigest) !== false)) {
            $leaked = true;
        }
    }
    $assert(!$leaked, 'source_digest_not_written_to_general_audit');
}

function hqRunWorkers(array $commands): array
{
    $workers = array_map('hqStartWorker', $commands);
    return array_map('hqCollectWorker', $workers);
}

function hqStartWorker(array $arguments): array
{
    $ini = (string)getenv('YFTH_PHP_INI');
    $command = [PHP_BINARY];
    if ($ini !== '') {
        $command[] = '-c';
        $command[] = $ini;
    }
    $command[] = __DIR__ . '/yfth_hq_authority_foundation_concurrency_worker.php';
    foreach ($arguments as $argument) {
        $command[] = (string)$argument;
    }
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('worker_process_start_failed');
    }
    return compact('process', 'pipes', 'arguments');
}

function hqCollectWorker(array $worker): array
{
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    $decoded = json_decode(trim((string)$stdout), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'payload' => ['error' => 'worker_invalid_output', 'stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit]];
    }
    return $decoded;
}

function hqWorkerSuccessCount(array $results): int
{
    return count(array_filter($results, function ($result) { return !empty($result['ok']); }));
}

function hqOne(string $table, array $where): array
{
    $row = Db::name($table)->where($where)->find();
    return $row ?: [];
}

function hqCount(string $table, array $where): int
{
    return (int)Db::name($table)->where($where)->count();
}

function hqCleanupFixture(array $fixture): void
{
    $uids = array_map('intval', $fixture['users']);
    Db::name('yfth_hq_active_referral_event')->whereIn('referrer_uid', $uids)->delete();
    Db::name('yfth_hq_active_referral_current')->whereIn('referrer_uid', $uids)->delete();
    Db::name('yfth_hq_customer_attribution_event')->whereIn('uid', $uids)->delete();
    Db::name('yfth_hq_customer_attribution_current')->whereIn('uid', $uids)->delete();
    Db::name('yfth_audit_event')->whereIn('operator_uid', $uids)->whereIn('business_domain', ['yfth_hq_customer_attribution', 'yfth_hq_active_referral'])->delete();
    Db::name('yfth_idempotency_record')->where('business_domain', 'yfth_hq_authority')->whereLike('idempotency_key', $fixture['run_id'] . ':%')->delete();
    Db::name('user')->whereIn('uid', $uids)->delete();
    Db::name('system_store')->whereIn('id', array_map('intval', $fixture['stores']))->delete();
}
