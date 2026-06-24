<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
$failures = [];

$read = function (string $path) use ($root, $projectRoot): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($full)) {
        return file_get_contents($full);
    }
    $projectFull = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    return is_file($projectFull) ? file_get_contents($projectFull) : '';
};

$assertContains = function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) === false) {
        $failures[] = $message;
    }
};

$assertNotContains = function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) !== false) {
        $failures[] = $message;
    }
};

$migration = $read('database/migrations/20260624130000_create_yfth_package_benefit_tables.php');
$hardeningMigration = $read('database/migrations/20260624170000_harden_yfth_package_purchase_snapshots.php');
$menuMigration = $read('database/migrations/20260624130010_seed_yfth_package_benefit_menus.php');
$recoveryMenuMigration = $read('database/migrations/20260624170010_seed_yfth_package_recovery_menus.php');
$event = $read('app/event.php');
$activation = $read('app/services/yfth/PackageActivationServices.php');
$recovery = $read('app/services/yfth/PackageActivationRecoveryServices.php');
$purchase = $read('app/services/yfth/PackagePurchaseServices.php');
$instance = $read('app/services/yfth/PackageInstanceServices.php');
$period = $read('app/services/yfth/BenefitPeriodServices.php');
$refund = $read('app/services/yfth/PackageRefundServices.php');
$template = $read('app/services/yfth/PackageTemplateServices.php');
$stateMachine = $read('app/services/yfth/PackageBenefitStateMachine.php');
$command = $read('crmeb/command/YfthPackage.php');
$console = $read('config/console.php');
$apiRoutes = $read('app/api/route/v1.php');
$adminRoutes = $read('app/adminapi/route/yfth.php');
$adminPage = $read('template/admin/src/pages/yfth/packageBenefit/index.vue');
$adminApi = $read('template/admin/src/api/yfth.js');
$mobileApi = $read('template/uni-app/api/yfth.js');
$paymentConfirm = $read('template/uni-app/pages/yfth/package/payment_confirm.vue');
$pagesJson = $read('template/uni-app/pages.json');
$userModel = $read('app/model/user/User.php');

foreach ([
    'yfth_package_template',
    'yfth_package_rule_version',
    'yfth_package_product_binding',
    'yfth_package_agreement_snapshot',
    'yfth_package_purchase',
    'yfth_package_instance',
    'yfth_benefit_template',
    'yfth_monthly_benefit_rule',
    'yfth_benefit_plan',
    'yfth_benefit_period',
    'yfth_benefit_item',
] as $table) {
    $assertContains($migration, $table, "Missing package benefit migration table: {$table}");
}

foreach ([
    'yfth_package_purchase_intent',
    'yfth_package_purchase_snapshot',
    'yfth_package_purchase_benefit_snapshot',
] as $table) {
    $assertContains($hardeningMigration, $table, "Missing hardening snapshot/intent table: {$table}");
}

foreach ([
    'uniq_yfth_pkg_rule_active',
    'uniq_yfth_pkg_bind_active',
    'uniq_yfth_pkg_instance_purchase',
    'uniq_yfth_benefit_period_month',
    'uniq_yfth_benefit_item_rule',
] as $index) {
    $assertContains($migration, $index, "Missing package benefit unique/index contract: {$index}");
}

foreach ([
    'uniq_yfth_pkg_purchase_order_key',
    'uniq_yfth_pkg_purchase_order_sn_key',
    'uniq_yfth_pkg_snapshot_purchase',
    'uniq_yfth_pkg_benefit_snapshot_rule',
    'idx_yfth_benefit_period_open_guard',
    'idx_yfth_benefit_period_expire_guard',
] as $index) {
    $assertContains($hardeningMigration, $index, "Missing hardening unique/index contract: {$index}");
}

$assertContains($menuMigration, 'yfth-package-benefit-index', 'Missing package benefit admin page menu permission');
$assertContains($menuMigration, 'upsertMenu', 'Package benefit menu seed must be idempotent');
$assertContains($menuMigration, "'pid' => \$rootId", 'Package benefit page must be parented under YFTH root');
$assertContains($menuMigration, "'pid' => \$pid", 'Package benefit API permissions must be parented under page menu');
$assertContains($recoveryMenuMigration, 'yfth-package-activation-recover', 'Missing activation recovery menu permission');
$assertContains($recoveryMenuMigration, 'yfth-package-activation-retry', 'Missing activation retry menu permission');
$assertContains($recoveryMenuMigration, 'yfth-package-rule-copy', 'Missing package rule copy menu permission');

