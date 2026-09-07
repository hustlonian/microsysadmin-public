<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$command = $argv[1] ?? 'check';
$manager = new SysAdmin\UpdateManager();

if ($command === 'check') {
    echo json_encode($manager->check(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

if ($command !== 'apply' || empty($argv[2]) || empty($argv[3])) {
    fwrite(STDERR, "Usage: php scripts/update-cli.php check|apply <manifest.json> <package.tar.gz>\n");
    exit(64);
}

$manifestPath = realpath($argv[2]);
$packagePath = realpath($argv[3]);
if (!$manifestPath || !$packagePath) {
    throw new RuntimeException('Manifest or package path does not exist.');
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
$manager->verifyPackage($manifest, $packagePath);

$releaseRoot = (string) config_value('updates.release_root', '/var/lib/microsysadmin/releases');
if (!is_dir($releaseRoot) && !mkdir($releaseRoot, 0770, true) && !is_dir($releaseRoot)) {
    throw new RuntimeException('Unable to create release storage.');
}
$stamp = date('Ymd-His');
$backupPath = $releaseRoot . '/rollback-' . SysAdmin\MachineIdentity::version() . '-' . $stamp . '.tar.gz';
$runner = new SysAdmin\ProcessExecutor();
$snapshot = $runner->run(['tar', '-czf', $backupPath, '-C', dirname(APP_ROOT), basename(APP_ROOT)], 600);
if (!$snapshot['ok']) {
    throw new RuntimeException('Unable to create application rollback snapshot: ' . $snapshot['error']);
}
(new SysAdmin\BackupService())->database((string) config_value('database.name', 'microsysadmin'));
$historyId = $manager->createHistory($manifest, 'running', $backupPath);
$maintenance = STORAGE_PATH . '/maintenance.flag';
file_put_contents($maintenance, json_encode(['target' => $manifest['version'], 'started_at' => gmdate(DATE_ATOM)]), LOCK_EX);

$staging = $releaseRoot . '/staging-' . bin2hex(random_bytes(8));
mkdir($staging, 0770, true);
try {
    $list = $runner->run(['tar', '-tzf', $packagePath], 60);
    if (!$list['ok']) {
        throw new RuntimeException('Unable to inspect update package.');
    }
    foreach (preg_split('/\R/', trim((string) $list['output'])) ?: [] as $entry) {
        $entry = str_replace('\\', '/', $entry);
        if ($entry === '' || str_starts_with($entry, '/') || in_array('..', explode('/', $entry), true)) {
            throw new RuntimeException('Update package contains an unsafe path.');
        }
    }
    $extract = $runner->run(['tar', '-xzf', $packagePath, '-C', $staging], 300);
    if (!$extract['ok']) {
        throw new RuntimeException('Unable to extract update package: ' . $extract['error']);
    }
    $source = is_dir($staging . '/microsysadmin') ? $staging . '/microsysadmin' : $staging;
    $copy = $runner->run([
        'rsync', '-a', '--delete',
        '--exclude=config/config.php', '--exclude=.env', '--exclude=storage/',
        rtrim($source, '/') . '/', rtrim(APP_ROOT, '/') . '/',
    ], 600);
    if (!$copy['ok']) {
        throw new RuntimeException('Unable to install update files: ' . $copy['error']);
    }
    SysAdmin\Database::migrate();
    SysAdmin\Database::seed();
    (new SysAdmin\MachineIdentity())->touchUpdated();
    $healthService = new SysAdmin\HealthCheckService();
    $health = $healthService->all();
    $releaseGate = $healthService->releaseGate($health);
    if (!$releaseGate['ok']) {
        $failedNames = array_map(
            static fn (array $check): string => (string) ($check['name'] ?? 'unknown'),
            $releaseGate['failures']
        );
        throw new RuntimeException('Post-update critical health checks failed: ' . implode(', ', $failedNames));
    }
    $manager->finishHistory($historyId, 'completed', 'Update completed.', $backupPath);
    @unlink($maintenance);
    (new SysAdmin\EventBus())->dispatch('update.completed', ['version' => $manifest['version']]);
    echo "Updated MicroSysAdmin to {$manifest['version']}.\n";
} catch (Throwable $exception) {
    $rollback = $runner->run(['tar', '-xzf', $backupPath, '-C', dirname(APP_ROOT)], 600);
    $rolledBack = false;
    if ($rollback['ok']) {
        try {
            SysAdmin\Database::migrate();
            SysAdmin\Database::seed();
            $healthService = new SysAdmin\HealthCheckService();
            $rolledBack = $healthService->releaseGate($healthService->all())['ok'];
        } catch (Throwable) {
            $rolledBack = false;
        }
    }
    $manager->finishHistory(
        $historyId,
        $rolledBack ? 'rolled_back' : 'failed',
        $exception->getMessage(),
        $backupPath
    );
    if ($rolledBack) {
        @unlink($maintenance);
    }
    (new SysAdmin\EventBus())->dispatch('update.failed', ['version' => $manifest['version']]);
    fwrite(
        STDERR,
        $rolledBack
            ? "Update failed and was rolled back automatically.\n"
            : "Update and automatic rollback failed; run ./rollback.sh " . escapeshellarg($backupPath) . "\n"
    );
    throw $exception;
}
