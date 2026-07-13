<?php

use app\services\yfth\PackageMembershipReferralMigrationHealthServices;

require __DIR__ . '/yfth_package_membership_referral_test_bootstrap.php';

try {
    packageMembershipReferralBootTestApp();
    $result = app()->make(PackageMembershipReferralMigrationHealthServices::class)->inspect();
    if (empty($result['healthy'])) {
        fwrite(STDERR, '[FAIL] yfth_package_membership_referral_v2_forward_repair_required:'
            . implode(',', $result['issues'] ?? []) . PHP_EOL);
        exit(1);
    }
    echo "[OK] YFTH package membership referral migration health verified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] migration_health_exception:' . $e->getMessage() . PHP_EOL);
    exit(1);
}
