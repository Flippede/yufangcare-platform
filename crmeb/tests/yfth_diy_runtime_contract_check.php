<?php

$root = dirname(__DIR__);
$failures = [];

$controller = file_get_contents($root . '/app/api/controller/v1/PublicController.php');
$service = file_get_contents($root . '/app/services/diy/DiyServices.php');

if (strpos($controller, '$services->getDiy((int)$id)') === false) {
    $failures[] = 'The public DIY endpoint must call DiyServices::getDiy().';
}

if (strpos($controller, '$services->getDiyInfo((int)$id)') !== false) {
    $failures[] = 'The public DIY endpoint must not forward to the missing getDiyInfo method.';
}

if (strpos($service, 'public function getDiy($id = 0)') === false) {
    $failures[] = 'DiyServices::getDiy() is missing.';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "YFTH DIY runtime contract check passed.\n");
