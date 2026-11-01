<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Backup;

use SysAdmin\Doctor\engine\Filesystem\SafeFilesystem;
use SysAdmin\Doctor\engine\Support\Value\BackupResult;

final readonly class BackupManager
{
    public function __construct(private SafeFilesystem $filesystem, private string $root)
    {
    }

    public function capture(string $executionId, string $repairId, array $paths, string $captureId = ''): BackupResult
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $executionId . DIRECTORY_SEPARATOR . $repairId . ($captureId === '' ? '' : '-' . $captureId);
        $this->filesystem->createDirectory($this->root . DIRECTORY_SEPARATOR . $executionId, 0750);
        $this->filesystem->createDirectory($directory, 0750);
        if (is_file($directory . '/manifest.json')) { throw new \RuntimeException('Backup identity already exists; prior evidence must not be overwritten.'); }
        $records = [];
        foreach (array_values(array_unique($paths)) as $index => $path) {
            $metadata = $this->snapshot((string) $path);
            $record = ['metadata' => $metadata, 'backup_path' => null];
            if ($metadata['type'] === 'file') {
                $destination = $directory . DIRECTORY_SEPARATOR . sprintf('%04d-%s', $index, basename((string) $path));
                $this->filesystem->atomicWrite($destination, $this->filesystem->read((string) $path), 0640);
                $record['backup_path'] = $destination;
                $record['backup_sha256'] = hash_file('sha256', $destination);
                if (!hash_equals((string) $metadata['sha256'], (string) $record['backup_sha256'])) {
                    throw new \RuntimeException('Doctor backup validation failed.');
                }
            }
            $records[] = $record;
        }
        $manifest = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->filesystem->atomicWrite($manifest, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0640);
        return new BackupResult([
            'successful' => true,
            'execution_id' => $executionId,
            'repair_id' => $repairId,
            'manifest' => $manifest,
            'records' => $records,
        ]);
    }

    public function none(string $executionId, string $repairId): BackupResult
    {
        return new BackupResult(['successful' => true, 'execution_id' => $executionId, 'repair_id' => $repairId, 'strategy' => 'none-required', 'records' => []]);
    }

    /** Metadata without following a link; absence is an explicit recovery fact. */
    public function snapshot(string $path): array
    {
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) { return $this->filesystem->metadata($path); }
        if (!str_starts_with($path, '/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) { throw new \RuntimeException('Invalid backup path.'); }
        $ancestor = dirname($path);
        while (!file_exists($ancestor) && !is_link($ancestor) && dirname($ancestor) !== $ancestor) { $ancestor = dirname($ancestor); }
        $this->filesystem->normalize($ancestor);
        if (is_link($ancestor)) { throw new \RuntimeException('Backup ancestor is symbolic.'); }
        return ['path' => $path, 'type' => 'absent'];
    }

    public function verify(BackupResult $backup): bool
    {
        foreach ((array)$backup->get('records', []) as $record) {
            if ($this->snapshot((string)$record['metadata']['path']) !== $record['metadata']) { return false; }
        }
        return true;
    }

    /** Restore every record in reverse order; continue independent records after a failure. */
    public function restore(BackupResult $backup, array $expectedCurrent = []): bool
    {
        $errors = [];
        foreach (array_reverse((array)$backup->get('records', [])) as $record) {
            try {
                $before = $record['metadata']; $target = (string)$before['path'];
                $current = $this->snapshot($target);
                if ($current === $before) { continue; }
                if (isset($expectedCurrent[$target]) && $current !== $expectedCurrent[$target]) {
                    throw new \RuntimeException('Rollback refuses a target changed after its recorded action.');
                }
                if ($before['type'] === 'absent') {
                    if ($current['type'] === 'file') { $this->filesystem->removeFile($target, (string)$current['sha256']); }
                    elseif ($current['type'] === 'directory') { $this->filesystem->removeEmptyDirectory($target); }
                    elseif ($current['type'] === 'link') { $this->filesystem->removeSymlink($target); }
                } elseif ($before['type'] === 'file') {
                    $source = (string)($record['backup_path'] ?? '');
                    $contents = $this->filesystem->read($source);
                    if (!hash_equals((string)$before['sha256'], hash('sha256', $contents))
                        || !hash_equals((string)($record['backup_sha256'] ?? ''), hash('sha256', $contents))) {
                        throw new \RuntimeException('Backup content integrity failed; restoration refused.');
                    }
                    if (!in_array($current['type'], ['file', 'absent'], true)) { throw new \RuntimeException('Backup target type changed.'); }
                    $this->filesystem->atomicWrite($target, $contents, (int)$before['mode']);
                } elseif ($before['type'] === 'directory' && $current['type'] === 'directory') {
                    $this->filesystem->chmod($target, (int)$before['mode']);
                } elseif ($before['type'] === 'link') {
                    if ($current['type'] === 'link') { $this->filesystem->removeSymlink($target); }
                    elseif ($current['type'] !== 'absent') { throw new \RuntimeException('Link rollback refuses a replacement non-link.'); }
                    $this->filesystem->normalize($target, false, true);
                    if (!symlink((string)$before['link_target'], $target)) { throw new \RuntimeException('Unable to restore prior link.'); }
                } else { throw new \RuntimeException('Unsupported backup target transition.'); }
                if ($this->snapshot($target) !== $before) { throw new \RuntimeException('Restored bytes, type, permissions or ownership differ.'); }
            } catch (\Throwable $error) { $errors[] = $error->getMessage(); }
        }
        if ($errors !== []) { throw new \RuntimeException(implode('; ', $errors)); }
        return $this->verify($backup);
    }
}
