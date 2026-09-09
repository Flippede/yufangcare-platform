<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];

$assert = function ($condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $message;
        return;
    }
    $failures[] = $message;
};

$read = function (string $path) use ($root): string {
    return (string)file_get_contents($root . DIRECTORY_SEPARATOR . $path);
};

foreach ([
    'database/migrations/20260909100000_create_ylz_franchise_portal_profiles.php',
    'app/services/yfth/FranchiseApplicationServices.php',
    'app/api/controller/v1/yfth/FranchiseApplicationController.php',
    'app/api/route/yfth_service.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$migration = $read('database/migrations/20260909100000_create_ylz_franchise_portal_profiles.php');
foreach ([
    'yfth_franchise_application_profile',
    'yfth_franchise_application_attachment',
    'uniq_yfth_franchise_profile_application',
    'uniq_yfth_franchise_attachment_object',
    'privacy_agreed_time',
    'submit_snapshot',
    "storage_disk', 'string'",
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}

$service = $read('app/services/yfth/FranchiseApplicationServices.php');
foreach ([
    'public function portalDraft(',
    'public function savePortalDraft(',
    'public function submitPortal(',
    "'source' => 'ylz_franchise_portal'",
    "->where('status', 'draft')",
    "->whereIn('status', ['submitted'",
    "'already_submitted' => true",
    'captureRecruitSource(',
    "'ylz_portal_submit'",
    'submit_snapshot',
] as $needle) {
    $assert(strpos($service, $needle) !== false, 'service_contains:' . $needle);
}
$assert(substr_count($service, "'status' => 'submitted'") >= 2, 'portal_submission_uses_canonical_submitted_status');
$assert(strpos($service, "'opened'") !== false, 'portal_duplicate_guard_includes_opened_application');

$routes = $read('app/api/route/yfth_service.php');
foreach ([
    "Route::get('yfth/franchise/portal/draft'",
    "Route::post('yfth/franchise/portal/draft'",
    "Route::post('yfth/franchise/portal/submit'",
    'AuthTokenMiddleware',
] as $needle) {
    $assert(strpos($routes, $needle) !== false, 'route_contains:' . $needle);
}

$frontendRoot = dirname($root) . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'franchise-h5';
foreach ([
    'src/pages/HomePage.vue',
    'src/pages/NewsPage.vue',
    'src/pages/WorkbenchPage.vue',
    'src/pages/ProfilePage.vue',
    'src/pages/ApplyPage.vue',
    'src/components/BottomNav.vue',
] as $file) {
    $assert(is_file($frontendRoot . DIRECTORY_SEPARATOR . $file), 'frontend_file_exists:' . $file);
}
$apply = (string)file_get_contents($frontendRoot . DIRECTORY_SEPARATOR . 'src/pages/ApplyPage.vue');
foreach ([
    'YLZ_FRANCHISE_DRAFT_V1',
    'partner_invite',
    'franchiseApi.saveDraft',
    'franchiseApi.submitDraft',
    '我已阅读并同意加盟申请隐私说明',
] as $needle) {
    $assert(strpos($apply, $needle) !== false, 'frontend_apply_contains:' . $needle);
}
$assert(strpos($apply, 'id_number') === false, 'frontend_does_not_cache_identity_number');

if ($failures) {
    echo "YFTH franchise portal contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - " . $failure . "\n";
    }
    exit(1);
}

echo 'YFTH franchise portal contract check passed (' . count($passes) . " assertions).\n";
