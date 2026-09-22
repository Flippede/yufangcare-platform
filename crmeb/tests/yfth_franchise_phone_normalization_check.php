<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = getenv('YFTH_AUTOLOAD') ?: $root . '/vendor/autoload.php';
$serviceFile = getenv('YFTH_FRANCHISE_SERVICE_FILE') ?: $root . '/app/services/yfth/FranchiseApplicationServices.php';

require $autoload;
require_once $serviceFile;

$reflection = new ReflectionClass(\app\services\yfth\FranchiseApplicationServices::class);
$service = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizePhone');
$normalize->setAccessible(true);

$cases = [
    '19999100004' => '19999100004',
    '１９９９９１００００４' => '19999100004',
    '+86 199-9910-0004' => '19999100004',
    '0086（199）9910 0004' => '19999100004',
];

$assertions = 0;
foreach ($cases as $input => $expected) {
    $actual = $normalize->invoke($service, $input);
    $assertions++;
    if ($actual !== $expected) {
        fwrite(STDERR, "Phone normalization failed for {$input}: {$actual}\n");
        exit(1);
    }
}

$rejected = false;
try {
    $normalize->invoke($service, 'not-a-phone');
} catch (Throwable $exception) {
    $rejected = strpos($exception->getMessage(), 'franchise_application_phone_invalid') !== false;
}
$assertions++;
if (!$rejected) {
    fwrite(STDERR, "Invalid phone was not rejected\n");
    exit(1);
}

fwrite(STDOUT, "YFTH franchise phone normalization check passed ({$assertions} assertions).\n");
