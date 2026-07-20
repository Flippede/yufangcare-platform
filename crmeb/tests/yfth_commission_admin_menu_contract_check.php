<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/template/admin/src/pages/yfth/commissionFinance/index.vue');
$router = file_get_contents($root . '/template/admin/src/router/modules/yfth.js');
$migration = file_get_contents($root . '/crmeb/database/migrations/20260720200000_create_yfth_automatic_commission_accounts_v1.php');
$service = file_get_contents($root . '/crmeb/app/services/yfth/AutomaticCommissionServices.php');
$settlement = file_get_contents($root . '/crmeb/app/services/yfth/CommissionFinanceServices.php');

$checks = [
    'visible menu name' => strpos($migration, "'menu_name' => '佣金与结算'") !== false,
    'visible menu path' => strpos($migration, "'menu_path' => '/yfth/commission-finance'") !== false,
    'admin router' => strpos($router, "path: 'commission-finance'") !== false,
    'router permission' => strpos($router, "auth: ['yfth-auto-commission-index']") !== false,
    'three product tabs' => substr_count($page, '<el-tab-pane') === 3,
    'rule tab' => strpos($page, 'label="佣金规则"') !== false,
    'automatic record tab' => strpos($page, 'label="自动佣金记录"') !== false,
    'settlement batch tab' => strpos($page, 'label="B1结算批次"') !== false,
    'ordinary observation supports zero' => strpos($page, 'ruleForm.observation_days" :min="0"') !== false,
    'package observation supports zero' => strpos($page, 'packageRuleForm.package_observation_days" :min="0"') !== false,
    'package reward ratios visible' => strpos($page, '15% / 25% / 60%') !== false,
    'automatic records join user' => strpos($service, "leftJoin('user u'") !== false,
    'automatic records join store' => strpos($service, "leftJoin('system_store s'") !== false,
    'refund reversal visible' => strpos($page, '冲正明细') !== false,
    'receiver state visible' => strpos($settlement, "'receiver_status'") !== false,
    'no store withdrawal entry' => strpos($page, 'B1提现') === false && strpos($page, '提现申请') === false,
];

$failed = array_keys(array_filter($checks, static function (bool $ok): bool { return !$ok; }));
if ($failed) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'yfth commission admin menu contract check passed (' . count($checks) . ' assertions)' . PHP_EOL;
