<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\BackupJob;
use Symfony\Component\Process\Process;
use ZipArchive;

final class BackupService implements \SysAdmin\Contracts\BackupOperationsInterface
{
    public function database(string $database): array
    {
        $database = $database ?: (string) config_value('database.name', 'microsysadmin');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new \InvalidArgumentException('Invalid database name.');
        }

        $jobId = BackupJob::create('database', $database, Auth::id());
        $backupRoot = EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups');
        $file = $this->availablePath($backupRoot . '/' . $database . '-' . date('Ymd-His'), '.sql');
        $db = config_value('database');
        $command = [EnvironmentConfig::path('mysqldump_binary')];
        if ((string) ($db['socket'] ?? EnvironmentConfig::get('mysql_socket', '')) !== '') {
            $command[] = '--socket=' . (string) ($db['socket'] ?? EnvironmentConfig::get('mysql_socket', ''));
        } else {
            array_push($command, '-h', (string) $db['host'], '-P', (string) $db['port']);
        }
        array_push($command, '-u', (string) $db['user'], $database);
        $output = '';
        $error = '';
        $code = 1;

        if (class_exists(Process::class)) {
            $process = new Process($command);
            $process->setEnv(['MYSQL_PWD' => (string) $db['password']]);
            $process->setTimeout(300);
            $process->run();
            $output = $process->getOutput();
            $error = $process->getErrorOutput();
            $code = (int) $process->getExitCode();
        } else {
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['MYSQL_PWD' => (string) $db['password']]);
            if (is_resource($proc)) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
            }
        }

        if ($code !== 0 || $output === '') {
            BackupJob::finish($jobId, 'failed', 100, $error ?: 'mysqldump failed', null);
            (new EventBus())->dispatch('backup.failed', ['type' => 'database', 'database' => $database, 'job_id' => $jobId]);
            throw new \RuntimeException($error ?: 'Database backup failed.');
        }

        try {
            if (file_put_contents($file, $output, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write the protected database backup workspace.');
            }
            chmod($file, 0600);
            $archive = $this->compressFile($file);
        } catch (\Throwable $exception) {
            $this->secureRemove($file);
            BackupJob::finish($jobId, 'failed', 100, $exception->getMessage(), null);
            (new EventBus())->dispatch('backup.failed', ['type' => 'database', 'database' => $database, 'job_id' => $jobId]);
            throw $exception;
        }
        BackupJob::finish($jobId, 'completed', 100, 'Database backup completed.', $archive);
        AuditLogger::info('backup.database', ['database' => $database, 'archive' => $archive]);
        (new EventBus())->dispatch('backup.completed', ['type' => 'database', 'database' => $database, 'job_id' => $jobId]);

        return ['job_id' => $jobId, 'archive' => $archive];
    }

    public function folder(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is not installed.');
        }
        $path = PathGuard::assertAllowed($path);
        $jobId = BackupJob::create('folder', $path, Auth::id());
        $archive = $this->availablePath(
            EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups') . '/' . basename($path) . '-' . date('Ymd-His'),
            '.zip'
        );
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            BackupJob::finish($jobId, 'failed', 100, 'Unable to create archive.', null);
            throw new \RuntimeException('Unable to create archive.');
        }
        $this->addToZip($zip, $path, basename($path));
        $zip->close();
        BackupJob::finish($jobId, 'completed', 100, 'Folder backup completed.', $archive);
        AuditLogger::info('backup.folder', ['path' => $path, 'archive' => $archive]);
        (new EventBus())->dispatch('backup.completed', ['type' => 'folder', 'target' => $path, 'job_id' => $jobId]);

        return ['job_id' => $jobId, 'archive' => $archive];
    }

    public function list(): array
    {
        $archives = [];
        foreach (glob(EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups') . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $archives[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size_human' => human_bytes(filesize($file)),
                    'modified' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
        }
        rsort($archives);

        return ['archives' => $archives, 'history' => BackupJob::recent(30)];
    }

    public function restore(string $archive, string $target): array
    {
        $archive = realpath(EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups') . '/' . basename($archive));
        if (!$archive || !is_file($archive)) {
            throw new \InvalidArgumentException('Backup archive not found.');
        }
        $target = PathGuard::assertAllowedDirectory($target);

        if (str_ends_with($archive, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) {
                throw new \RuntimeException('Unable to open backup.');
            }
            $this->assertSafeArchive($zip);
            $preRestore = $this->folder($target);
            if (!$zip->extractTo($target)) {
                $zip->close();
                throw new \RuntimeException('Unable to extract backup.');
            }
            $zip->close();
            AuditLogger::warning('backup.restore', [
                'archive' => $archive,
                'target' => $target,
                'pre_restore_backup' => $preRestore['archive'],
            ]);
            return [
                'target' => $target,
                'integrity_checked' => true,
                'pre_restore_backup' => $preRestore['archive'],
            ];
        }

        throw new \InvalidArgumentException('Only zip restore is supported from the GUI.');
    }

    private function compressFile(string $file): string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->secureRemove($file);
            throw new \RuntimeException('PHP ZipArchive is required for protected database backup containment.');
        }
        $zipPath = $file . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->secureRemove($file);
            throw new \RuntimeException('Unable to create the protected database backup archive.');
        }
        if (!$zip->addFile($file, basename($file)) || !$zip->close()) {
            @unlink($zipPath);
            $this->secureRemove($file);
            throw new \RuntimeException('Unable to finish the protected database backup archive.');
        }
        if (!@unlink($file)) {
            @unlink($zipPath);
            $this->secureRemove($file);
            throw new \RuntimeException('Database backup was compressed but the unencrypted temporary SQL file could not be removed.');
        }
        return $zipPath;
    }

    private function secureRemove(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $size = filesize($file);
        if (is_int($size) && $size > 0) {
            @file_put_contents($file, str_repeat("\0", min($size, 1048576)), LOCK_EX);
        }
        @unlink($file);
    }

    private function availablePath(string $base, string $extension): string
    {
        $candidate = $base . $extension;
        if (!file_exists($candidate)) {
            return $candidate;
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $base . '-' . bin2hex(random_bytes(4)) . $extension;
            if (!file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique backup archive name.');
    }

    private function addToZip(ZipArchive $zip, string $path, string $localName): void
    {
        $backupRoot = realpath(EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups'));
        $real = realpath($path);
        if ($backupRoot && $real && ($real === $backupRoot || str_starts_with($real, $backupRoot . DIRECTORY_SEPARATOR))) {
            return;
        }
        if (is_file($path)) {
            $zip->addFile($path, $localName);
            return;
        }
        $zip->addEmptyDir($localName);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->addToZip($zip, $path . DIRECTORY_SEPARATOR . $entry, $localName . '/' . $entry);
        }
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
            if (
                $name === ''
                || str_starts_with($name, '/')
                || preg_match('/^[A-Za-z]:\//', $name)
                || in_array('..', explode('/', $name), true)
                || preg_match('#(?:^|/)\.(?:git|agents)(?:/|$)#i', $name)
            ) {
                throw new \RuntimeException('Backup contains an unsafe archive entry.');
            }

            $operations = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $operations, $attributes)) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new \RuntimeException('Backup contains a symbolic link.');
                }
            }
        }
    }
}
