<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

use Symfony\Component\Process\Process;
use SysAdmin\BackupService;
use SysAdmin\EnvironmentConfig;

$options = getopt('', ['archive:', 'confirm']);
$archiveName = basename((string) ($options['archive'] ?? ''));
if ($archiveName === '' || !isset($options['confirm'])) {
    fwrite(STDERR, "Usage: php scripts/restore-database.php --archive=BACKUP.sql.zip --confirm\n");
    exit(64);
}

$backupRoot = realpath(EnvironmentConfig::path('backup_root'));
$archive = $backupRoot !== false ? realpath($backupRoot . DIRECTORY_SEPARATOR . $archiveName) : false;
if ($backupRoot === false || $archive === false || !str_starts_with($archive, $backupRoot . DIRECTORY_SEPARATOR)) {
    throw new InvalidArgumentException('Database backup is outside the configured backup root.');
}
if (!str_ends_with(strtolower($archive), '.sql.zip')) {
    throw new InvalidArgumentException('Database restore accepts only MicroSysAdmin .sql.zip backups.');
}

$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CHECKCONS) !== true || $zip->numFiles !== 1) {
    throw new RuntimeException('Database backup integrity validation failed.');
}
$zipOpen = true;
$entry = (string) $zip->getNameIndex(0);
if (
    $entry === ''
    || basename($entry) !== $entry
    || !str_ends_with(strtolower($entry), '.sql')
    || str_contains($entry, "\0")
) {
    $zip->close();
    throw new RuntimeException('Database backup contains an unsafe entry.');
}

$preRestore = (new BackupService())->database((string) config_value('database.name', 'microsysadmin'));
$temporary = tempnam(EnvironmentConfig::path('private_root'), 'restore-');
if ($temporary === false) {
    $zip->close();
    throw new RuntimeException('Unable to allocate protected restore workspace.');
}
chmod($temporary, 0600);

try {
    $input = $zip->getStream($entry);
    $output = fopen($temporary, 'wb');
    if (!is_resource($input) || !is_resource($output) || stream_copy_to_stream($input, $output) === false) {
        throw new RuntimeException('Unable to read the database backup.');
    }
    fclose($input);
    fclose($output);
    $zip->close();
    $zipOpen = false;

    $db = (array) config_value('database', []);
    $command = [EnvironmentConfig::path('mysql_binary')];
    $socket = (string) ($db['socket'] ?? EnvironmentConfig::get('mysql_socket', ''));
    if ($socket !== '') {
        $command[] = '--socket=' . $socket;
    } else {
        $command[] = '--host=' . (string) ($db['host'] ?? '127.0.0.1');
        $command[] = '--port=' . (string) ($db['port'] ?? 3306);
    }
    $command[] = '--user=' . (string) ($db['user'] ?? 'microsysadmin');
    $command[] = (string) ($db['name'] ?? 'microsysadmin');

    $stream = fopen($temporary, 'rb');
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to open the protected restore workspace.');
    }
    $process = new Process($command);
    $process->setEnv(['MYSQL_PWD' => (string) ($db['password'] ?? '')]);
    $process->setInput($stream);
    $process->setTimeout(600);
    $process->run();
    fclose($stream);
    if (!$process->isSuccessful()) {
        throw new RuntimeException('Database import failed; the pre-restore backup was preserved.');
    }
} finally {
    if ($zipOpen) {
        $zip->close();
    }
    file_put_contents($temporary, '', LOCK_EX);
    @unlink($temporary);
}

echo json_encode([
    'ok' => true,
    'restored_archive' => basename($archive),
    'pre_restore_backup' => $preRestore['archive'] ?? null,
    'integrity_checked' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
