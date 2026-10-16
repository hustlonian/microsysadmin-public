<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$worker = new SysAdmin\JobWorker();
if (($argv[1] ?? '') === '--execute') {
    if (count($argv) !== 4 || !preg_match('/^[a-f0-9-]{36}$/', $argv[2])
        || !ctype_digit($argv[3]) || (int) $argv[3] < 1) {
        exit(64);
    }
    exit($worker->execute($argv[2], (int) $argv[3]) ? 0 : 2);
}
if (($argv[1] ?? '') === '--reconcile') {
    echo json_encode(['reconciled' => (new SysAdmin\JobQueue())->reconcile()], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
if (in_array('--once', $argv, true)) {
    exit($worker->runOnce() ? 0 : 2);
}
$worker->run();
