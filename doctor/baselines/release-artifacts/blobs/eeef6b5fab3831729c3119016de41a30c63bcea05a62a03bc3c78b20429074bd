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

    public function capture(string $executionId, string $repairId, array $paths): BackupResult
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $executionId . DIRECTORY_SEPARATOR . $repairId;
        $this->filesystem->createDirectory($this->root . DIRECTORY_SEPARATOR . $executionId, 0750);
        $this->filesystem->createDirectory($directory, 0750);
        $records = [];
        foreach (array_values(array_unique($paths)) as $index => $path) {
            $metadata = $this->filesystem->metadata((string) $path);
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

    public function restore(BackupResult $backup): bool
    {
        foreach ((array) $backup->get('records', []) as $record) {
            $metadata = (array) ($record['metadata'] ?? []);
            $source = (string) ($record['backup_path'] ?? '');
            $target = (string) ($metadata['path'] ?? '');
            if ($source !== '' && $target !== '') {
                $this->filesystem->atomicWrite($target, $this->filesystem->read($source), (int) ($metadata['mode'] ?? 0640));
            } elseif ($target !== '' && file_exists($target)) {
                $this->filesystem->chmod($target, (int) ($metadata['mode'] ?? 0750));
            }
        }
        return true;
    }
}