foreach ([
    'package_code',
    'benefit_months',
    'package_instance_id',
    'member_5980',
] as $forbiddenUserField) {
    $assertNotContains($userModel, $forbiddenUserField, "Package benefit must not add user field: {$forbiddenUserField}");
}

$assertContains($activation, 'IdempotencyRecordServices::class', 'Activation must use foundation idempotency service');
$assertContains($activation, 'tryReacquire', 'Activation must reacquire failed/expired idempotency records');
$assertContains($activation, "'package_activate:'", 'Activation must use package_activate idempotency key');
$assertContains($activation, 'lock(true)', 'Activation must lock package purchase row');
$assertContains($activation, 'createPlanAndBenefitsFromSnapshot', 'Paid package activation must create benefit plan and items from snapshots');
$assertContains($activation, 'YfthPackagePurchaseSnapshotDao::class', 'Activation must read package purchase snapshot');
$assertContains($activation, 'YfthPackagePurchaseBenefitSnapshotDao::class', 'Activation must read benefit relational snapshots');
$assertContains($activation, 'YfthBenefitPlanDao::class', 'Paid package activation must create benefit plan');
$assertContains($activation, 'YfthBenefitPeriodDao::class', 'Paid package activation must create monthly periods');
$assertContains($activation, 'YfthBenefitItemDao::class', 'Paid package activation must create benefit items');
$assertContains($activation, 'recomputeMemberIdentity', 'Activation must recompute member_5980 identity');
$assertNotContains($activation, 'PackageTemplateServices::class', 'Activation must not read live package templates');
$assertNotContains($activation, 'BenefitTemplateServices::class', 'Activation must not read live benefit templates');
$assertContains($purchase, "package_5980", 'Purchase validation must verify package payment route scene');
$assertContains($purchase, 'createIntent', 'Purchase service must create package purchase intents');
$assertContains($purchase, 'createOrderFromIntent', 'Purchase service must create CRMEB orders from intents');
$assertContains($purchase, 'StoreOrderCreateServices::class', 'Purchase service must use CRMEB order creation service');
$assertContains($purchase, 'savePurchaseResolvingOrderConflict', 'Purchase creation must resolve DB unique order conflicts');
$assertContains($purchase, 'createPurchaseSnapshots', 'Purchase service must create relational purchase snapshots');
$assertContains($purchase, 'assertOrderMatchesPackage', 'Purchase binding must verify CRMEB order product/SKU/amount');
$assertContains($purchase, 'agreement_snapshot_id', 'Purchase must keep accepted agreement snapshot');
$assertContains($purchase, 'benefit_hash', 'Purchase validation must detect benefit rule hash mismatch');
$assertContains($recovery, 'recoverPaidUnactivated', 'Missing paid-but-unactivated compensation scan service');
$assertContains($recovery, 'retryPurchase', 'Missing manual activation retry service');
$assertContains($recovery, 'PackageActivationServices::class', 'Recovery must reuse activation service');
$assertContains($command, 'recover-activation', 'Missing console recovery action');
$assertContains($console, 'yfth:package', 'Missing console command registration');
$assertContains($template, 'agreement_content_hash', 'Rule version must snapshot agreement hash');
$assertContains($template, 'copyRuleVersion', 'Published/referenced rule must support copy-as-new-version');
$assertContains($template, "unset(\$row['agreement_content'])", 'Public template output must not expose full agreement content');
$assertContains($template, "unset(\$data['agreement_content'])", 'Rule version save must not write agreement content into rule table');
$assertContains($instance, 'member_5980', 'Package instance service must manage member_5980 identity');
$assertContains($instance, "'source_type' => 'package_instance'", 'member_5980 identity source must be package instance');
$assertContains($period, 'openDuePeriods', 'Benefit period service must expose due opening job');
$assertContains($period, 'isPeriodOpenable', 'Benefit period opening must validate plan/instance active status');
$assertContains($period, 'YfthBenefitPlanDao::class', 'Benefit period opening must read benefit plan status');
$assertContains($period, 'YfthPackageInstanceDao::class', 'Benefit period opening must read package instance status');
$assertContains($period, 'limit($limit)', 'Benefit period opening must be batch-limited');
$assertContains($period, 'expire_at', 'Benefit period service must expire due benefits');
$assertContains($refund, 'onRefundSucceeded', 'Refund service must sync refund success');
$assertContains($refund, 'appendRefundRecordCandidates', 'Refund service must resolve original order from refund record');
$assertContains($refund, 'store_order_id', 'Refund service must use real CRMEB original order id');
$assertContains($refund . $read('app/services/yfth/PackageLifecycleServices.php'), 'quantity_used', 'Refund lifecycle must distinguish fulfilled benefits');
$assertContains($stateMachine, 'closed_after_partial_refund', 'State machine must distinguish partial-fulfillment refund closure');
$assertContains($stateMachine, "'purchase' =>", 'State machine must centralize purchase transitions');
$assertContains($stateMachine, "'instance' =>", 'State machine must centralize instance transitions');
$assertContains($stateMachine, "'period' =>", 'State machine must centralize period transitions');
$assertContains($stateMachine, "'item' =>", 'State machine must centralize item transitions');
$assertContains($event, 'PackagePaySuccessListener::class', 'Order pay success event must attach package activation listener');
$assertContains($event, 'PackageRefundApplyListener::class', 'Refund apply event must attach package sync listener');
$assertContains($event, 'PackageRefundCancelListener::class', 'Refund cancel event must attach package sync listener');
$assertContains($event, 'PackageCustomEventListener::class', 'Custom refund success/fail events must attach package listener');

