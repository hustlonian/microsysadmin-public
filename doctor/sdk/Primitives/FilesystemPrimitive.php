<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Filesystem\ManagedArtifactCatalog;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Support\PathGuard;

final readonly class FilesystemPrimitive implements RepairPrimitive
{
    public function __construct(private ManagedArtifactCatalog $artifacts)
    {
    }

    public function types(): array
    {
        return ['atomic_write', 'chmod', 'copy_file', 'create_directory', 'create_symlink', 'repair_symlink', 'restore_managed_artifact', 'restore_managed_directory'];
    }

    public function descriptor(string $type): array
    {
        $rollback = match ($type) {
            'create_directory' => 'reverse identity-and-mode-guarded removal of helper-created empty directory chain',
            'copy_file', 'create_symlink', 'repair_symlink', 'restore_managed_artifact', 'restore_managed_directory' => 'automatic',
            'atomic_write', 'chmod' => 'backup-manager',
            default => 'unsupported',
        };
        return [
            'type' => $type,
            'category' => 'filesystem',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => $rollback,
            'internal_backup' => in_array($type, ['copy_file', 'create_directory', 'create_symlink', 'repair_symlink', 'restore_managed_artifact', 'restore_managed_directory'], true),
            'safety_class' => match ($type) {
                'restore_managed_artifact', 'restore_managed_directory' => 'exact-release-manifest',
                'create_directory' => 'bounded-identity-guarded-directory-chain',
                default => 'bounded-path',
            },
            'required_fields' => match ($type) {
                'create_directory' => ['path'],
                'create_symlink' => ['path', 'target'],
                'repair_symlink' => ['path', 'target'],
                'copy_file' => ['source', 'path'],
                'chmod' => ['path', 'mode'],
                'atomic_write' => ['path', 'contents'],
                'restore_managed_artifact' => ['repair_id', 'path', 'baseline', 'sha256', 'mode'],
                'restore_managed_directory' => ['repair_id', 'path', 'mode', 'condition'],
                default => [],
            },
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        return match ((string) ($action['type'] ?? '')) {
            'create_directory' => $this->createDirectory($action, $context),
            'create_symlink' => $this->createSymlink($action, $context),
            'repair_symlink' => $this->repairSymlink($action, $context),
            'copy_file' => $this->copyFile($action, $context),
            'chmod' => $this->chmod($action, $context),
            'atomic_write' => $this->atomicWrite($action, $context),
            'restore_managed_artifact' => $this->restoreManagedArtifact($action, $context),
            'restore_managed_directory' => $this->restoreManagedDirectory($action, $context),
            default => throw new \RuntimeException('Filesystem primitive received an unsupported action.'),
        };
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        $type = (string) ($change['type'] ?? '');
        if (!in_array($type, ['directory', 'symlink', 'repaired_symlink', 'managed_artifact', 'managed_directory', 'file', 'permissions'], true)) {
            return false;
        }
        if ($type === 'repaired_symlink') {
            if (($change['changed'] ?? true) === false) {
                return true;
            }
            $path = (string) ($change['path'] ?? '');
            $afterTarget = (string) ($change['after_target'] ?? '');
            if (!is_link($path) || readlink($path) !== $afterTarget) {
                throw new \RuntimeException('Repaired symlink changed after repair; rollback refused.');
            }
            $context->filesystem->removeSymlink($path);
            if (!empty($change['existed'])) {
                $project = realpath($context->projectPath);
                $beforeTarget = (string) ($change['before_target'] ?? '');
                if ($project === false || !PathGuard::linkTargetStaysWithin($project, $path, $beforeTarget)) {
                    throw new \RuntimeException('Captured symlink rollback target is outside the managed project.');
                }
                if (!symlink($beforeTarget, $path)) {
                    throw new \RuntimeException('Unable to restore the captured project symlink.');
                }
                if (readlink($path) !== $beforeTarget) {
                    throw new \RuntimeException('Captured project symlink failed rollback verification.');
                }
            }
            return true;
        }
        if (empty($change['created'])) {
            if ($type === 'managed_directory' && !empty($change['mode_changed'])) {
                $path = (string) ($change['path'] ?? '');
                if ($this->directoryIdentity($path) !== (string) ($change['identity'] ?? '')
                    || (fileperms($path) & 0777) !== (int) ($change['after_mode'] ?? 0)) {
                    throw new \RuntimeException('Managed-directory mode rollback refuses a changed target.');
                }
                $context->filesystem->chmod($path, (int) ($change['before_mode'] ?? 0));
                clearstatcache(true, $path);
            }
            return true; // BackupManager owns restoration for pre-existing paths.
        }
        if ($type === 'directory') {
            $directories = (array) ($change['created_directories'] ?? []);
            if ($directories !== []) {
                $this->rollbackCreatedDirectories($directories, $context);
            } else {
                // Backward compatibility for an execution journal produced by
                // the original single-directory primitive contract.
                $context->filesystem->removeEmptyDirectory((string) ($change['path'] ?? ''));
            }
        } elseif ($type === 'symlink') {
            $context->filesystem->removeSymlink((string) ($change['path'] ?? ''));
        } elseif (in_array($type, ['managed_artifact', 'file'], true)) {
            $context->filesystem->removeFile((string) ($change['path'] ?? ''), (string) ($change['after_sha256'] ?? ''));
            $this->rollbackCreatedDirectories((array) ($change['created_directories'] ?? []), $context);
        } elseif ($type === 'managed_directory') {
            $this->rollbackCreatedDirectories((array) ($change['created_directories'] ?? []), $context);
        }
        return true;
    }

    private function createDirectory(array $action, DoctorContext $context): array
    {
        $requested = rtrim((string) ($action['path'] ?? ''), DIRECTORY_SEPARATOR);
        $mode = (int) ($action['mode'] ?? 0775);
        if ($requested === '' || file_exists($requested) || is_link($requested)) {
            if (is_dir($requested) && !is_link($requested)) {
                return [
                    'type' => 'directory', 'path' => $requested, 'created' => false,
                    'created_directories' => [],
                    'description' => $action['description'] ?? 'Verified directory.',
                ];
            }
            throw new \RuntimeException('Directory creation is obstructed by an existing path.');
        }

        $segments = [];
        $ancestor = $requested;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $segment = basename($ancestor);
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new \RuntimeException('Directory creation contains an unsafe path segment.');
            }
            array_unshift($segments, $segment);
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new \RuntimeException('Directory creation has no existing bounded ancestor.');
            }
            $ancestor = $parent;
        }
        if (!is_dir($ancestor) || is_link($ancestor)) {
            throw new \RuntimeException('Directory creation ancestor is unavailable or symbolic.');
        }
        $cursor = $context->filesystem->normalize($ancestor, true, true);
        $createdDirectories = [];
        try {
            foreach ($segments as $segment) {
                $cursor .= DIRECTORY_SEPARATOR . $segment;
                if (is_dir($cursor) && !is_link($cursor)) {
                    continue; // A concurrent creator reached this exact path.
                }
                if (file_exists($cursor) || is_link($cursor)) {
                    throw new \RuntimeException('Directory creation became obstructed by an existing path.');
                }
                $context->filesystem->createDirectory($cursor, $mode);
                clearstatcache(true, $cursor);
                $createdDirectories[] = [
                    'path' => $cursor,
                    'identity' => $this->directoryIdentity($cursor),
                    'mode' => $mode,
                ];
            }
        } catch (\Throwable $exception) {
            $this->rollbackCreatedDirectories($createdDirectories, $context);
            throw $exception;
        }
        return [
            'type' => 'directory', 'path' => $cursor,
            'created' => $createdDirectories !== [],
            'created_directories' => $createdDirectories,
            'description' => $action['description'] ?? 'Created a bounded directory chain.',
        ];
    }

    private function createSymlink(array $action, DoctorContext $context): array
    {
        $path = (string) ($action['path'] ?? '');
        $existed = is_link($path);
        $context->filesystem->createSymlink((string) ($action['target'] ?? ''), $path);
        return ['type' => 'symlink', 'path' => $path, 'target' => $action['target'] ?? '', 'created' => !$existed, 'description' => $action['description'] ?? 'Created symbolic link.'];
    }

    private function repairSymlink(array $action, DoctorContext $context): array
    {
        $project = realpath($context->projectPath);
        $target = realpath((string) ($action['target'] ?? ''));
        $path = (string) ($action['path'] ?? '');
        if ($project === false || $target === false || ($target !== $project && !str_starts_with($target, $project . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Symlink-repair target is outside the managed project or unavailable.');
        }
        $normalizedPath = $context->filesystem->normalize($path, !(!file_exists($path) && !is_link($path)), true);
        if (file_exists($normalizedPath) && !is_link($normalizedPath)) {
            throw new \RuntimeException('Symlink repair refuses to replace a non-link path.');
        }
        $existed = is_link($normalizedPath);
        $beforeTarget = $existed ? readlink($normalizedPath) : null;
        if ($existed && (!is_string($beforeTarget) || !PathGuard::linkTargetStaysWithin($project, $normalizedPath, $beforeTarget))) {
            throw new \RuntimeException('Symlink repair refuses an existing link that escapes the managed project.');
        }
        if ($existed && realpath($normalizedPath) === $target) {
            return [
                'type' => 'repaired_symlink', 'path' => $normalizedPath, 'existed' => true,
                'changed' => false, 'before_target' => $beforeTarget, 'after_target' => $beforeTarget,
                'description' => $action['description'] ?? 'Verified the project symlink target.',
            ];
        }
        if ($existed) {
            $context->filesystem->removeSymlink($normalizedPath);
        }
        try {
            $context->filesystem->createSymlink($target, $normalizedPath);
        } catch (\Throwable $exception) {
            if ($existed && is_string($beforeTarget) && !file_exists($normalizedPath) && !is_link($normalizedPath)) {
                @symlink($beforeTarget, $normalizedPath);
            }
            throw $exception;
        }
        $afterTarget = readlink($normalizedPath);
        if (!is_string($afterTarget) || realpath($normalizedPath) !== $target) {
            throw new \RuntimeException('Symlink repair failed independent target validation.');
        }
        return [
            'type' => 'repaired_symlink', 'path' => $normalizedPath, 'existed' => $existed,
            'changed' => true, 'before_target' => $beforeTarget, 'after_target' => $afterTarget,
            'description' => $action['description'] ?? 'Repaired one bounded project symlink.',
        ];
    }

    private function chmod(array $action, DoctorContext $context): array
    {
        $result = $context->filesystem->chmod((string) ($action['path'] ?? ''), (int) ($action['mode'] ?? 0750));
        return ['type' => 'permissions', 'path' => $action['path'] ?? '', 'created' => false, 'metadata' => $result, 'description' => $action['description'] ?? 'Changed scoped permissions.'];
    }

    private function copyFile(array $action, DoctorContext $context): array
    {
        $source = (string) ($action['source'] ?? '');
        $path = (string) ($action['path'] ?? '');
        if (!is_file($source) || is_link($source)) {
            throw new \RuntimeException('Copy-file primitive source is unavailable or symbolic.');
        }
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Copy-file primitive refuses to replace an existing path.');
        }
        $contents = $context->filesystem->read($source);
        $mode = (int) ($action['mode'] ?? 0640);
        $context->filesystem->atomicWrite($path, $contents, $mode);
        $afterSha = hash('sha256', $contents);
        return [
            'type' => 'file', 'path' => $path, 'source' => $source, 'created' => true,
            'after_sha256' => $afterSha, 'mode' => sprintf('%04o', $mode),
            'description' => $action['description'] ?? 'Created one file from an exact local template.',
        ];
    }

    private function atomicWrite(array $action, DoctorContext $context): array
    {
        $path = (string) ($action['path'] ?? '');
        $existed = is_file($path);
        $before = $existed ? $context->filesystem->read($path) : '';
        $contents = (string) ($action['contents'] ?? '');
        $context->filesystem->atomicWrite($path, $contents, isset($action['mode']) ? (int) $action['mode'] : null);
        $afterSha = hash('sha256', $contents);
        return [
            'type' => 'file',
            'path' => $path,
            'created' => !$existed,
            'after_sha256' => $afterSha,
            'diff' => $context->filesystem->diff($before, $contents),
            'description' => $action['description'] ?? 'Wrote configuration atomically.',
        ];
    }

    private function restoreManagedArtifact(array $action, DoctorContext $context): array
    {
        $authorized = $this->artifacts->authorize(
            (string) ($action['repair_id'] ?? ''),
            (string) ($action['path'] ?? ''),
            (string) ($action['baseline'] ?? ''),
            strtolower((string) ($action['sha256'] ?? '')),
            (int) ($action['mode'] ?? 0),
        );
        $path = $authorized['target_path'];
        if ((file_exists($path) || is_link($path)) && (!is_file($path) || is_link($path))) {
            throw new \RuntimeException('Managed-artifact repair refuses a non-regular or symbolic target.');
        }
        $created = !is_file($path);
        $beforeHash = $created ? null : hash_file('sha256', $path);
        $beforeContents = $created ? null : $context->filesystem->read($path);
        $beforeMode = $created ? null : (fileperms($path) & 0777);
        $contents = $context->filesystem->read($authorized['baseline_path']);
        if (!hash_equals($authorized['sha256'], hash('sha256', $contents))) {
            throw new \RuntimeException('Managed-artifact baseline changed after authorization.');
        }
        $createdDirectories = $this->createMissingManagedDirectories(
            $this->artifacts->parentDirectoriesForArtifact($authorized['repair_id']),
            $context,
        );
        try {
            $context->filesystem->atomicWrite($path, $contents, $authorized['mode']);
        } catch (\Throwable $exception) {
            $this->rollbackCreatedDirectories($createdDirectories, $context);
            throw $exception;
        }
        clearstatcache(true, $path);
        $afterHash = hash_file('sha256', $path);
        $afterMode = fileperms($path) & 0777;
        if (!is_string($afterHash) || !hash_equals($authorized['sha256'], $afterHash) || $afterMode !== $authorized['mode']) {
            if (is_string($afterHash) && hash_equals($authorized['sha256'], $afterHash)) {
                if ($created) {
                    $context->filesystem->removeFile($path, $authorized['sha256']);
                    $this->rollbackCreatedDirectories($createdDirectories, $context);
                } elseif (is_string($beforeContents) && is_int($beforeMode)) {
                    $context->filesystem->atomicWrite($path, $beforeContents, $beforeMode);
                    clearstatcache(true, $path);
                    $restoredHash = hash_file('sha256', $path);
                    if (!is_string($restoredHash) || !is_string($beforeHash) || !hash_equals($beforeHash, $restoredHash)
                        || (fileperms($path) & 0777) !== $beforeMode) {
                        throw new \RuntimeException('Managed-artifact automatic failure compensation could not verify the prior state.');
                    }
                }
            }
            throw new \RuntimeException('Managed-artifact atomic restoration failed independent validation.');
        }
        return [
            'type' => 'managed_artifact', 'path' => $path, 'created' => $created,
            'before_sha256' => $beforeHash, 'after_sha256' => $afterHash,
            'mode' => sprintf('%04o', $afterMode),
            'created_directories' => $createdDirectories,
            'description' => $action['description'] ?? 'Restored one managed release artifact from its immutable local baseline.',
        ];
    }

    private function restoreManagedDirectory(array $action, DoctorContext $context): array
    {
        $authorized = $this->artifacts->authorizeDirectory(
            (string) ($action['repair_id'] ?? ''),
            (string) ($action['path'] ?? ''),
            (int) ($action['mode'] ?? 0),
            (string) ($action['condition'] ?? ''),
        );
        $path = $authorized['target_path'];
        if (is_dir($path) && !is_link($path)) {
            $identity = $this->directoryIdentity($path);
            $beforeMode = fileperms($path) & 0777;
            $changed = $authorized['condition'] === 'mode' && $beforeMode !== $authorized['mode'];
            if ($changed) {
                $context->filesystem->chmod($path, $authorized['mode']);
                clearstatcache(true, $path);
            }
            $expectedMode = $authorized['condition'] === 'mode' ? $authorized['mode'] : $beforeMode;
            if ($this->directoryIdentity($path) !== $identity || (fileperms($path) & 0777) !== $expectedMode) {
                throw new \RuntimeException('Managed-directory mode restoration failed independent validation.');
            }
            return [
                'type' => 'managed_directory', 'path' => $path, 'created' => false,
                'created_directories' => [], 'identity' => $identity,
                'condition' => $authorized['condition'],
                'mode_changed' => $changed, 'before_mode' => $beforeMode, 'after_mode' => $expectedMode,
                'description' => $action['description'] ?? 'Verified or restored one managed release directory mode.',
            ];
        }
        if ($authorized['condition'] === 'mode') {
            throw new \RuntimeException('Managed-directory mode repair became inapplicable because the directory is missing.');
        }
        $created = $this->createMissingManagedDirectories(
            $this->artifacts->directoryChainForRepair($authorized['repair_id']),
            $context,
        );
        return [
            'type' => 'managed_directory', 'path' => $path, 'created' => true,
            'created_directories' => $created, 'identity' => $this->directoryIdentity($path),
            'condition' => $authorized['condition'],
            'mode_changed' => false, 'before_mode' => null, 'after_mode' => $authorized['mode'],
            'description' => $action['description'] ?? 'Restored one managed release directory and its missing manifest parents.',
        ];
    }

    /** @param list<array{repair_id:string,path:string,mode:int,target_path:string}> $entries @return list<array{path:string,identity:string,mode:int}> */
    private function createMissingManagedDirectories(array $entries, DoctorContext $context, ?array $target = null): array
    {
        if ($target !== null && !in_array($target['path'], array_column($entries, 'path'), true)) {
            $entries[] = $target;
            usort($entries, static fn (array $left, array $right): int => substr_count($left['path'], '/') <=> substr_count($right['path'], '/'));
        }
        $created = [];
        try {
            foreach ($entries as $entry) {
                $path = (string) $entry['target_path'];
                if (is_dir($path) && !is_link($path)) {
                    continue;
                }
                if (file_exists($path) || is_link($path)) {
                    throw new \RuntimeException('Managed-directory creation is obstructed by an existing path.');
                }
                $context->filesystem->createDirectory($path, (int) $entry['mode']);
                $created[] = ['path' => $path, 'identity' => $this->directoryIdentity($path), 'mode' => (int) $entry['mode']];
            }
            return $created;
        } catch (\Throwable $exception) {
            $this->rollbackCreatedDirectories($created, $context);
            throw $exception;
        }
    }

    /** @param list<array{path:string,identity:string,mode:int}> $directories */
    private function rollbackCreatedDirectories(array $directories, DoctorContext $context): void
    {
        foreach (array_reverse($directories) as $directory) {
            $path = (string) ($directory['path'] ?? '');
            clearstatcache(true, $path);
            if ($this->directoryIdentity($path) !== (string) ($directory['identity'] ?? '')
                || (fileperms($path) & 0777) !== (int) ($directory['mode'] ?? 0)) {
                throw new \RuntimeException('Directory rollback refuses a changed directory identity or mode.');
            }
            $context->filesystem->removeEmptyDirectory($path);
        }
    }

    private function directoryIdentity(string $path): string
    {
        $stat = lstat($path);
        if ($stat === false || !is_dir($path) || is_link($path)) {
            return '';
        }
        return (int) $stat['dev'] . ':' . (int) $stat['ino'];
    }

}
