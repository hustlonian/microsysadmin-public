<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$archive = realpath($argv[1] ?? '');
if (!$archive || !str_ends_with($archive, '.tar.gz')) {
    fwrite(STDERR, "Usage: php scripts/rollback-cli.php /absolute/path/to/rollback.tar.gz\n");
    exit(64);
}
$releaseRoot = realpath((string) config_value('updates.release_root', '/var/lib/microsysadmin/releases'));
if (!$releaseRoot || !str_starts_with($archive, $releaseRoot . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Rollback archive is outside the configured release root.');
}

file_put_contents(STORAGE_PATH . '/maintenance.flag', json_encode(['rollback' => basename($archive)]), LOCK_EX);
$result = (new SysAdmin\ProcessExecutor())->run(['tar', '-xzf', $archive, '-C', dirname(APP_ROOT)], 600);
if (!$result['ok']) {
    throw new RuntimeException('Rollback extraction failed: ' . $result['error']);
}
SysAdmin\Database::migrate();
SysAdmin\Database::seed();
$healthService = new SysAdmin\HealthCheckService();
$releaseGate = $healthService->releaseGate($healthService->all());
if (!$releaseGate['ok']) {
    $failedNames = array_map(
        static fn (array $check): string => (string) ($check['name'] ?? 'unknown'),
        $releaseGate['failures']
    );
    throw new RuntimeException('Rollback completed but critical health checks failed: ' . implode(', ', $failedNames));
}
@unlink(STORAGE_PATH . '/maintenance.flag');
echo "Rollback completed from {$archive}.\n";
