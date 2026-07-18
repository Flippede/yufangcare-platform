<?php

$root = dirname(__DIR__);
$projectRoot = dirname($root);
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

$methodBlock = function (string $source, string $methodName): string {
    $needle = 'function ' . $methodName . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return '';
};

foreach ([
    'database/migrations/20260708110000_create_yfth_franchise_application_tables.php',
    'database/migrations/20260708113000_add_yfth_franchise_follow_visibility.php',
    'database/migrations/20260719130000_simplify_franchise_review_and_localize_admin.php',
    'database/migrations/20260718150000_add_franchise_application_approved_store.php',
    'app/model/yfth/YfthFranchiseApplication.php',
    'app/model/yfth/YfthFranchiseFollowRecord.php',
    'app/dao/yfth/YfthFranchiseApplicationDao.php',
    'app/dao/yfth/YfthFranchiseFollowRecordDao.php',
    'app/services/yfth/FranchiseApplicationServices.php',
    'app/api/controller/v1/yfth/FranchiseApplicationController.php',
    'app/adminapi/controller/v1/yfth/FranchiseApplication.php',
    'app/api/route/yfth_service.php',
    'app/adminapi/route/yfth.php',
    'tests/yfth_franchise_application_contract_check.php',
] as $file) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . $file), 'file_exists:' . $file);
}

$migration = $read('database/migrations/20260708110000_create_yfth_franchise_application_tables.php');
$visibilityMigration = $read('database/migrations/20260708113000_add_yfth_franchise_follow_visibility.php');
$reviewMigration = $read('database/migrations/20260719130000_simplify_franchise_review_and_localize_admin.php');
$approvedStoreMigration = $read('database/migrations/20260718150000_add_franchise_application_approved_store.php');
foreach ([
    'yfth_franchise_application',
    'yfth_franchise_follow_record',
    'application_no',
    'applicant_uid',
    'assigned_uid',
    'intention_area',
    'pending_contract',
    'uniq_yfth_franchise_app_no',
    'idx_yfth_franchise_app_user_status',
    'idx_yfth_franchise_app_owner_status',
    'idx_yfth_franchise_follow_app_time',
    'yfth-franchise-application-index',
    'yfth/franchise_application/application/<id>/status',
] as $needle) {
    $assert(strpos($migration, $needle) !== false, 'migration_contains:' . $needle);
}
foreach ([
    'AddYfthFranchiseFollowVisibility',
    'visible_type',
    'internal',
    'public/internal visibility',
    'idx_yfth_franchise_follow_visible_time',
    'removeColumn(\'visible_type\')',
] as $needle) {
    $assert(strpos($visibilityMigration, $needle) !== false, 'visibility_migration_contains:' . $needle);
}
foreach ([
    'SimplifyFranchiseReviewAndLocalizeAdmin',
    'yfth-franchise-application-review',
    'yfth/franchise_application/application/<id>/review',
    '总部加盟申请',
    '套餐会员与一级推荐',
    '供应链与门店库存',
] as $needle) {
    $assert(strpos($reviewMigration, $needle) !== false, 'review_migration_contains:' . $needle);
}
foreach ([
    'approved_store_id',
    'idx_yfth_franchise_app_store',
] as $needle) {
    $assert(strpos($approvedStoreMigration, $needle) !== false, 'approved_store_migration_contains:' . $needle);
}

$service = $read('app/services/yfth/FranchiseApplicationServices.php');
foreach ([
    'FranchiseApplicationServices',
    'YfthFranchiseApplicationDao',
    'YfthFranchiseFollowRecordDao',
    'private const DOMAIN = \'yfth_franchise_application\'',
    'private const USER_SOURCE = \'miniapp_cooperation_center\'',
    'IMPLEMENTED_STATUSES',
    'RESERVED_STATUSES',
    'STATUS_TRANSITIONS',
    '\'submitted\' => [\'contacting\']',
    '\'contacting\' => [\'communicating\']',
    '\'communicating\' => [\'inspecting\']',
    '\'inspecting\' => [\'pending_contract\']',
    'franchise_application_user_field_forbidden',
    'franchise_application_status_reserved_for_later',
    'franchise_application_status_transition_forbidden',
    'request->uid()',
    'maskPhone',
    'phone_masked',
    'assigned_name',
    'submit_time',
    'AuditEventServices',
    'recordSafely',
    'SystemAdminDao',
    'AdminStoreContextServices',
    'assertHeadquarterScope',
    'FOLLOW_VISIBLE_TYPES',
    'normalizeFollowVisibility',
    '\'visible_type\' => $visibleType',
    '->where(\'visible_type\', \'public\')',
    'object_type\', \'franchise_application\'',
    'object_type\', \'franchise_follow_record\'',
    'add_time',
    'public function review(',
    'offline_review_approved',
    'offline_review_rejected',
    "['approve', 'reject']",
    "? 'pending_contract' : 'terminated'",
    '$action === \'reject\' && $current === \'pending_contract\'',
    'approved_store_id',
    'createApprovedStore',
    'grantApprovedStoreManager',
    "'role_code' => 'store_manager'",
] as $needle) {
    $assert(strpos($service, $needle) !== false, 'service_contains:' . $needle);
}

