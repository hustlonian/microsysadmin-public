<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Filesystem;

/**
 * Exact local allowlist for release-artifact integrity repairs.
 *
 * The generated definitions are intentionally not enough to authorize a write:
 * every probe and action must also match the independently loaded release
 * manifest and its content-addressed baseline blob.
 */
final class ManagedArtifactCatalog
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $entryCache = [];

    public function __construct(private readonly ?string $applicationRoot = null)
    {
    }

    /**
     * @return array{repair_id:string,path:string,baseline:string,sha256:string,mode:int,size:int,target_path:string,baseline_path:string}
     */
    public function authorize(
        string $repairId,
        string $target,
        string $baseline,
        string $sha256,
        int $mode,
    ): array {
        if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId)
            || !preg_match('/^[a-f0-9]{64}$/', $sha256)
            || $mode < 0600
            || $mode > 0775
            || ($mode & 0002) !== 0) {
            throw new \RuntimeException('Managed-artifact request has invalid identity, hash, or permissions.');
        }

        $root = realpath($this->applicationRoot ?? APP_ROOT);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('Managed-artifact application root is unavailable.');
        }
        $entry = $this->entries($root)[$repairId] ?? null;
        if (!is_array($entry)) {
            throw new \RuntimeException('Managed-artifact repair is absent from the release manifest.');
        }

        $relative = $this->relativePath((string) ($entry['path'] ?? ''));
        $baselineRelative = $this->relativePath((string) ($entry['baseline'] ?? ''));
        $expectedTarget = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $expectedBaseline = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $baselineRelative);
        $baselineReal = realpath($expectedBaseline);
        $blobRoot = realpath($root . '/doctor/baselines/release-artifacts/blobs');

        if ($baselineReal === false || $blobRoot === false
            || ($baselineReal !== $blobRoot && !str_starts_with($baselineReal, $blobRoot . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Managed-artifact target or baseline path is unavailable or unsafe.');
        }
        if (!hash_equals($expectedTarget, $target)
            || !hash_equals($baselineReal, $baseline)
            || !hash_equals((string) ($entry['sha256'] ?? ''), $sha256)
            || (int) ($entry['mode'] ?? 0) !== $mode) {
            throw new \RuntimeException('Managed-artifact request differs from the exact release allowlist.');
        }
        if ($relative === 'config/config.php'
            || str_starts_with($relative, 'storage/')
            || str_starts_with($relative, 'node_modules/')
            || str_starts_with($relative, 'doctor/baselines/')
            || str_starts_with($relative, 'doctor/modules/artifact-integrity/')) {
            throw new \RuntimeException('Managed-artifact request targets an excluded mutable or generated path.');
        }
        if (!is_file($baselineReal) || is_link($baselineReal)) {
            throw new \RuntimeException('Managed-artifact baseline is not a regular local file.');
        }
        $baselineHash = hash_file('sha256', $baselineReal);
        if (!is_string($baselineHash) || !hash_equals($sha256, $baselineHash)) {
            throw new \RuntimeException('Managed-artifact baseline failed SHA-256 validation.');
        }

        return [
            'repair_id' => $repairId,
            'path' => $relative,
            'baseline' => $baselineRelative,
            'sha256' => $sha256,
            'mode' => $mode,
            'size' => (int) ($entry['size'] ?? filesize($baselineReal)),
            'target_path' => $expectedTarget,
            'baseline_path' => $baselineReal,
        ];
    }

    /** @return array{repair_id:string,path:string,mode:int,target_path:string,root_path:string} */
    public function authorizeDirectory(string $repairId, string $target, int $mode, string $condition): array
    {
        if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId)
            || $mode < 0700 || $mode > 0775 || ($mode & 0002) !== 0
            || !in_array($condition, ['missing', 'mode'], true)) {
            throw new \RuntimeException('Managed-directory request has an invalid identity, mode, or condition.');
        }
        $root = realpath($this->applicationRoot ?? APP_ROOT);
        if ($root === false || !is_dir($root) || is_link($root)) {
            throw new \RuntimeException('Managed-directory application root is unavailable or symbolic.');
        }
        $section = $condition === 'missing' ? 'directories' : 'directory_mode_repairs';
        $entry = $this->entries($root, $section)[$repairId] ?? null;
        if (!is_array($entry)) {
            throw new \RuntimeException('Managed-directory repair is absent from the release manifest.');
        }
        $relative = $this->relativePath((string) ($entry['path'] ?? ''));
        $expectedTarget = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!hash_equals($expectedTarget, $target)
            || (int) ($entry['mode'] ?? 0) !== $mode
            || (string) ($entry['condition'] ?? '') !== $condition) {
            throw new \RuntimeException('Managed-directory request differs from the exact release topology allowlist.');
        }
        return ['repair_id' => $repairId, 'path' => $relative, 'mode' => $mode, 'condition' => $condition, 'target_path' => $expectedTarget, 'root_path' => $root];
    }

    /** @return list<array{repair_id:string,path:string,mode:int,target_path:string}> */
    public function parentDirectoriesForArtifact(string $repairId): array
    {
        if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId)) {
            throw new \RuntimeException('Managed-artifact parent request has an invalid repair identity.');
        }
        $root = realpath($this->applicationRoot ?? APP_ROOT);
        if ($root === false || !is_dir($root) || is_link($root)) {
            throw new \RuntimeException('Managed-artifact application root is unavailable or symbolic.');
        }
        $artifact = $this->entries($root)[$repairId] ?? null;
        if (!is_array($artifact)) {
            throw new \RuntimeException('Managed-artifact parent request is absent from the release manifest.');
        }
        $directoriesByPath = [];
        foreach ($this->entries($root, 'directories') as $entry) {
            $directoriesByPath[(string) ($entry['path'] ?? '')] = $entry;
        }
        $parents = [];
        $relative = dirname($this->relativePath((string) ($artifact['path'] ?? '')));
        while ($relative !== '.') {
            $entry = $directoriesByPath[$relative] ?? null;
            if (!is_array($entry)) {
                if ($relative === 'doctor') {
                    break;
                }
                throw new \RuntimeException("Managed-artifact parent is absent from release topology: {$relative}");
            }
            $parents[] = [
                'repair_id' => (string) ($entry['repair_id'] ?? ''),
                'path' => $relative,
                'mode' => (int) ($entry['mode'] ?? 0),
                'target_path' => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative),
            ];
            $relative = dirname($relative);
        }
        return array_reverse($parents);
    }

    /** @return list<array{repair_id:string,path:string,mode:int,target_path:string}> */
    public function directoryChainForRepair(string $repairId): array
    {
        if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId)) {
            throw new \RuntimeException('Managed-directory chain request has an invalid repair identity.');
        }
        $root = realpath($this->applicationRoot ?? APP_ROOT);
        if ($root === false || !is_dir($root) || is_link($root)) {
            throw new \RuntimeException('Managed-directory application root is unavailable or symbolic.');
        }
        $entries = $this->entries($root, 'directories');
        $byPath = [];
        $target = null;
        foreach ($entries as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $byPath[$path] = $entry;
            if (($entry['repair_id'] ?? '') === $repairId) {
                $target = $entry;
            }
        }
        if (!is_array($target)) {
            throw new \RuntimeException('Managed-directory chain repair is absent from the release manifest.');
        }
        $chain = [];
        $relative = (string) $target['path'];
        while ($relative !== '.') {
            $entry = $byPath[$relative] ?? null;
            if (!is_array($entry)) {
                if ($relative === 'doctor') {
                    break;
                }
                throw new \RuntimeException("Managed-directory parent is absent from release topology: {$relative}");
            }
            $chain[] = [
                'repair_id' => (string) ($entry['repair_id'] ?? ''),
                'path' => $relative,
                'mode' => (int) ($entry['mode'] ?? 0),
                'target_path' => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative),
            ];
            $relative = dirname($relative);
        }
        return array_reverse($chain);
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(string $root, string $section = 'artifacts'): array
    {
        $manifest = $root . '/doctor/baselines/release-artifacts/manifest.json';
        $signature = is_file($manifest)
            ? (string) filemtime($manifest) . ':' . (string) filesize($manifest)
            : 'missing';
        if (!in_array($section, ['artifacts', 'directories', 'directory_mode_repairs'], true)) {
            throw new \RuntimeException('Managed release manifest section is unsupported.');
        }
        $cacheKey = $manifest . ':' . $signature . ':' . $section;
        if (isset(self::$entryCache[$cacheKey])) {
            return self::$entryCache[$cacheKey];
        }
        if (!is_file($manifest) || is_link($manifest) || !is_readable($manifest)) {
            throw new \RuntimeException('Managed-artifact release manifest is unavailable.');
        }
        $decoded = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || (int) ($decoded['schema_version'] ?? 0) !== 3
            || (int) ($decoded['artifact_count'] ?? 0) !== count((array) ($decoded['artifacts'] ?? []))
            || (int) ($decoded['directory_count'] ?? 0) !== count((array) ($decoded['directories'] ?? []))
            || (int) ($decoded['directory_mode_repair_count'] ?? 0) !== count((array) ($decoded['directory_mode_repairs'] ?? []))) {
            throw new \RuntimeException('Managed-artifact release manifest is invalid.');
        }
        $entries = [];
        foreach ((array) $decoded[$section] as $entry) {
            $id = (string) ($entry['repair_id'] ?? '');
            if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $id) || isset($entries[$id])) {
                throw new \RuntimeException('Managed-artifact release manifest contains an invalid or duplicate ID.');
            }
            $entries[$id] = (array) $entry;
        }
        self::$entryCache[$cacheKey] = $entries;
        return $entries;
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains("/{$path}/", '/../') || str_contains($path, "\0")) {
            throw new \RuntimeException('Managed-artifact manifest contains an unsafe relative path.');
        }
        return $path;
    }
}
