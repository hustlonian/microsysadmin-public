<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Support;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Support\Value\BackupResult;

/** Exact, secret-safe single-file snapshots for semantic SDK primitives. */
final readonly class FileSnapshotManager
{
    /** @return array<string, mixed> */
    public function capture(DoctorContext $context, string $repairId, string $path, int $maximumBytes = 1048576): array
    {
        $path = $context->filesystem->normalize($path, true, true);
        if (!preg_match('/^[A-Z]+-[0-9]{4}$/', $repairId)
            || !is_file($path)
            || is_link($path)
            || !is_readable($path)
            || filesize($path) === false
            || filesize($path) > $maximumBytes) {
            throw new \RuntimeException('File snapshot requires one bounded readable regular file and a permanent repair ID.');
        }
        $backup = $context->backups->capture($context->executionId, $repairId, [$path]);
        $records = (array) $backup->get('records', []);
        $record = count($records) === 1 ? (array) $records[0] : [];
        $metadata = (array) ($record['metadata'] ?? []);
        $backupPath = (string) ($record['backup_path'] ?? '');
        $manifest = (string) $backup->get('manifest', '');
        if (($metadata['path'] ?? null) !== $path
            || ($metadata['type'] ?? null) !== 'file'
            || !is_file($backupPath)
            || is_link($backupPath)
            || !is_file($manifest)
            || is_link($manifest)) {
            throw new \RuntimeException('File snapshot manager received an invalid backup record.');
        }
        $backupHash = hash_file('sha256', $backupPath);
        $manifestHash = hash_file('sha256', $manifest);
        if (!is_string($backupHash)
            || !is_string($manifestHash)
            || !hash_equals((string) ($metadata['sha256'] ?? ''), $backupHash)
            || !hash_equals((string) ($record['backup_sha256'] ?? ''), $backupHash)) {
            throw new \RuntimeException('File snapshot hashes failed validation.');
        }
        return [
            'backup' => $backup->toArray(),
            'target_path' => $path,
            'target_sha256' => $backupHash,
            'target_mode' => (int) ($metadata['mode'] ?? 0),
            'target_uid' => (int) ($metadata['uid'] ?? -1),
            'target_gid' => (int) ($metadata['gid'] ?? -1),
            'target_mtime' => (int) filemtime($path),
            'backup_path' => $backupPath,
            'backup_sha256' => $backupHash,
            'manifest' => $manifest,
            'manifest_sha256' => $manifestHash,
        ];
    }

    /** Restore only while the target is still in the exact recorded post-repair state. */
    public function restore(array $snapshot, DoctorContext $context, ?string $expectedCurrentHash): void
    {
        $target = (string) ($snapshot['target_path'] ?? '');
        $backupPath = (string) ($snapshot['backup_path'] ?? '');
        $manifest = (string) ($snapshot['manifest'] ?? '');
        $backupHash = strtolower((string) ($snapshot['backup_sha256'] ?? ''));
        $manifestHash = strtolower((string) ($snapshot['manifest_sha256'] ?? ''));
        $originalHash = strtolower((string) ($snapshot['target_sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $backupHash)
            || !preg_match('/^[a-f0-9]{64}$/', $manifestHash)
            || !preg_match('/^[a-f0-9]{64}$/', $originalHash)
            || !is_file($backupPath)
            || is_link($backupPath)
            || !is_file($manifest)
            || is_link($manifest)
            || dirname($backupPath) !== dirname($manifest)) {
            throw new \RuntimeException('File snapshot rollback reference is invalid.');
        }
        if (!hash_equals($backupHash, (string) hash_file('sha256', $backupPath))
            || !hash_equals($manifestHash, (string) hash_file('sha256', $manifest))) {
            throw new \RuntimeException('File snapshot rollback reference changed after capture.');
        }
        if ($expectedCurrentHash === null) {
            if (file_exists($target) || is_link($target)) {
                throw new \RuntimeException('File snapshot rollback refuses to overwrite a newly created path.');
            }
        } else {
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedCurrentHash)
                || !is_file($target)
                || is_link($target)
                || !hash_equals($expectedCurrentHash, (string) hash_file('sha256', $target))) {
                throw new \RuntimeException('File snapshot rollback refuses a target changed after repair.');
            }
        }
        $backup = new BackupResult((array) ($snapshot['backup'] ?? []));
        $records = (array) $backup->get('records', []);
        $record = count($records) === 1 ? (array) $records[0] : [];
        if (($record['backup_path'] ?? null) !== $backupPath
            || (($record['metadata']['path'] ?? null) !== $target)
            || (($record['metadata']['sha256'] ?? null) !== $originalHash)) {
            throw new \RuntimeException('File snapshot rollback manifest does not match the captured target.');
        }
        $context->backups->restore($backup);
        if (!touch($target, (int) ($snapshot['target_mtime'] ?? 0))) {
            throw new \RuntimeException('File snapshot rollback could not restore the original modification time.');
        }
        clearstatcache(true, $target);
        if (!is_file($target)
            || is_link($target)
            || !hash_equals($originalHash, (string) hash_file('sha256', $target))
            || ((fileperms($target) & 07777) !== (int) ($snapshot['target_mode'] ?? -1))
            || fileowner($target) !== (int) ($snapshot['target_uid'] ?? -1)
            || filegroup($target) !== (int) ($snapshot['target_gid'] ?? -1)
            || filemtime($target) !== (int) ($snapshot['target_mtime'] ?? -1)) {
            throw new \RuntimeException('File snapshot rollback failed independent restoration verification.');
        }
    }
}
