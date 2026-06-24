<?php

$dsn = getenv('YFTH_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=yfth_runtime;charset=utf8mb4';
$user = getenv('YFTH_TEST_USER') ?: 'root';
$pass = getenv('YFTH_TEST_PASSWORD') ?: 'root';
$prefix = 'yfth_rt_' . bin2hex(random_bytes(4));

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables = [
    "{$prefix}_store_subject",
    "{$prefix}_payment_route",
    "{$prefix}_idempotency",
    "{$prefix}_order",
    "{$prefix}_side_effect",
];

$failures = [];

$assert = function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expectDuplicate = function (callable $callback, string $message) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000' && strpos($e->getMessage(), '1062') === false) {
            $failures[] = $message . ': unexpected error ' . $e->getMessage();
        }
    }
};

try {
    $pdo->exec("CREATE TABLE `{$prefix}_store_subject` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        store_id INT UNSIGNED NOT NULL DEFAULT 0,
        subject_id INT UNSIGNED NOT NULL DEFAULT 0,
        subject_role VARCHAR(32) NOT NULL DEFAULT '',
        status VARCHAR(24) NOT NULL DEFAULT 'active',
        active_key VARCHAR(191) NULL DEFAULT NULL,
        UNIQUE KEY uniq_active (active_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_payment_route` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        store_id INT UNSIGNED NOT NULL DEFAULT 0,
        business_scene VARCHAR(48) NOT NULL DEFAULT '',
        version_no INT UNSIGNED NOT NULL DEFAULT 1,
        priority INT NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'active',
        active_key VARCHAR(191) NULL DEFAULT NULL,
        UNIQUE KEY uniq_active (active_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_idempotency` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        business_domain VARCHAR(48) NOT NULL DEFAULT '',
        action_type VARCHAR(64) NOT NULL DEFAULT '',
        idempotency_key VARCHAR(128) NOT NULL DEFAULT '',
        request_hash CHAR(64) NOT NULL DEFAULT '',
        process_status VARCHAR(24) NOT NULL DEFAULT 'processing',
        result_summary TEXT NULL,
        expire_time INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_key (business_domain, action_type, idempotency_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_order` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        verify_code VARCHAR(64) NOT NULL DEFAULT '',
        status TINYINT NOT NULL DEFAULT 0,
        paid TINYINT NOT NULL DEFAULT 1,
        store_id INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_verify_code (verify_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_side_effect` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL DEFAULT 0,
        effect_type VARCHAR(32) NOT NULL DEFAULT '',
        UNIQUE KEY uniq_effect (order_id, effect_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT INTO `{$prefix}_store_subject` (store_id, subject_id, subject_role, status, active_key) VALUES (1, 101, 'sales', 'active', '1:sales')");
    $expectDuplicate(function () use ($pdo, $prefix) {
        $pdo->exec("INSERT INTO `{$prefix}_store_subject` (store_id, subject_id, subject_role, status, active_key) VALUES (1, 102, 'sales', 'active', '1:sales')");
    }, 'store subject must be unique by store_id + subject_role');
    $pdo->exec("INSERT INTO `{$prefix}_store_subject` (store_id, subject_id, subject_role, status, active_key) VALUES (1, 102, 'sales', 'disabled', NULL)");

    $pdo->exec("INSERT INTO `{$prefix}_payment_route` (store_id, business_scene, version_no, priority, status, active_key) VALUES (1, 'store_retail', 1, 0, 'active', '1:store_retail')");
    $expectDuplicate(function () use ($pdo, $prefix) {
        $pdo->exec("INSERT INTO `{$prefix}_payment_route` (store_id, business_scene, version_no, priority, status, active_key) VALUES (1, 'store_retail', 2, 10, 'active', '1:store_retail')");
    }, 'payment route must be unique by store_id + business_scene');
    $route = $pdo->query("SELECT id, version_no FROM `{$prefix}_payment_route` WHERE store_id = 1 AND business_scene = 'store_retail' AND status = 'active' ORDER BY priority DESC, version_no DESC, id DESC LIMIT 1")->fetch();
    $assert((int)$route['version_no'] === 1, 'resolveRoute must be deterministic for one active route');

    $hash = hash('sha256', json_encode(['order_id' => 1001]));
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}_idempotency` (business_domain, action_type, idempotency_key, request_hash, process_status, expire_time) VALUES (?, ?, ?, ?, 'processing', ?)");
    $stmt->execute(['order', 'writeoff', 'idem-1', $hash, time() + 60]);
    $expectDuplicate(function () use ($stmt, $hash) {
        $stmt->execute(['order', 'writeoff', 'idem-1', $hash, time() + 60]);
    }, 'idempotency begin must rely on insert-first unique conflict');
    $pdo->exec("UPDATE `{$prefix}_idempotency` SET process_status = 'succeeded', result_summary = '{\"ok\":true}' WHERE id = 1");
    $idem = $pdo->query("SELECT process_status, result_summary FROM `{$prefix}_idempotency` WHERE business_domain = 'order' AND action_type = 'writeoff' AND idempotency_key = 'idem-1'")->fetch();
    $assert($idem['process_status'] === 'succeeded', 'idempotency replay must see succeeded status');

    $pdo->exec("INSERT INTO `{$prefix}_order` (verify_code, status, paid, store_id) VALUES ('code-1', 0, 1, 9)");
    for ($i = 0; $i < 2; $i++) {
        $pdo->beginTransaction();
        $order = $pdo->query("SELECT * FROM `{$prefix}_order` WHERE verify_code = 'code-1' FOR UPDATE")->fetch();
        if ((int)$order['status'] !== 2) {
            $pdo->exec("UPDATE `{$prefix}_order` SET status = 2 WHERE id = " . (int)$order['id']);
            $pdo->exec("INSERT INTO `{$prefix}_side_effect` (order_id, effect_type) VALUES (" . (int)$order['id'] . ", 'take_delivery')");
        }
        $pdo->commit();
    }
    $effects = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_side_effect` WHERE order_id = 1")->fetchColumn();
    $assert($effects === 1, 'writeoff side effects must run once under row lock');

    if ($failures) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "[FAIL] {$failure}\n");
        }
        exit(1);
    }

    echo "[OK] YFTH runtime MySQL checks verified.\n";
} finally {
    foreach (array_reverse($tables) as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        } catch (Throwable $e) {
        }
    }
}
