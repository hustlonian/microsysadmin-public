<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

SysAdmin\Database::migrate();
SysAdmin\Database::seed();
(new SysAdmin\MachineIdentity())->get();

$directories = [
    STORAGE_PATH,
    STORAGE_PATH . '/logs',
    SysAdmin\EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups'),
    SysAdmin\EnvironmentConfig::path('trash_root', STORAGE_PATH . '/cache/trash'),
    SysAdmin\EnvironmentConfig::path('script_root', STORAGE_PATH . '/scripts'),
    SysAdmin\EnvironmentConfig::path('temporary_workspace_root', STORAGE_PATH . '/workspace'),
];
foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

$healthService = new SysAdmin\HealthCheckService();
$health = $healthService->all();
$releaseGate = $healthService->releaseGate($health);
echo json_encode([
    'repaired' => $releaseGate['ok'],
    'critical_health' => $releaseGate,
    'health' => $health,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($releaseGate['ok'] ? 0 : 1);
