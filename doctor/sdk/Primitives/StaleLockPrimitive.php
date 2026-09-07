<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Support\FileSnapshotManager;

final readonly class StaleLockPrimitive implements RepairPrimitive
{
    public function __construct(private FileSnapshotManager $snapshots = new FileSnapshotManager())
    {
    }

    public function types(): array
    {
        return ['quarantine_stale_git_lock'];
    }

    public function descriptor(string $type): array
    {
        return [
            'type' => $type,
            'category' => 'git',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => 'exact lock snapshot restored only while the repaired path remains absent',
            'internal_backup' => true,
            'safety_class' => 'aged-unowned-exact-git-index-lock',
            'required_fields' => ['repair_id', 'path', 'minimum_age_seconds'],
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $repairId = strtoupper((string) ($action['repair_id'] ?? ''));
        $path = (string) ($action['path'] ?? '');
        $minimumAge = max(300, min(604800, (int) ($action['minimum_age_seconds'] ?? 3600)));
        if ($repairId !== 'GIT-0007') {
            throw new \RuntimeException('Stale-lock primitive requires its reviewed permanent repair ID.');
        }
        $project = realpath($context->projectPath);
        if ($project === false || realpath($project . '/.git') === false) {
            throw new \RuntimeException('Stale-lock primitive requires an existing project Git repository.');
        }
        $path = $context->filesystem->normalize($path, true, true);
        if ($path !== $project . '/.git/index.lock') {
            throw new \RuntimeException('Stale-lock primitive refuses every path except the project Git index lock.');
        }
        $before = $this->inspect($path, $minimumAge, $context);
        $snapshot = $this->snapshots->capture($context, $repairId, $path, 67108864);
        $rechecked = $this->inspect($path, $minimumAge, $context);
        foreach (['dev', 'ino', 'size', 'mtime', 'sha256'] as $field) {
            if (($before[$field] ?? null) !== ($rechecked[$field] ?? null)) {
                throw new \RuntimeException('Git index lock changed during stale-lock validation.');
            }
        }
        $context->filesystem->removeFile($path, (string) $before['sha256']);
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Stale Git index lock remains after quarantine.');
        }
        return [
            'type' => 'quarantined_stale_git_lock',
            'path' => $path,
            'before_sha256' => $before['sha256'],
            'snapshot' => $snapshot,
            'description' => $action['description'] ?? 'Quarantined one verified stale Git index lock.',
        ];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'quarantined_stale_git_lock') {
            return false;
        }
        $this->snapshots->restore((array) ($change['snapshot'] ?? []), $context, null);
        return true;
    }

    /** @return array<string, int|string> */
    private function inspect(string $path, int $minimumAge, DoctorContext $context): array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path) || !is_writable($path)) {
            throw new \RuntimeException('Git index lock is not a writable regular file.');
        }
        $stat = lstat($path);
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : (int) ($stat['uid'] ?? -2);
        $effectiveGid = function_exists('posix_getegid') ? posix_getegid() : (int) ($stat['gid'] ?? -2);
        if ($stat === false
            || (int) $stat['uid'] !== $effectiveUid
            || (int) $stat['gid'] !== $effectiveGid
            || (time() - (int) $stat['mtime']) < $minimumAge) {
            throw new \RuntimeException('Git index lock is not old enough or is not owned by the execution account.');
        }
        $lsof = $context->environment->command('lsof');
        if ($lsof === null) {
            throw new \RuntimeException('Stale-lock ownership inspection is unavailable.');
        }
        $owners = $context->process->run([$lsof, '-t', '--', $path], 15);
        if ((int) $owners->get('exit_code', -1) !== 1
            || trim((string) $owners->get('stdout')) !== ''
            || trim((string) $owners->get('stderr')) !== '') {
            throw new \RuntimeException('Git index lock is active or process ownership is inconclusive.');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash the Git index lock.');
        }
        return [
            'dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'sha256' => $hash,
        ];
    }
}
