<?php

$root = dirname(__DIR__);
$failures = [];

$timer = file_get_contents($root . '/crmeb/command/Timer.php');
$workerman = file_get_contents($root . '/crmeb/command/Workerman.php');

foreach ([
    'timer.pid',
    'timer.log',
] as $runtimeFile) {
    if (strpos($timer, "app()->getRootPath() . 'runtime/{$runtimeFile}'") === false) {
        $failures[] = "Timer must keep {$runtimeFile} in the writable runtime directory.";
    }
}

foreach ([
    'workerman.pid',
    'workerman.log',
] as $runtimeFile) {
    if (strpos($workerman, "app()->getRootPath() . 'runtime/{$runtimeFile}'") === false) {
        $failures[] = "Workerman must keep {$runtimeFile} in the writable runtime directory.";
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "YFTH runtime service contract check passed.\n");
