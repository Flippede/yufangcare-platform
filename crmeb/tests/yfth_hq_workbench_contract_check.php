<?php

$projectRoot = dirname(__DIR__);
$repoRoot = dirname($projectRoot);
$failures = [];

$read = function (string $path) use ($projectRoot, $repoRoot): string {
    $relative = str_replace('/', DIRECTORY_SEPARATOR, $path);
    $full = $projectRoot . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($full)) {
        $full = $repoRoot . DIRECTORY_SEPARATOR . $relative;
    }
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

$assertContains = function (string $haystack, string $needle, string $label) use (&$failures): void {
    if (strpos($haystack, $needle) === false) {
        $failures[] = $label;
        echo "[FAIL] {$label}\n";
    } else {
        echo "[PASS] {$label}\n";
    }
};

try {
    $controller = $read('app/adminapi/controller/Common.php');
    $route = $read('app/adminapi/route/common.php');
    $migration = $read('database/migrations/20260704150000_add_yfth_hq_workbench_permission.php');
    $handoff = $read('docs/PROJECT_HANDOFF.md');
    $architecture = $read('docs/YFTH_PRODUCT_SURFACE_ARCHITECTURE.md');

    $assertContains($route, "Route::get('home/yfth'", 'route_registers_home_yfth');
    $assertContains($controller, "\$this->assertAdminApiAuth('home/yfth', 'GET')", 'workbench_forces_depth_permission');
    $assertContains($controller, 'SystemRoleServices', 'workbench_reuses_system_role_services');
    $assertContains($controller, 'safePaidOrderMetrics($todayStart, $todayEnd)', 'workbench_uses_shared_paid_order_metrics');
    $assertContains($controller, "workbenchCard('today_orders', '今日支付订单'", 'workbench_card_title_paid_orders');
    $assertContains($controller, "workbenchCard('today_paid_amount', '今日成交金额'", 'workbench_card_title_paid_amount');
    $assertContains($controller, "workbenchCard('pending_franchise_applications', '待确认加盟申请'", 'workbench_counts_pending_franchise_applications');
    $assertContains($controller, "'title' => '待确认加盟申请'", 'workbench_exposes_pending_franchise_todo');
    $assertContains($controller, "'count' => \$pendingFranchiseApplications", 'workbench_todo_reuses_pending_review_count');
    $assertContains($controller, "quickLink('总部加盟申请', '/yfth/franchise-application'", 'workbench_exposes_franchise_application_link');
    $assertContains($controller, "->whereBetween('pay_time', [\$start, \$end])", 'paid_order_metrics_use_pay_time_range');
    foreach (["'paid' => 1", "'refund_status' => 0", "'pid' => 0", "'is_del' => 0"] as $needle) {
        $assertContains($controller, $needle, 'paid_order_filter_contains:' . $needle);
    }

    foreach ([
        'yfth-hq-workbench-read',
        '查看总部经营工作台',
        'home/yfth',
        "'methods' => 'GET'",
        "'auth_type' => 2",
        'admin-home',
        'DELETE FROM',
    ] as $needle) {
        $assertContains($migration, $needle, 'permission_migration_contains:' . $needle);
    }

    $assertContains($handoff, 'yfth-hq-workbench-read', 'handoff_mentions_hq_workbench_permission');
    $assertContains($architecture, 'yfth-hq-workbench-read', 'architecture_mentions_hq_workbench_permission');
} catch (Throwable $e) {
    $failures[] = 'contract_exception:' . $e->getMessage();
    echo "[FAIL] contract_exception:" . $e->getMessage() . "\n";
}

if ($failures) {
    exit(1);
}

echo "[OK] YFTH headquarters workbench contract checks passed.\n";
