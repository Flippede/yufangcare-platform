<?php

use Phinx\Db\Adapter\AdapterFactory;
use think\App;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
if ((string)getenv('YFTH_MEMBER_PACKAGE_REAL_FLOW_EXECUTE') !== '1') {
    echo "[NOTE] member_package_9800_real_flow_skipped_set_execute=1\n";
    exit(0);
}

$app = new class() extends App {
    public function loadEnv(string $envName = ''): void
    {
        parent::loadEnv($envName);
        foreach (['HOSTNAME'=>'hostname','HOSTPORT'=>'hostport','USERNAME'=>'username','PASSWORD'=>'password','DATABASE'=>'database','PREFIX'=>'prefix','CHARSET'=>'charset'] as $env => $key) {
            $value = getenv('YFTH_REAL_FLOW_DB_' . $env);
            if ($value !== false) $this->env->set('database.' . $key, $value);
        }
        if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') $this->env->set('database.password', '');
        $this->env->set('cache.driver', 'file');
    }
};
$app->initialize();
require_once dirname(__DIR__) . '/database/migrations/20260721100000_promote_yfth_member_package_9800.php';
require_once dirname(__DIR__) . '/database/migrations/20260722110000_repair_yfth_member_package_virtual_checkout.php';

$failures = [];
$passes = [];
$assert = function (bool $ok, string $label) use (&$failures, &$passes): void {
    $ok ? $passes[] = $label : $failures[] = $label;
};

try {
    $config = Config::get('database.connections.mysql');
    $database = (string)(Db::query('SELECT DATABASE() db')[0]['db'] ?? '');
    $version = (string)(Db::query('SELECT VERSION() version')[0]['version'] ?? '');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_guard');
    $assert((bool)preg_match('/(test|validation|sandbox)/i', $database), 'isolated_database:' . $database);
    $assert(strpos($version, '8.0.46') === 0, 'mysql_8_0_46:' . $version);

    $raw = AdapterFactory::instance()->getAdapter('mysql', [
        'adapter'=>$config['type'], 'host'=>$config['hostname'], 'name'=>$config['database'],
        'user'=>$config['username'], 'pass'=>$config['password'], 'port'=>$config['hostport'],
        'charset'=>$config['charset'], 'table_prefix'=>$config['prefix'],
        'default_migration_table'=>$config['prefix'] . Config::get('database.migration_table', 'migrations'),
    ]);
    $migration = new PromoteYfthMemberPackage9800(20260721100000);
    $migration->setAdapter(AdapterFactory::instance()->getWrapper('prefix', $raw));
    $migration->up();
    $migration->up();
    $virtualRepair = new RepairYfthMemberPackageVirtualCheckout(20260722110000);
    $virtualRepair->setAdapter(AdapterFactory::instance()->getWrapper('prefix', $raw));
    $virtualRepair->up();
    $virtualRepair->up();

    $template = Db::name('yfth_package_template')->where('package_code', 'YFTH-MEMBER-PACKAGE-V1')->find();
    $rule = $template ? Db::name('yfth_package_rule_version')->where('id', (int)$template['current_rule_version_id'])->find() : [];
    $product = Db::name('store_product')->where('bar_code', 'YFTHPKG9800')->find();
    $sku = $product ? Db::name('store_product_attr_value')->where('product_id', (int)$product['id'])->where('is_show', 1)->find() : [];
    $binding = $rule ? Db::name('yfth_package_product_binding')->where([
        'template_id'=>(int)$template['id'], 'rule_version_id'=>(int)$rule['id'], 'binding_status'=>'active',
    ])->find() : [];
    $assert((bool)$template && (string)$template['base_price'] === '9800.00', 'template_promoted_to_9800');
    $assert((bool)$rule && (string)$rule['package_price'] === '9800.00' && (int)$rule['grants_permanent_membership'] === 1, 'published_member_rule_9800');
    $assert((bool)$product && (string)$product['price'] === '9800.00' && (int)$product['is_show'] === 1, 'dedicated_crmeb_product_visible');
    $assert((int)$product['is_virtual'] === 1 && (int)$product['virtual_type'] === 1, 'member_package_uses_virtual_checkout_without_recipient');
    $assert((bool)$sku && (string)$sku['price'] === '9800.00', 'dedicated_sku_9800');
    $assert((int)$sku['is_virtual'] === 1, 'member_package_sku_is_virtual');
    $assert((bool)$binding && (string)$binding['sku_price_snapshot'] === '9800.00', 'active_binding_snapshot_9800');

    /** @var \app\services\yfth\PackageTemplateServices $service */
    $service = app()->make(\app\services\yfth\PackageTemplateServices::class);
    $draft = $service->copyRuleVersion((int)$rule['id'], 1);
    $draft['package_price'] = '9888.00';
    $draft['status'] = 'published';
    $service->saveRuleVersion($draft, 1);
    $newRule = Db::name('yfth_package_rule_version')->where('id', (int)$draft['id'])->find();
    $oldRule = Db::name('yfth_package_rule_version')->where('id', (int)$rule['id'])->find();
    $newBinding = Db::name('yfth_package_product_binding')->where('rule_version_id', (int)$newRule['id'])->where('binding_status', 'active')->find();
    $assert((string)$newRule['package_price'] === '9888.00', 'admin_price_version_published');
    $assert((string)$oldRule['package_price'] === '9800.00' && (string)$oldRule['status'] === 'superseded', 'historical_rule_price_immutable');
    $assert((string)Db::name('store_product')->where('id', (int)$product['id'])->value('price') === '9888.00', 'managed_product_price_synced');
    $assert((string)Db::name('store_product_attr_value')->where('id', (int)$sku['id'])->value('price') === '9888.00', 'managed_sku_price_synced');
    $assert((int)Db::name('store_product')->where('id', (int)$product['id'])->value('virtual_type') === 1, 'admin_price_publish_preserves_virtual_checkout');
    $assert((bool)$newBinding && (string)$newBinding['sku_price_snapshot'] === '9888.00', 'new_binding_snapshot_synced');

    $source = (string)file_get_contents(dirname(__DIR__) . '/app/services/yfth/PackagePurchaseServices.php');
    $assert(strpos($source, 'permanent_member_cannot_repeat') === false, 'normal_purchase_has_no_member_repeat_block');
} catch (Throwable $e) {
    $failures[] = 'exception:' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

foreach ($passes as $pass) echo "[PASS] {$pass}\n";
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] {$failure}\n");
    exit(1);
}
echo '[OK] YFTH member package 9800 real flow passed with ' . count($passes) . " assertions.\n";
