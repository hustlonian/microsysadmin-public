<?php

declare(strict_types=1);

$appRoot = rtrim((string) getenv('MICROSYSADMIN_ISOLATED_APP_ROOT'), DIRECTORY_SEPARATOR);
if ($appRoot === '' || !str_starts_with($appRoot, '/tmp/microsysadmin-isolated.')) {
    fwrite(STDERR, "Unsafe isolated application root.\n");
    exit(64);
}

define('APP_ROOT', $appRoot);
require APP_ROOT . '/classes/bootstrap.php';

$history = SysAdmin\Database::pdo()->query(
    'SELECT status, from_version, to_version, rollback_path FROM update_history ORDER BY id DESC LIMIT 1'
)->fetch();
$healthService = new SysAdmin\HealthCheckService();
$gate = $healthService->releaseGate($healthService->all());
$configuration = require APP_ROOT . '/config/config.php';
$expectedVersion = trim((string) getenv('MICROSYSADMIN_ISOLATED_ORIGINAL_VERSION'));

$checks = [
    'version_restored' => $expectedVersion !== ''
        && SysAdmin\MachineIdentity::version() === $expectedVersion,
    'scheduler_restored' => is_file(APP_ROOT . '/cron/runner.php'),
    'history_rolled_back' => (string) ($history['status'] ?? '') === 'rolled_back',
    'target_recorded' => (string) ($history['to_version'] ?? '') === '1.1.1-dev',
    'rollback_archive_recorded' => is_file((string) ($history['rollback_path'] ?? '')),
    'maintenance_cleared' => !is_file(STORAGE_PATH . '/maintenance.flag'),
    'critical_health_passes' => $gate['ok'],
    'configuration_preserved' => (
        strlen((string) ($configuration['security']['app_key'] ?? '')) === 64
        && str_starts_with((string) ($configuration['environment']['storage_root'] ?? ''), '/tmp/microsysadmin-isolated.')
        && (bool) ($configuration['licensing']['development_mode'] ?? false)
    ),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
