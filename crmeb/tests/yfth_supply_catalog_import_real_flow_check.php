<?php

use Phinx\Db\Adapter\AdapterFactory;
use think\App;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if ((string)getenv('YFTH_SUPPLY_CATALOG_IMPORT_EXECUTE') !== '1') {
    echo "[NOTE] supply_catalog_import_real_flow_skipped_set_execute=1\n";
    exit(0);
}

$app = new class() extends App {
    public function loadEnv(string $envName = ''): void
    {
        parent::loadEnv($envName);
        foreach ([
            'HOSTNAME' => 'hostname',
            'HOSTPORT' => 'hostport',
            'USERNAME' => 'username',
            'PASSWORD' => 'password',
            'DATABASE' => 'database',
            'PREFIX' => 'prefix',
            'CHARSET' => 'charset',
        ] as $env => $key) {
            $value = getenv('YFTH_REAL_FLOW_DB_' . $env);
            if ($value !== false) {
                $this->env->set('database.' . $key, $value);
            }
        }
        if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') {
            $this->env->set('database.password', '');
        }
        $this->env->set('cache.driver', 'file');
    }
};
$app->initialize();

$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    $condition ? $passes[] = $label : $failures[] = $label;
};

try {
    $database = (string)(Db::query('SELECT DATABASE() AS db')[0]['db'] ?? '');
    $version = (string)(Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB') === '1', 'isolated_database_guard');
    $assert((bool)preg_match('/(test|validation|sandbox)/i', $database), 'isolated_database_name:' . $database);
    $assert(strpos($version, '8.0.46') === 0, 'mysql_8_0_46:' . $version);

    require_once dirname(__DIR__) . '/database/migrations/20260723170000_expose_yfth_procurement_product_management.php';
    $config = Config::get('database.connections.' . Config::get('database.default'));
    $raw = AdapterFactory::instance()->getAdapter('mysql', [
        'adapter' => $config['type'],
        'host' => $config['hostname'],
        'name' => $config['database'],
        'user' => $config['username'],
        'pass' => $config['password'],
        'port' => $config['hostport'],
        'charset' => $config['charset'],
        'table_prefix' => $config['prefix'],
        'default_migration_table' => $config['prefix'] . Config::get('database.migration_table', 'migrations'),
    ]);
    $migration = new ExposeYfthProcurementProductManagement(20260723170000);
    $migration->setAdapter(AdapterFactory::instance()->getWrapper('prefix', $raw));
    $migration->up();
    $assert(
        (int)Db::name('system_menus')->where('unique_auth', 'yfth-procurement-product-index')->where('is_show', 1)->count() === 1,
        'procurement_product_menu_created'
    );
    $assert(
        (int)Db::name('system_menus')->where('unique_auth', 'yfth-supply-catalog-import-visible')->where('auth_type', 2)->count() === 1,
        'catalog_import_permission_created'
    );
    $migration->down();
    $assert(
        (int)Db::name('system_menus')->whereIn('unique_auth', [
            'yfth-procurement-product-index',
            'yfth-supply-catalog-import-visible',
        ])->count() === 0,
        'procurement_menu_targeted_rollback'
    );
    $migration->up();
    $assert(
        (int)Db::name('system_menus')->where('unique_auth', 'yfth-procurement-product-index')->count() === 1,
        'procurement_menu_rerun'
    );

    $productIds = Db::name('store_product')
        ->where('is_del', 0)
        ->where('is_show', 1)
        ->where('is_virtual', 0)
        ->order('id asc')
        ->column('id');
    $assert(count($productIds) > 0, 'visible_physical_product_fixture_exists');
    Db::name('yfth_supply_catalog')->whereIn('product_id', $productIds)->delete();

    /** @var \app\services\yfth\SupplyChainServices $service */
    $service = app()->make(\app\services\yfth\SupplyChainServices::class);
    $admin = ['id' => 1, 'level' => 0, 'status' => 1];
    $first = $service->adminImportVisibleProducts([], 1, $admin);
    $assert((int)$first['imported_count'] === count($productIds), 'first_import_creates_all_visible_physical_products');
    $assert(
        (int)Db::name('yfth_supply_catalog')->whereIn('product_id', $productIds)->where('status', 'active')->count() === count($productIds),
        'imported_catalog_rows_are_active'
    );
    $assert(
        (int)Db::name('yfth_supply_catalog')->alias('c')
            ->join('store_product p', 'p.id=c.product_id')
            ->where('p.is_virtual', 1)
            ->count() === 0,
        'virtual_products_are_excluded'
    );

    $preservedProductId = (int)$productIds[0];
    Db::name('yfth_supply_catalog')->where('product_id', $preservedProductId)->update(['purchase_price' => '1.23']);
    $second = $service->adminImportVisibleProducts([], 1, $admin);
    $assert((int)$second['imported_count'] === 0, 'repeat_import_is_idempotent');
    $assert((int)$second['skipped_count'] === count($productIds), 'repeat_import_reports_skipped_products');
    $assert(
        (string)Db::name('yfth_supply_catalog')->where('product_id', $preservedProductId)->value('purchase_price') === '1.23',
        'repeat_import_preserves_configured_purchase_price'
    );

    Db::name('yfth_supply_catalog')->whereIn('product_id', $productIds)->delete();
} catch (Throwable $e) {
    $failures[] = 'exception:' . $e->getMessage();
}

foreach ($passes as $pass) {
    echo "[PASS] {$pass}\n";
}
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}
echo "[OK] YFTH supply catalog import and procurement menu lifecycle verified.\n";
