<?php

$dsn = getenv('YFTH_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=yfth_runtime;charset=utf8mb4';
$user = getenv('YFTH_TEST_USER') ?: 'root';
$pass = getenv('YFTH_TEST_PASSWORD') ?: 'root';
$prefix = 'yfth_pkg_rt_' . bin2hex(random_bytes(4));

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$tables = [
    "{$prefix}_rule",
    "{$prefix}_binding",
    "{$prefix}_order",
    "{$prefix}_purchase",
    "{$prefix}_instance",
    "{$prefix}_plan",
    "{$prefix}_period",
    "{$prefix}_item",
    "{$prefix}_identity",
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
    $pdo->exec("CREATE TABLE `{$prefix}_rule` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL DEFAULT 0,
        version_no INT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(24) NOT NULL DEFAULT 'draft',
        package_price DECIMAL(12,2) NOT NULL DEFAULT '0.00',
        month_count INT UNSIGNED NOT NULL DEFAULT 0,
        active_key VARCHAR(191) NULL DEFAULT NULL,
        UNIQUE KEY uniq_rule_version (template_id, version_no),
        UNIQUE KEY uniq_active (active_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_binding` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        template_id INT UNSIGNED NOT NULL DEFAULT 0,
        rule_version_id INT UNSIGNED NOT NULL DEFAULT 0,
        product_id INT UNSIGNED NOT NULL DEFAULT 0,
        product_attr_unique VARCHAR(191) NOT NULL DEFAULT '',
        status VARCHAR(24) NOT NULL DEFAULT 'active',
        active_key VARCHAR(191) NULL DEFAULT NULL,
        UNIQUE KEY uniq_active (active_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_order` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_sn VARCHAR(32) NOT NULL DEFAULT '',
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        paid TINYINT UNSIGNED NOT NULL DEFAULT 0,
        pay_price DECIMAL(12,2) NOT NULL DEFAULT '0.00',
        UNIQUE KEY uniq_order_sn (order_sn)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_purchase` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        purchase_no VARCHAR(48) NOT NULL DEFAULT '',
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        order_id INT UNSIGNED NOT NULL DEFAULT 0,
        order_sn VARCHAR(32) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        refund_status VARCHAR(32) NOT NULL DEFAULT 'none',
        instance_id INT UNSIGNED NOT NULL DEFAULT 0,
        idempotency_key VARCHAR(128) NOT NULL DEFAULT '',
        UNIQUE KEY uniq_purchase_no (purchase_no),
        KEY idx_order_id (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_instance` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        instance_no VARCHAR(48) NOT NULL DEFAULT '',
        purchase_id INT UNSIGNED NOT NULL DEFAULT 0,
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        order_id INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        refund_status VARCHAR(32) NOT NULL DEFAULT 'none',
        fulfilled_count INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_purchase (purchase_id),
        UNIQUE KEY uniq_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_plan` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        package_instance_id INT UNSIGNED NOT NULL DEFAULT 0,
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        month_count INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        opened_month_no INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_instance (package_instance_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_period` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        plan_id INT UNSIGNED NOT NULL DEFAULT 0,
        package_instance_id INT UNSIGNED NOT NULL DEFAULT 0,
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        month_no INT UNSIGNED NOT NULL DEFAULT 1,
        open_at INT UNSIGNED NOT NULL DEFAULT 0,
        expire_at INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'unopened',
        total_item_count INT UNSIGNED NOT NULL DEFAULT 0,
        fulfilled_item_count INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_period_month (plan_id, month_no),
        KEY idx_open (open_at, status),
        KEY idx_expire (expire_at, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_item` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        plan_id INT UNSIGNED NOT NULL DEFAULT 0,
        period_id INT UNSIGNED NOT NULL DEFAULT 0,
        package_instance_id INT UNSIGNED NOT NULL DEFAULT 0,
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        month_no INT UNSIGNED NOT NULL DEFAULT 1,
        source_rule_id INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'unopened',
        quantity_total DECIMAL(12,2) NOT NULL DEFAULT '0.00',
        quantity_available DECIMAL(12,2) NOT NULL DEFAULT '0.00',
        quantity_used DECIMAL(12,2) NOT NULL DEFAULT '0.00',
        fulfillment_status VARCHAR(32) NOT NULL DEFAULT 'none',
        available_time INT UNSIGNED NOT NULL DEFAULT 0,
        expire_time INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_item_rule (period_id, source_rule_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE `{$prefix}_identity` (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uid INT UNSIGNED NOT NULL DEFAULT 0,
        role_code VARCHAR(32) NOT NULL DEFAULT '',
        status VARCHAR(24) NOT NULL DEFAULT 'active',
        source_type VARCHAR(32) NOT NULL DEFAULT '',
        source_id INT UNSIGNED NOT NULL DEFAULT 0,
        active_key VARCHAR(191) NULL DEFAULT NULL,
        UNIQUE KEY uniq_active (active_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $recomputeMember = function (int $uid) use ($pdo, $prefix): void {
        $activeRows = $pdo->query("SELECT id FROM `{$prefix}_instance` WHERE uid = {$uid} AND status = 'active'")->fetchAll();
        $activeIds = [];
        foreach ($activeRows as $row) {
            $activeIds[] = (int)$row['id'];
            $activeKey = $uid . ':member_5980:' . (int)$row['id'];
            $existing = $pdo->query("SELECT id FROM `{$prefix}_identity` WHERE uid = {$uid} AND role_code = 'member_5980' AND source_type = 'package_instance' AND source_id = " . (int)$row['id'] . " LIMIT 1")->fetch();
            if ($existing) {
                $pdo->exec("UPDATE `{$prefix}_identity` SET status = 'active', active_key = '{$activeKey}' WHERE id = " . (int)$existing['id']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO `{$prefix}_identity` (uid, role_code, status, source_type, source_id, active_key) VALUES (?, 'member_5980', 'active', 'package_instance', ?, ?)");
                $stmt->execute([$uid, (int)$row['id'], $activeKey]);
            }
        }

        $identityRows = $pdo->query("SELECT id, source_id FROM `{$prefix}_identity` WHERE uid = {$uid} AND role_code = 'member_5980' AND source_type = 'package_instance'")->fetchAll();
        foreach ($identityRows as $identity) {
            if (!in_array((int)$identity['source_id'], $activeIds, true)) {
                $pdo->exec("UPDATE `{$prefix}_identity` SET status = 'disabled', active_key = NULL WHERE id = " . (int)$identity['id']);
            }
        }
    };

    $activate = function (int $purchaseId) use ($pdo, $prefix, $recomputeMember): void {
        $now = time();
        $pdo->beginTransaction();
        $purchase = $pdo->query("SELECT p.*, o.paid FROM `{$prefix}_purchase` p INNER JOIN `{$prefix}_order` o ON p.order_id = o.id WHERE p.id = {$purchaseId} FOR UPDATE")->fetch();
        if (!$purchase || (int)$purchase['paid'] !== 1) {
            $pdo->rollBack();
            return;
        }

        $existing = $pdo->query("SELECT id FROM `{$prefix}_instance` WHERE purchase_id = {$purchaseId} LIMIT 1")->fetch();
        if ($existing) {
            $pdo->commit();
            $recomputeMember((int)$purchase['uid']);
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO `{$prefix}_instance` (instance_no, purchase_id, uid, order_id, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute(['PKG-' . $purchaseId, $purchaseId, (int)$purchase['uid'], (int)$purchase['order_id']]);
        $instanceId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO `{$prefix}_plan` (package_instance_id, uid, month_count, status) VALUES (?, ?, 10, 'active')");
        $stmt->execute([$instanceId, (int)$purchase['uid']]);
        $planId = (int)$pdo->lastInsertId();

        for ($month = 1; $month <= 10; $month++) {
            $openAt = $now + (($month - 1) * 86400);
            $expireAt = $openAt + 86400;
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}_period` (plan_id, package_instance_id, uid, month_no, open_at, expire_at, status, total_item_count) VALUES (?, ?, ?, ?, ?, ?, 'unopened', 1)");
            $stmt->execute([$planId, $instanceId, (int)$purchase['uid'], $month, $openAt, $expireAt]);
            $periodId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}_item` (plan_id, period_id, package_instance_id, uid, month_no, source_rule_id, status, quantity_total, available_time, expire_time) VALUES (?, ?, ?, ?, ?, ?, 'unopened', '1.00', ?, ?)");
            $stmt->execute([$planId, $periodId, $instanceId, (int)$purchase['uid'], $month, 7000 + $month, $openAt, $expireAt]);
        }

        $pdo->exec("UPDATE `{$prefix}_purchase` SET status = 'activated', instance_id = {$instanceId}, idempotency_key = 'package_activate:" . (int)$purchase['order_id'] . "' WHERE id = {$purchaseId}");
        $pdo->commit();
        $recomputeMember((int)$purchase['uid']);
    };

    $openDuePeriods = function (int $now) use ($pdo, $prefix): void {
        $pdo->exec("UPDATE `{$prefix}_period` SET status = 'available' WHERE status = 'unopened' AND open_at <= {$now}");
        $pdo->exec("UPDATE `{$prefix}_item` i INNER JOIN `{$prefix}_period` p ON i.period_id = p.id SET i.status = 'available', i.quantity_available = i.quantity_total WHERE i.status = 'unopened' AND p.status = 'available' AND i.available_time <= {$now}");
        $pdo->exec("UPDATE `{$prefix}_item` i INNER JOIN `{$prefix}_period` p ON i.period_id = p.id SET i.status = 'expired', i.quantity_available = '0.00' WHERE i.status = 'available' AND p.status = 'available' AND i.expire_time > 0 AND i.expire_time <= {$now} AND i.quantity_used <= 0");
        $pdo->exec("UPDATE `{$prefix}_period` SET status = 'expired' WHERE status = 'available' AND expire_at > 0 AND expire_at <= {$now}");
    };

    $refundSucceeded = function (int $purchaseId) use ($pdo, $prefix, $recomputeMember): void {
        $purchase = $pdo->query("SELECT * FROM `{$prefix}_purchase` WHERE id = {$purchaseId}")->fetch();
        if (!$purchase) {
            return;
        }
        $instance = $pdo->query("SELECT * FROM `{$prefix}_instance` WHERE purchase_id = {$purchaseId} LIMIT 1")->fetch();
        if (!$instance) {
            $pdo->exec("UPDATE `{$prefix}_purchase` SET status = 'refunded', refund_status = 'succeeded' WHERE id = {$purchaseId}");
            return;
        }

        $used = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_item` WHERE package_instance_id = " . (int)$instance['id'] . " AND (quantity_used > 0 OR fulfillment_status <> 'none')")->fetchColumn();
        if ($used > 0) {
            $pdo->exec("UPDATE `{$prefix}_instance` SET status = 'closed', refund_status = 'partial_refunded', fulfilled_count = {$used} WHERE id = " . (int)$instance['id']);
            $pdo->exec("UPDATE `{$prefix}_plan` SET status = 'closed' WHERE package_instance_id = " . (int)$instance['id']);
            $pdo->exec("UPDATE `{$prefix}_period` SET status = 'closed' WHERE package_instance_id = " . (int)$instance['id']);
            $pdo->exec("UPDATE `{$prefix}_item` SET status = 'closed' WHERE package_instance_id = " . (int)$instance['id'] . " AND status <> 'used'");
            $pdo->exec("UPDATE `{$prefix}_purchase` SET status = 'closed', refund_status = 'partial_refunded' WHERE id = {$purchaseId}");
        } else {
            $pdo->exec("UPDATE `{$prefix}_instance` SET status = 'refunded', refund_status = 'succeeded' WHERE id = " . (int)$instance['id']);
            $pdo->exec("UPDATE `{$prefix}_plan` SET status = 'refunded' WHERE package_instance_id = " . (int)$instance['id']);
            $pdo->exec("UPDATE `{$prefix}_period` SET status = 'refunded' WHERE package_instance_id = " . (int)$instance['id']);
            $pdo->exec("UPDATE `{$prefix}_item` SET status = 'refunded' WHERE package_instance_id = " . (int)$instance['id']);
            $pdo->exec("UPDATE `{$prefix}_purchase` SET status = 'refunded', refund_status = 'succeeded' WHERE id = {$purchaseId}");
        }

        $recomputeMember((int)$purchase['uid']);
    };

    $pdo->exec("INSERT INTO `{$prefix}_rule` (template_id, version_no, status, package_price, month_count, active_key) VALUES (1, 1, 'published', '5980.00', 10, '1:published')");
    $expectDuplicate(function () use ($pdo, $prefix) {
        $pdo->exec("INSERT INTO `{$prefix}_rule` (template_id, version_no, status, package_price, month_count, active_key) VALUES (1, 2, 'published', '5980.00', 10, '1:published')");
    }, 'only one published rule may be active per package template');
    $pdo->exec("INSERT INTO `{$prefix}_rule` (template_id, version_no, status, package_price, month_count, active_key) VALUES (1, 2, 'draft', '5980.00', 10, NULL)");

    $pdo->exec("INSERT INTO `{$prefix}_binding` (template_id, rule_version_id, product_id, product_attr_unique, status, active_key) VALUES (1, 1, 8801, 'sku-5980', 'active', '1:8801:sku-5980')");
    $expectDuplicate(function () use ($pdo, $prefix) {
        $pdo->exec("INSERT INTO `{$prefix}_binding` (template_id, rule_version_id, product_id, product_attr_unique, status, active_key) VALUES (1, 1, 8801, 'sku-5980', 'active', '1:8801:sku-5980')");
    }, 'only one active product/SKU binding may exist for the package');

    $pdo->exec("INSERT INTO `{$prefix}_order` (id, order_sn, uid, paid, pay_price) VALUES (1001, 'ORDER1001', 501, 1, '5980.00')");
    $pdo->exec("INSERT INTO `{$prefix}_purchase` (id, purchase_no, uid, order_id, order_sn, status) VALUES (1, 'P1001', 501, 1001, 'ORDER1001', 'pending')");
    $activate(1);
    $activate(1);

    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_instance` WHERE purchase_id = 1")->fetchColumn() === 1, 'activation replay must not create duplicate package instance');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_plan`")->fetchColumn() === 1, 'activation replay must not create duplicate benefit plan');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_period`")->fetchColumn() === 10, 'activation must create exactly ten monthly periods once');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_item`")->fetchColumn() === 10, 'activation must create monthly benefit items once');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_identity` WHERE uid = 501 AND role_code = 'member_5980' AND status = 'active'")->fetchColumn() === 1, 'activation must grant active member_5980 identity');

    $expectDuplicate(function () use ($pdo, $prefix) {
        $pdo->exec("INSERT INTO `{$prefix}_instance` (instance_no, purchase_id, uid, order_id, status) VALUES ('DUP', 1, 501, 1001, 'active')");
    }, 'one purchase/order must not activate more than one package instance');
    $expectDuplicate(function () use ($pdo, $prefix) {
        $pdo->exec("INSERT INTO `{$prefix}_period` (plan_id, package_instance_id, uid, month_no, open_at, expire_at, status) VALUES (1, 1, 501, 1, 1, 2, 'unopened')");
    }, 'one benefit plan must not duplicate a month period');
    $expectDuplicate(function () use ($pdo, $prefix) {
        $pdo->exec("INSERT INTO `{$prefix}_item` (plan_id, period_id, package_instance_id, uid, month_no, source_rule_id, status) VALUES (1, 1, 1, 501, 1, 7001, 'unopened')");
    }, 'one period must not duplicate a source monthly rule item');

    $now = time();
    $pdo->exec("UPDATE `{$prefix}_period` SET open_at = " . ($now - 10) . ", expire_at = " . ($now + 100) . " WHERE plan_id = 1 AND month_no = 1");
    $pdo->exec("UPDATE `{$prefix}_item` SET available_time = " . ($now - 10) . ", expire_time = " . ($now + 100) . " WHERE plan_id = 1 AND month_no = 1");
    $openDuePeriods($now);
    $openDuePeriods($now);
    $assert($pdo->query("SELECT status FROM `{$prefix}_period` WHERE plan_id = 1 AND month_no = 1")->fetchColumn() === 'available', 'due unopened period must become available');
    $assert($pdo->query("SELECT status FROM `{$prefix}_item` WHERE plan_id = 1 AND month_no = 1")->fetchColumn() === 'available', 'due unopened item must become available');
    $pdo->exec("UPDATE `{$prefix}_period` SET expire_at = " . ($now - 1) . " WHERE plan_id = 1 AND month_no = 1");
    $pdo->exec("UPDATE `{$prefix}_item` SET expire_time = " . ($now - 1) . " WHERE plan_id = 1 AND month_no = 1");
    $openDuePeriods($now);
    $assert($pdo->query("SELECT status FROM `{$prefix}_period` WHERE plan_id = 1 AND month_no = 1")->fetchColumn() === 'expired', 'available period must expire after expire_at');
    $assert($pdo->query("SELECT status FROM `{$prefix}_item` WHERE plan_id = 1 AND month_no = 1")->fetchColumn() === 'expired', 'available item must expire after expire_time');

    $pdo->exec("UPDATE `{$prefix}_period` SET status = 'unopened', open_at = " . ($now - 10) . ", expire_at = " . ($now + 1000) . " WHERE plan_id = 1 AND month_no = 2");
    $pdo->exec("UPDATE `{$prefix}_item` SET status = 'unopened', quantity_available = '0.00', available_time = " . ($now + 100) . ", expire_time = " . ($now + 200) . " WHERE plan_id = 1 AND month_no = 2");
    $openDuePeriods($now);
    $assert($pdo->query("SELECT status FROM `{$prefix}_period` WHERE plan_id = 1 AND month_no = 2")->fetchColumn() === 'available', 'period may open before delayed item availability');
    $assert($pdo->query("SELECT status FROM `{$prefix}_item` WHERE plan_id = 1 AND month_no = 2")->fetchColumn() === 'unopened', 'delayed item must remain unopened until its available_time');
    $openDuePeriods($now + 101);
    $assert($pdo->query("SELECT status FROM `{$prefix}_item` WHERE plan_id = 1 AND month_no = 2")->fetchColumn() === 'available', 'delayed item must become available after its available_time even if period is already open');
    $openDuePeriods($now + 201);
    $assert($pdo->query("SELECT status FROM `{$prefix}_period` WHERE plan_id = 1 AND month_no = 2")->fetchColumn() === 'available', 'item-level expiry must not close a still-valid period');
    $assert($pdo->query("SELECT status FROM `{$prefix}_item` WHERE plan_id = 1 AND month_no = 2")->fetchColumn() === 'expired', 'available item must expire by its own expire_time before period expiry');

    $pdo->exec("INSERT INTO `{$prefix}_order` (id, order_sn, uid, paid, pay_price) VALUES (1002, 'ORDER1002', 501, 1, '5980.00')");
    $pdo->exec("INSERT INTO `{$prefix}_purchase` (id, purchase_no, uid, order_id, order_sn, status) VALUES (2, 'P1002', 501, 1002, 'ORDER1002', 'pending')");
    $refundSucceeded(2);
    $assert($pdo->query("SELECT status FROM `{$prefix}_purchase` WHERE id = 2")->fetchColumn() === 'refunded', 'unactivated refunded package purchase must close without instance');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_instance` WHERE purchase_id = 2")->fetchColumn() === 0, 'unactivated refunded purchase must not create package instance');

    $pdo->exec("INSERT INTO `{$prefix}_order` (id, order_sn, uid, paid, pay_price) VALUES (1003, 'ORDER1003', 501, 1, '5980.00')");
    $pdo->exec("INSERT INTO `{$prefix}_purchase` (id, purchase_no, uid, order_id, order_sn, status) VALUES (3, 'P1003', 501, 1003, 'ORDER1003', 'pending')");
    $activate(3);
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_identity` WHERE uid = 501 AND role_code = 'member_5980' AND status = 'active'")->fetchColumn() === 2, 'multiple active package instances must keep member identities active');

    $refundSucceeded(1);
    $assert($pdo->query("SELECT status FROM `{$prefix}_instance` WHERE purchase_id = 1")->fetchColumn() === 'refunded', 'activated package without fulfillment must refund instance');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_identity` WHERE uid = 501 AND role_code = 'member_5980' AND status = 'active'")->fetchColumn() === 1, 'refunding one package must not remove member identity from another active package');

    $instanceId = (int)$pdo->query("SELECT id FROM `{$prefix}_instance` WHERE purchase_id = 3")->fetchColumn();
    $pdo->exec("UPDATE `{$prefix}_item` SET quantity_used = '1.00', fulfillment_status = 'used', status = 'used' WHERE package_instance_id = {$instanceId} AND month_no = 1");
    $refundSucceeded(3);
    $assert($pdo->query("SELECT status FROM `{$prefix}_instance` WHERE purchase_id = 3")->fetchColumn() === 'closed', 'fulfilled package refund must close instance instead of deleting history');
    $assert($pdo->query("SELECT refund_status FROM `{$prefix}_purchase` WHERE id = 3")->fetchColumn() === 'partial_refunded', 'fulfilled package refund must be marked partial');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}_identity` WHERE uid = 501 AND role_code = 'member_5980' AND status = 'active'")->fetchColumn() === 0, 'member_5980 identity must be disabled after all package instances are inactive');

    if ($failures) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "[FAIL] {$failure}\n");
        }
        exit(1);
    }

    echo "[OK] YFTH package benefit runtime MySQL checks verified.\n";
} finally {
    foreach (array_reverse($tables) as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        } catch (Throwable $e) {
        }
    }
}
