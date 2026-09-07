<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Support\TreeSnapshotManager;

final readonly class ComposerPrimitive implements RepairPrimitive
{
    private const COMMANDS = ['install-locked', 'dump-autoload-optimized'];

    public function __construct(private TreeSnapshotManager $trees = new TreeSnapshotManager())
    {
    }

    public function types(): array
    {
        return ['composer_command'];
    }

    public function descriptor(string $type): array
    {
        return [
            'type' => $type,
            'category' => 'composer',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => 'bounded exact vendor-tree snapshot or exact created-tree removal',
            'internal_backup' => true,
            'safety_class' => 'locked-offline-no-plugins-no-scripts',
            'allowed_commands' => self::COMMANDS,
            'required_fields' => ['command'],
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $commandName = strtolower(trim((string) ($action['command'] ?? '')));
        if (!in_array($commandName, self::COMMANDS, true)) {
            throw new \RuntimeException('Composer primitive command is outside the exact allowlist.');
        }
        $project = realpath($context->projectPath);
        $composer = $context->environment->command('composer');
        if ($project === false || $composer === null || !is_file($project . '/composer.json') || is_link($project . '/composer.json')) {
            throw new \RuntimeException('Composer primitive prerequisites are unavailable.');
        }
        if ($commandName === 'install-locked' && (!is_file($project . '/composer.lock') || is_link($project . '/composer.lock'))) {
            throw new \RuntimeException('Locked Composer installation requires an exact non-symbolic composer.lock.');
        }
        $manifest = json_decode((string) file_get_contents($project . '/composer.json'), true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Composer primitive refuses an invalid project manifest.');
        }
        if ($commandName === 'install-locked') {
            $lock = json_decode((string) file_get_contents($project . '/composer.lock'), true);
            if (!is_array($lock)) {
                throw new \RuntimeException('Composer primitive refuses an invalid dependency lock.');
            }
        }

        $maxFiles = max(1, min(100000, (int) ($action['max_files'] ?? 50000)));
        $maxBytes = max(1048576, min(1073741824, (int) ($action['max_bytes'] ?? 536870912)));
        $vendor = $project . '/vendor';
        $beforeSnapshot = is_dir($vendor) && !is_link($vendor)
            ? $this->trees->capture($context, $vendor, 'composer-' . $commandName, $maxFiles, $maxBytes)
            : null;
        if (file_exists($vendor) || is_link($vendor)) {
            if ($beforeSnapshot === null) {
                throw new \RuntimeException('Composer primitive refuses a non-directory or symbolic vendor target.');
            }
        }
        $guarded = $this->captureGuardedFiles($project, $commandName);
        $home = $this->composerHome($context);
        $arguments = $commandName === 'install-locked'
            ? [$composer, 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--no-plugins', '--no-scripts']
            : [$composer, 'dump-autoload', '--optimize', '--no-interaction', '--no-plugins', '--no-scripts'];

        try {
            $result = $context->process->run(
                $arguments,
                max(30, min(900, (int) ($action['timeout'] ?? ($commandName === 'install-locked' ? 600 : 300)))),
                $project,
                [
                    'LD_LIBRARY_PATH' => '',
                    'HOME' => $home,
                    'COMPOSER_HOME' => $home,
                    'COMPOSER_CACHE_DIR' => $home . '/cache',
                    'XDG_CACHE_HOME' => $home . '/cache',
                    'COMPOSER_DISABLE_NETWORK' => '1',
                    'COMPOSER_NO_INTERACTION' => '1',
                ],
            );
            $after = $this->trees->inspect($context, $vendor, $maxFiles, $maxBytes);
            if (!$result->successful()) {
                throw new \RuntimeException($this->failureDetail($result->toArray()));
            }
            if (empty($after['exists'])) {
                throw new \RuntimeException('Composer command did not produce the bounded vendor tree.');
            }
            $this->assertGuardedFiles($guarded);
        } catch (\Throwable $exception) {
            try {
                $current = $this->trees->inspect($context, $vendor, $maxFiles, $maxBytes);
                $this->restoreVendor($context, $vendor, $beforeSnapshot, $current);
                $this->restoreGuardedFiles($context, $guarded);
            } catch (\Throwable $rollbackException) {
                throw new \RuntimeException($exception->getMessage() . ' Immediate Composer rollback also failed: ' . $rollbackException->getMessage(), 0, $exception);
            }
            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }

        return [
            'type' => 'composer',
            'command' => $commandName,
            'vendor' => $vendor,
            'before_snapshot' => $beforeSnapshot,
            'after_manifest' => $after,
            'guarded_files' => $guarded,
            'network_disabled' => true,
            'plugins_disabled' => true,
            'scripts_disabled' => true,
            'result' => $result->toArray(),
            'description' => $action['description'] ?? "Executed bounded Composer operation {$commandName}.",
        ];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'composer') {
            return false;
        }
        $this->assertGuardedFiles((array) ($change['guarded_files'] ?? []));
        $this->restoreVendor(
            $context,
            (string) ($change['vendor'] ?? ''),
            is_array($change['before_snapshot'] ?? null) ? (array) $change['before_snapshot'] : null,
            (array) ($change['after_manifest'] ?? []),
        );
        $this->assertGuardedFiles((array) ($change['guarded_files'] ?? []));
        return true;
    }

    private function composerHome(DoctorContext $context): string
    {
        $runtime = (string) $context->option('doctor_runtime_root', '');
        if ($runtime === '' || !is_dir($runtime) || is_link($runtime)) {
            throw new \RuntimeException('Composer primitive runtime root is unavailable or symbolic.');
        }
        $runtime = $context->filesystem->normalize($runtime, true, true);
        $home = $runtime . '/composer-home';
        if (!is_dir($home)) {
            $context->filesystem->createDirectory($home, 0750);
        }
        if (!is_dir($home . '/cache')) {
            $context->filesystem->createDirectory($home . '/cache', 0750);
        }
        return $home;
    }

    private function captureGuardedFiles(string $project, string $commandName): array
    {
        $paths = [$project . '/composer.json'];
        if (is_file($project . '/composer.lock')) {
            $paths[] = $project . '/composer.lock';
        } elseif ($commandName === 'install-locked') {
            throw new \RuntimeException('Locked Composer installation requires composer.lock.');
        }
        $records = [];
        foreach ($paths as $path) {
            $stat = lstat($path);
            if ($stat === false || !is_file($path) || is_link($path)) {
                throw new \RuntimeException('Composer guarded input is unavailable or unsafe.');
            }
            $contents = (string) file_get_contents($path);
            $records[] = [
                'path' => $path,
                'contents' => base64_encode($contents),
                'sha256' => hash('sha256', $contents),
                'mode' => $stat['mode'] & 0777,
                'mtime' => $stat['mtime'],
            ];
        }
        return $records;
    }

    private function assertGuardedFiles(array $records): void
    {
        foreach ($records as $record) {
            $path = (string) ($record['path'] ?? '');
            $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (!is_string($actual) || !hash_equals((string) ($record['sha256'] ?? ''), $actual)) {
                throw new \RuntimeException('Composer command changed a guarded manifest or lock file.');
            }
        }
    }

    private function restoreGuardedFiles(DoctorContext $context, array $records): void
    {
        foreach ($records as $record) {
            $contents = base64_decode((string) ($record['contents'] ?? ''), true);
            if (!is_string($contents) || !hash_equals((string) ($record['sha256'] ?? ''), hash('sha256', $contents))) {
                throw new \RuntimeException('Composer guarded-file backup is invalid.');
            }
            $path = (string) ($record['path'] ?? '');
            $context->filesystem->atomicWrite($path, $contents, (int) ($record['mode'] ?? 0640));
            @touch($path, (int) ($record['mtime'] ?? time()));
        }
        $this->assertGuardedFiles($records);
    }

    private function restoreVendor(DoctorContext $context, string $vendor, ?array $snapshot, array $current): void
    {
        if ($snapshot !== null) {
            $this->trees->restore($context, $snapshot, $current);
        } elseif (!empty($current['exists'])) {
            $this->trees->removeCreated($context, $vendor, $current);
        }
    }

    private function failureDetail(array $result): string
    {
        $detail = trim((string) ($result['stderr'] ?? '')) ?: trim((string) ($result['stdout'] ?? ''));
        return 'Composer primitive failed.' . ($detail !== '' ? ' ' . $detail : '');
    }
}
