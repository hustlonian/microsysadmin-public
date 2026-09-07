<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Support\DotenvDocument;
use SysAdmin\Doctor\sdk\Support\FileSnapshotManager;

final readonly class EnvFilePrimitive implements RepairPrimitive
{
    public function __construct(private FileSnapshotManager $snapshots = new FileSnapshotManager())
    {
    }

    public function types(): array
    {
        return ['set_env_value'];
    }

    public function descriptor(string $type): array
    {
        return [
            'type' => $type,
            'category' => 'configuration',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => 'secret-safe exact file snapshot with post-repair hash guard',
            'internal_backup' => true,
            'safety_class' => 'allowlisted-production-dotenv-transition',
            'required_fields' => ['repair_id', 'path', 'key', 'value', 'guard_key', 'guard_value'],
            'allowed_key_values' => ['APP_DEBUG' => ['false']],
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $repairId = strtoupper((string) ($action['repair_id'] ?? ''));
        $path = (string) ($action['path'] ?? '');
        $key = (string) ($action['key'] ?? '');
        $value = strtolower((string) ($action['value'] ?? ''));
        if ($repairId !== 'SECURITY-0007'
            || $key !== 'APP_DEBUG'
            || $value !== 'false'
            || ($action['guard_key'] ?? null) !== 'APP_ENV'
            || strtolower((string) ($action['guard_value'] ?? '')) !== 'production') {
            throw new \RuntimeException('Dotenv primitive action is outside the reviewed production-debug transition.');
        }
        $project = realpath($context->projectPath);
        if ($project === false) {
            throw new \RuntimeException('Dotenv repair project root is unavailable.');
        }
        $expectedPath = $project . DIRECTORY_SEPARATOR . '.env';
        $path = $context->filesystem->normalize($path, true, true);
        $metadata = $context->filesystem->metadata($path);
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : (int) ($metadata['uid'] ?? -2);
        $effectiveGid = function_exists('posix_getegid') ? posix_getegid() : (int) ($metadata['gid'] ?? -2);
        if ($path !== $expectedPath
            || !is_file($path)
            || is_link($path)
            || !is_writable($path)
            || (int) ($metadata['uid'] ?? -1) !== $effectiveUid
            || (int) ($metadata['gid'] ?? -1) !== $effectiveGid) {
            throw new \RuntimeException('Dotenv repair requires an execution-account-owned writable project .env file.');
        }
        $before = $context->filesystem->read($path);
        $guard = DotenvDocument::assignments($before, 'APP_ENV');
        $target = DotenvDocument::assignments($before, 'APP_DEBUG');
        if (count($guard) !== 1 || strtolower($guard[0]['value']) !== 'production') {
            throw new \RuntimeException('Dotenv repair is applicable only to an unambiguous APP_ENV=production document.');
        }
        if (count($target) !== 1 || !in_array(strtolower($target[0]['value']), ['true', '1', 'yes', 'on'], true)) {
            throw new \RuntimeException('Dotenv repair requires one exact enabled APP_DEBUG assignment.');
        }
        $snapshot = $this->snapshots->capture($context, $repairId, $path);
        $after = DotenvDocument::replaceSingle($before, 'APP_DEBUG', 'false');
        $afterHash = hash('sha256', $after);
        try {
            $context->filesystem->atomicWrite($path, $after, (int) ($metadata['mode'] ?? 0640));
            clearstatcache(true, $path);
            if (!hash_equals($afterHash, (string) hash_file('sha256', $path))
                || count(DotenvDocument::assignments($context->filesystem->read($path), 'APP_DEBUG')) !== 1
                || strtolower(DotenvDocument::assignments($context->filesystem->read($path), 'APP_DEBUG')[0]['value']) !== 'false') {
                throw new \RuntimeException('Dotenv repair failed independent file and value verification.');
            }
        } catch (\Throwable $exception) {
            if (is_file($path) && !is_link($path) && hash_equals($afterHash, (string) hash_file('sha256', $path))) {
                $this->snapshots->restore($snapshot, $context, $afterHash);
            }
            throw $exception;
        }
        return [
            'type' => 'env_value_update',
            'path' => $path,
            'key' => 'APP_DEBUG',
            'before_state' => 'enabled',
            'after_state' => 'disabled',
            'after_sha256' => $afterHash,
            'snapshot' => $snapshot,
            'description' => $action['description'] ?? 'Disabled production application debug output.',
        ];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'env_value_update') {
            return false;
        }
        $this->snapshots->restore((array) ($change['snapshot'] ?? []), $context, (string) ($change['after_sha256'] ?? ''));
        return true;
    }
}
