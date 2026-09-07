<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$result = (new SysAdmin\HealthCheckService())->all();
if (in_array('--release-gate', $argv, true)) {
    $gate = (new SysAdmin\HealthCheckService())->releaseGate($result);
    echo json_encode([
        'ok' => $gate['ok'],
        'release_gate' => $gate,
        'health' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($gate['ok'] ? 0 : 1);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