$auditBlock = $methodBlock($service, 'auditEvents');
$assert($auditBlock !== '', 'service_audit_events_method_found');
foreach ([
    'after_state',
    'like',
    'whereOr',
    'create_time',
] as $needle) {
    $assert(strpos($auditBlock, $needle) === false, 'audit_events_not_contains:' . $needle);
}
$assert(strpos($auditBlock, 'add_time') !== false, 'audit_events_uses_add_time');
$assert(strpos($auditBlock, 'whereIn(\'object_id\', $followIds)') !== false, 'audit_events_matches_follow_ids_precisely');

$followRecordsBlock = $methodBlock($service, 'followRecords');
$latestFollowBlock = $methodBlock($service, 'latestFollow');
$assert(strpos($followRecordsBlock, 'visible_type\', \'public\'') !== false, 'user_follow_records_filter_public');
$assert(strpos($latestFollowBlock, 'visible_type\', \'public\'') !== false, 'user_latest_follow_filter_public');

$exactAuditFixture = [
    ['id' => 1, 'object_type' => 'franchise_application', 'object_id' => '1'],
    ['id' => 2, 'object_type' => 'franchise_application', 'object_id' => '10'],
    ['id' => 3, 'object_type' => 'franchise_follow_record', 'object_id' => '21'],
    ['id' => 4, 'object_type' => 'franchise_follow_record', 'object_id' => '210'],
];
$applicationId = 1;
$followIds = ['21'];
$exactMatches = array_values(array_filter($exactAuditFixture, function ($row) use ($applicationId, $followIds) {
    if ($row['object_type'] === 'franchise_application') {
        return $row['object_id'] === (string)$applicationId;
    }
    if ($row['object_type'] === 'franchise_follow_record') {
        return in_array($row['object_id'], $followIds, true);
    }
    return false;
}));
$assert(array_column($exactMatches, 'id') === [1, 3], 'audit_matching_exact_application_id_1_not_10');

foreach ([
    'createStore',
    'grantFranchisee',
    'franchisee_identity',
    'store_staff',
    'service_mentor',
    'admin_token',
    'openid',
    'unionid',
    'AppSecret',
] as $needle) {
    $assert(strpos($service, $needle) === false, 'service_not_contains:' . $needle);
}

$apiController = $read('app/api/controller/v1/yfth/FranchiseApplicationController.php');
foreach ([
    'submit',
    'myList',
    'detail',
    'postMore',
    'applicant_uid',
    'assigned_uid',
    'status',
    '_forbidden_user_fields_submitted',
] as $needle) {
    $assert(strpos($apiController, $needle) !== false, 'api_controller_contains:' . $needle);
}
foreach ([
    "[['uid', 'd'], 0]",
    "[['applicant_uid', 'd'], 0]",
    "['status', 'submitted']",
] as $needle) {
    $assert(strpos($apiController, $needle) === false, 'api_controller_not_accepts:' . $needle);
}

$apiRoute = $read('app/api/route/yfth_service.php');
foreach ([
    'yfth/franchise/application',
    'yfth/franchise/application/my',
    'yfth/franchise/application/:id',
    'FranchiseApplicationController/submit',
    'FranchiseApplicationController/myList',
    'FranchiseApplicationController/detail',
    'AuthTokenMiddleware',
    'yfth_franchise_application_user',
] as $needle) {
    $assert(strpos($apiRoute, $needle) !== false, 'api_route_contains:' . $needle);
}

$adminController = $read('app/adminapi/controller/v1/yfth/FranchiseApplication.php');
foreach ([
    'extends AuthController',
    "assertAdminApiAuth('yfth/franchise_application/application', 'GET')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>', 'GET')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>/assign', 'POST')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>/status', 'POST')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>/follow', 'POST')",
    "assertAdminApiAuth('yfth/franchise_application/application/<id>/review', 'POST')",
    'SystemRoleServices',
    'assignOwner',
    'changeStatus',
    'addFollow',
    'services->review',
    "['store_name', '']",
    "[['store_id', 'd'], 0]",
    'visible_type',
] as $needle) {
    $assert(strpos($adminController, $needle) !== false, 'admin_controller_contains:' . $needle);
}

$reviewMethod = $methodBlock($service, 'review');
foreach ([
    'createApprovedStore',
    'grantApprovedStoreManager',
    "'approved_store_id'",
] as $needle) {
    $assert(strpos($reviewMethod, $needle) !== false, 'review_grants_store_manager:' . $needle);
}
$grantManagerMethod = $methodBlock($service, 'grantApprovedStoreManager');
foreach ([
    "'store_manager'",
    'UserStoreRoleServices::class',
    'grant_store_manager_on_franchise_approval',
] as $needle) {
    $assert(strpos($grantManagerMethod, $needle) !== false, 'manager_grant_method_contains:' . $needle);
}

