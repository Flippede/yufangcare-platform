<?php

$root = dirname(__DIR__);
$files = [
    'app/services/yfth/UserRelationshipAuthorityServices.php',
    'app/services/yfth/HqAuthorityUserReadServices.php',
    'app/services/yfth/HqUserRoleManagementServices.php',
    'app/services/yfth/PackageMembershipReferralServices.php',
    '../template/uni-app/pages/yfth/referral/code.vue',
    '../template/uni-app/pages/yfth/package/detail.vue',
    '../template/uni-app/pages/yfth/package/payment_confirm.vue',
];
$source = [];
foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing:{$file}\n");
        exit(1);
    }
    $source[$file] = file_get_contents($path);
}

$checks = [
    'authority_role_precedes_customer_projection' => strpos($source[$files[0]], 'operatingRelationship($role)') !== false,
    'authority_uses_role_store' => strpos($source[$files[0]], "(int)\$role['store_id']") !== false,
    'authority_uses_franchise_recruit_source' => strpos($source[$files[0]], "yfth_franchise_recruit_source") !== false,
    'authority_resolves_direct_partner' => strpos($source[$files[0]], "direct_partner_uid") !== false,
    'me_uses_single_authority' => strpos($source[$files[1]], 'relationshipAuthority') === false
        && strpos($source[$files[1]], 'authority->resolve($uid)') !== false,
    'admin_dto_uses_single_authority' => strpos($source[$files[2]], 'relationshipAuthority->resolve($uid)') !== false,
    'package_dto_exposes_single_purchase_store' => strpos($source[$files[3]], "'purchase_store'") !== false
        && strpos($source[$files[3]], "'purchase_attribution'") === false,
    'package_dto_has_current_relationship' => strpos($source[$files[3]], "'current_relationship'") !== false,
    'business_role_cannot_issue_member_code' => strpos($source[$files[3]], 'business_role_uses_store_acquisition_code') !== false,
    'referral_page_redirects_to_store_code' => strpos($source[$files[4]], '/pages/yfth/store_acquisition/code') !== false,
    'package_pages_use_single_purchase_store' => strpos($source[$files[5]], 'profile.purchase_store') !== false
        && strpos($source[$files[6]], 'profile.purchase_store') !== false
        && strpos($source[$files[5]], 'profile.purchase_attribution') === false
        && strpos($source[$files[6]], 'profile.purchase_attribution') === false,
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
