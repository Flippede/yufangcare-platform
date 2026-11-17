<?php

namespace crmeb\exceptions {
    class ApiException extends \RuntimeException {}
}

namespace think\facade {
    final class Db
    {
        public static $queries = 0;
        public static function name($table)
        {
            self::$queries++;
            return new class($table) {
                private $table;
                private $filters = [];
                public function __construct($table) { $this->table = $table; }
                public function where($field, $value) { $this->filters[$field] = $value; return $this; }
                public function whereIn($field, $values) { $this->filters[$field] = $values; return $this; }
                public function count()
                {
                    if ($this->table === 'yfth_package_product_binding') {
                        return in_array(9800, $this->filters['product_id'], true) ? 1 : 0;
                    }
                    return $this->filters['order_id'] === 99 ? 1 : 0;
                }
            };
        }
    }
}

namespace {
    use app\services\yfth\MembershipPackageAvailability as Availability;
    use crmeb\exceptions\ApiException;

    $enabled = false;
    function config($key, $default = null) { return $GLOBALS['enabled']; }
    require dirname(__DIR__) . '/app/services/yfth/MembershipPackageAvailability.php';

    $checks = 0;
    function check($condition, $message) {
        $GLOBALS['checks']++;
        if (!$condition) throw new \RuntimeException($message);
    }
    function blocked(callable $call) {
        try { $call(); } catch (ApiException $e) {
            check(strpos($e->getMessage(), '会员套餐暂未开放') !== false, 'clear paused message');
            return;
        }
        throw new \RuntimeException('package write was not blocked');
    }
    check(!Availability::enabled(), 'closed by default');
    blocked(function () { Availability::assertOpen(); });
    blocked(function () { Availability::assertProductsAvailable([9800]); });
    blocked(function () { Availability::assertProductsAvailable([100, 9800]); });
    Availability::assertProductsAvailable([100]);
    Availability::assertProductsAvailable([]);
    blocked(function () { Availability::assertOrderAvailable(99); });
    Availability::assertOrderAvailable(100);
    Availability::assertOrderAvailable(0);
    check(true, 'ordinary products and orders remain available');

    foreach (['1', 'true', true] as $value) {
        $enabled = $value;
        check(Availability::enabled(), 'explicit opt-in');
        $before = \think\facade\Db::$queries;
        Availability::assertOpen();
        Availability::assertProductsAvailable([9800]);
        Availability::assertOrderAvailable(99);
        check($before === \think\facade\Db::$queries, 'enabled fast path');
    }
    foreach (['false', '0', false, null] as $value) {
        $enabled = $value;
        check(!Availability::enabled(), 'false configuration remains closed');
    }

    $root = dirname(__DIR__);
    foreach ([
        'PackagePurchaseServices' => ['createIntent', 'createOrderFromIntent', 'createPurchase'],
        'PermanentMembershipServices' => ['applyByCustomer', 'approveForStore', 'activateIdentityForStore'],
    ] as $service => $methods) {
        $source = file_get_contents($root . '/app/services/yfth/' . $service . '.php');
        foreach ($methods as $method) {
            check((bool)preg_match('/public function ' . $method . '\([^\{]+\{\s*MembershipPackageAvailability::assertOpen\(\);/s', $source), $method . ' must stop before any write');
        }
    }
    $templates = file_get_contents($root . '/app/services/yfth/PackageTemplateServices.php');
    check(strpos($templates, "'available' => false") !== false, 'closed public catalog');
    $home = file_get_contents($root . '/app/services/yfth/HomepageServices.php');
    check(strpos($home, 'excludeClosedPackages($query)') !== false, 'home excludes package-backed products');
    echo "Membership package availability checks passed ($checks assertions; mocked read-only DB, not live MySQL).\n";
}