foreach (['order_remark', 'user_money', 'brokerage_price', 'pay_integral'] as $forbiddenStorage) {
    $assertNotContains($activation . $purchase . $period . $refund, $forbiddenStorage, "Package benefits must not be stored in CRMEB side field: {$forbiddenStorage}");
}

foreach ([
    "yfth/package/purchase",
    "yfth/package/intent",
    "yfth/package/order",
    "yfth/package/my",
    "yfth/package/plan/:instanceId",
    "yfth/package/current_benefits",
    "yfth/package/agreement/:purchaseNo",
    "yfth/package/list",
    "yfth/package/detail/:id",
    "yfth/package/service_stores/:id",
    "yfth/package/rule_preview/:id",
] as $route) {
    $assertContains($apiRoutes, $route, "Missing mobile/API route: {$route}");
}

foreach ([
    "template/save",
    "rule/save",
    "binding/save",
    "monthly_rule/save",
    "instance/:id/state",
    "instance/:id/lifecycle",
    "period/open_due",
    "activation/recover",
    "purchase/:id/activation_retry",
    "rule/:id/copy",
] as $route) {
    $assertContains($adminRoutes, $route, "Missing admin package benefit route: {$route}");
}

foreach ([
    'yfthPackageTemplateList',
    'yfthPackageRuleSave',
    'yfthPackageBindingSave',
    'yfthMonthlyRuleSave',
    'yfthPackageInstanceState',
    'yfthPackageInstanceLifecycle',
    'yfthPackageActivationRecover',
    'yfthPackageActivationRetry',
    'yfthPackageRuleCopy',
] as $method) {
    $assertContains($adminApi . $adminPage, $method, "Missing admin package benefit UI/API method: {$method}");
}

foreach ([
    'getYfthPackageDetail',
    'createYfthPackageIntent',
    'createYfthPackageOrder',
    'createYfthPackagePurchase',
    'getYfthAgreementRecord',
    'getYfthMyPackages',
    'getYfthTimeline',
    'getYfthCurrentBenefits',
] as $method) {
    $assertContains($mobileApi, $method, "Missing mobile package benefit API method: {$method}");
}

$assertContains($paymentConfirm, 'createYfthPackageIntent', 'Payment confirm page must create package intent');
$assertContains($paymentConfirm, 'createYfthPackageOrder', 'Payment confirm page must create real CRMEB order from intent');
foreach ([
    'manualOrder',
    'productIdInput',
    'skuUniqueInput',
    'order_sn_input',
] as $forbiddenInput) {
    $assertNotContains($paymentConfirm, $forbiddenInput, "Payment confirm page must not keep manual input: {$forbiddenInput}");
}

foreach ([
    'pages/yfth',
    'package/detail',
    'package/store_select',
    'package/agreement_confirm',
    'package/payment_confirm',
    'package/payment_result',
    'package/my_packages',
    'package/package_detail',
    'package/timeline',
    'package/current_month',
] as $page) {
    $assertContains($pagesJson, $page, "Missing uni-app package page registration: {$page}");
}

foreach ([
    'docs/YFTH_PACKAGE_BENEFIT_ARCHITECTURE.md',
    'docs/YFTH_PACKAGE_BENEFIT_DATA_MODEL.md',
    'docs/YFTH_PACKAGE_BENEFIT_STATE_MACHINE.md',
] as $doc) {
    if (!is_file(dirname($root) . DIRECTORY_SEPARATOR . $doc)) {
        $failures[] = "Missing package benefit document: {$doc}";
    }
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

echo "[OK] YFTH package benefit contracts verified.\n";