$adminRoute = $read('app/adminapi/route/yfth.php');
foreach ([
    'franchise_application',
    'FranchiseApplication/applicationList',
    'FranchiseApplication/applicationDetail',
    'FranchiseApplication/assign',
    'FranchiseApplication/status',
    'FranchiseApplication/follow',
    'FranchiseApplication/review',
    'AdminAuthTokenMiddleware',
    'AdminCheckRoleMiddleware',
] as $needle) {
    $assert(strpos($adminRoute, $needle) !== false, 'admin_route_contains:' . $needle);
}

$adminApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/api/yfth.js');
foreach ([
    'yfthFranchiseApplicationList',
    'yfthFranchiseApplicationDetail',
    'yfthFranchiseApplicationAssign',
    'yfthFranchiseApplicationStatus',
    'yfthFranchiseApplicationFollow',
    'yfthFranchiseApplicationReview',
    'yfth/franchise_application/application',
] as $needle) {
    $assert(strpos($adminApi, $needle) !== false, 'admin_api_contains:' . $needle);
}

$adminRouteJs = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/router/modules/yfth.js');
$assert(strpos($adminRouteJs, 'franchise-application') !== false, 'admin_router_contains_franchise_application');
$assert(strpos($adminRouteJs, 'yfth-franchise-application-index') !== false, 'admin_router_contains_auth');
$adminPage = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/admin/src/pages/yfth/franchiseApplication/index.vue');
$assert(strpos($adminPage, '总部加盟申请') !== false, 'admin_page_has_discoverable_title');
$assert(strpos($adminPage, "this.filters.status = this.\$route.query.status || ''") !== false, 'admin_page_accepts_workbench_status_filter');
foreach ([
    '同意加盟',
    '驳回申请',
    'yfthFranchiseApplicationReview',
    '补齐门店与店长',
    'store_mode',
    'store_name',
    'store_id',
    'merchantStoreApi',
    'approved_store_id',
] as $needle) {
    $assert(strpos($adminPage, $needle) !== false, 'admin_page_review_contains:' . $needle);
}
foreach ([
    '推进状态',
    '创建加盟合同',
    '新增沟通记录',
] as $needle) {
    $assert(strpos($adminPage, $needle) === false, 'admin_page_review_not_contains:' . $needle);
}

$uniApi = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/api/yfth.js');
foreach ([
    'getYfthFranchiseApplications',
    'getYfthFranchiseApplicationDetail',
    'submitYfthFranchiseApplication',
    'yfth/franchise/application/my',
    'yfth/franchise/application/',
] as $needle) {
    $assert(strpos($uniApi, $needle) !== false, 'uni_api_contains:' . $needle);
}

foreach ([
    'pages/yfth/franchise/index.vue',
    'pages/yfth/franchise/apply.vue',
    'pages/yfth/franchise/detail.vue',
] as $file) {
    $assert(is_file($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/' . $file), 'uni_page_exists:' . $file);
}

$pagesJson = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages.json');
foreach ([
    '"path": "franchise/index"',
    '"path": "franchise/apply"',
    '"path": "franchise/detail"',
] as $needle) {
    $assert(strpos($pagesJson, $needle) !== false, 'pages_json_contains:' . $needle);
}

$userIndex = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/user/index.vue');
foreach ([
    'goYfthFranchiseApplications',
    '/pages/yfth/franchise/index',
    '御方通和合作中心',
] as $needle) {
    $assert(strpos($userIndex, $needle) !== false, 'user_center_contains:' . $needle);
}

$userApply = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/franchise/apply.vue');
foreach ([
    'submitYfthFranchiseApplication',
    'form.name',
    'form.phone',
    'form.city',
    'form.intention_area',
] as $needle) {
    $assert(strpos($userApply, $needle) !== false, 'user_apply_contains:' . $needle);
}
foreach ([
    'applicant_uid',
    'assigned_uid',
    'admin_token',
    'status:',
] as $needle) {
    $assert(strpos($userApply, $needle) === false, 'user_apply_not_contains:' . $needle);
}

$userDetail = (string)file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'template/uni-app/pages/yfth/franchise/detail.vue');
foreach ([
    'assigned_uid',
    'operator_uid',
    'visible_type: \'internal\'',
    'audit_events',
    'phone }}',
    'create_time',
    'update_time',
] as $needle) {
    $assert(strpos($userDetail, $needle) === false, 'user_detail_not_contains:' . $needle);
}

$assert(strpos($adminPage, 'scope.row.add_time') !== false, 'admin_page_contains_audit_time');

if ($failures) {
    echo "YFTH franchise application contract check failed:\n";
    foreach ($failures as $failure) {
        echo " - " . $failure . "\n";
    }
    exit(1);
}

echo "YFTH franchise application contract check passed (" . count($passes) . " assertions).\n";
