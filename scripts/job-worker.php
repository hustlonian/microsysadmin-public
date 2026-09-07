<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$worker = new SysAdmin\JobWorker();
if (in_array('--once', $argv, true)) {
    exit($worker->runOnce() ? 0 : 2);
}
$worker->run();
