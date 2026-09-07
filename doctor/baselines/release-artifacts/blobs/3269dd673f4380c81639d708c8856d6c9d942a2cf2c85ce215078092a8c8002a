<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Generation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Validates the clean, production-only Composer install captured from the
 * commercial release staging workflow. Runtime repairs consume only immutable
 * local blobs attested by this manifest; the mutable source vendor tree is
 * never trusted as repair provenance.
 */
final readonly class LockedComposerVendorCatalog
{
    public const MANIFEST = 'doctor/baselines/locked-composer-vendor.json';

    public function __construct(private string $applicationRoot)
    {
    }

    /**
     * @return array{
     *   candidates:array<string,array{path:string,size:int,mode:int,sha256:string}>,
     *   directories:array<string,array{path:string,mode:int}>,
     *   provenance:array<string,mixed>
     * }
     */
    public function build(): array
    {
        $root = $this->root($this->applicationRoot);
        $manifestPath = $root . '/' . self::MANIFEST;
        $manifest = $this->decode($manifestPath);
        $lockPath = $root . '/composer.lock';
        $lockHash = is_file($lockPath) ? hash_file('sha256', $lockPath) : false;
        if (!is_string($lockHash)
            || !hash_equals((string) ($manifest['composer_lock_sha256'] ?? ''), $lockHash)
            || (int) ($manifest['schema_version'] ?? 0) !== 2
            || ($manifest['source'] ?? '') !== 'clean_commercial_release_composer_install') {
            throw new RuntimeException('Locked Composer vendor provenance does not match composer.lock.');
        }

        $candidates = [];
        $ids = [];
        foreach ((array) ($manifest['artifacts'] ?? []) as $entry) {
            $relative = $this->relativeVendorPath((string) ($entry['path'] ?? ''));
            $sha256 = (string) ($entry['sha256'] ?? '');
            $mode = (int) ($entry['mode'] ?? 0);
            $size = (int) ($entry['size'] ?? -1);
            $baseline = $this->relativePath((string) ($entry['baseline'] ?? ''));
            if (isset($ids[$relative])
                || !preg_match('/^[a-f0-9]{64}$/', $sha256)
                || $baseline !== 'doctor/baselines/release-artifacts/blobs/' . $sha256
                || $mode < 0600 || $mode > 0775 || ($mode & 0002) !== 0 || $size < 0) {
                throw new RuntimeException("Locked Composer artifact metadata is invalid: {$relative}");
            }
            $blob = $root . '/' . $baseline;
            $actualHash = is_file($blob) && !is_link($blob) ? hash_file('sha256', $blob) : false;
            if (!is_string($actualHash) || !hash_equals($sha256, $actualHash) || filesize($blob) !== $size) {
                throw new RuntimeException("Locked Composer baseline failed validation: {$relative}");
            }
            $ids[$relative] = true;
            $candidates[$relative] = ['path' => $blob, 'size' => $size, 'mode' => $mode, 'sha256' => $sha256];
        }
        ksort($candidates, SORT_STRING);
        if ($candidates === [] || count($candidates) !== (int) ($manifest['artifact_count'] ?? -1)) {
            throw new RuntimeException('Locked Composer vendor catalog is empty or incomplete.');
        }

        $directories = [];
        foreach ((array) ($manifest['directories'] ?? []) as $entry) {
            $relative = $this->relativeVendorDirectoryPath((string) ($entry['path'] ?? ''));
            $mode = (int) ($entry['mode'] ?? 0);
            if (isset($directories[$relative]) || $mode < 0700 || $mode > 0775 || ($mode & 0002) !== 0) {
                throw new RuntimeException("Locked Composer directory metadata is invalid: {$relative}");
            }
            $directories[$relative] = ['path' => $relative, 'mode' => $mode];
        }
        ksort($directories, SORT_STRING);
        if ($directories === [] || count($directories) !== (int) ($manifest['directory_count'] ?? -1)) {
            throw new RuntimeException('Locked Composer vendor directory catalog is empty or incomplete.');
        }
        foreach (array_keys($candidates) as $relative) {
            $parent = dirname($relative);
            while ($parent !== '.') {
                if (!isset($directories[$parent])) {
                    throw new RuntimeException("Locked Composer artifact parent is absent from directory provenance: {$parent}");
                }
                $parent = dirname($parent);
            }
        }
        $revision = hash('sha256', $this->json([
            'artifacts' => array_values((array) ($manifest['artifacts'] ?? [])),
            'directories' => array_values((array) ($manifest['directories'] ?? [])),
        ]));
        if (!hash_equals((string) ($manifest['catalog_revision'] ?? ''), $revision)) {
            throw new RuntimeException('Locked Composer vendor catalog revision is invalid.');
        }

        return [
            'candidates' => $candidates,
            'directories' => $directories,
            'provenance' => [
                'source' => 'clean_commercial_release_composer_install',
                'manifest_path' => self::MANIFEST,
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'composer_lock_sha256' => $lockHash,
                'catalog_revision' => $revision,
                'artifact_count' => count($candidates),
                'directory_count' => count($directories),
                'packages' => array_values((array) ($manifest['packages'] ?? [])),
                'composer_install_flags' => array_values((array) ($manifest['composer_install_flags'] ?? [])),
            ],
        ];
    }

    /** @return array{verified:int,verified_directories:int,unexpected:list<string>,missing:list<string>,drifted:list<string>,unexpected_directories:list<string>,missing_directories:list<string>,drifted_directories:list<string>} */
    public function verifyReleaseRoot(string $releaseRoot): array
    {
        $catalog = $this->build();
        $release = $this->root($releaseRoot);
        $expected = (array) $catalog['candidates'];
        $expectedDirectories = (array) $catalog['directories'];
        $actual = [];
        $actualDirectories = ['vendor' => $vendor = $release . '/vendor'];
        if (!is_dir($vendor) || is_link($vendor)) {
            throw new RuntimeException('Release Composer vendor root is unavailable or symbolic.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new RuntimeException('Release Composer vendor contains a non-regular artifact.');
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($release) + 1));
            if ($entry->isDir()) {
                $actualDirectories[$relative] = $entry->getPathname();
            } else {
                $actual[$relative] = $entry->getPathname();
            }
        }
        ksort($actual, SORT_STRING);
        ksort($actualDirectories, SORT_STRING);
        $missing = array_values(array_diff(array_keys($expected), array_keys($actual)));
        $unexpected = array_values(array_diff(array_keys($actual), array_keys($expected)));
        $drifted = [];
        foreach (array_intersect_key($actual, $expected) as $relative => $path) {
            $record = (array) $expected[$relative];
            $hash = hash_file('sha256', $path);
            $mode = fileperms($path) & 0777;
            if (!is_string($hash)
                || !hash_equals((string) $record['sha256'], $hash)
                || (int) $record['size'] !== filesize($path)
                || (int) $record['mode'] !== $mode) {
                $drifted[] = $relative;
            }
        }
        $missingDirectories = array_values(array_diff(array_keys($expectedDirectories), array_keys($actualDirectories)));
        $unexpectedDirectories = array_values(array_diff(array_keys($actualDirectories), array_keys($expectedDirectories)));
        $driftedDirectories = [];
        foreach (array_intersect_key($actualDirectories, $expectedDirectories) as $relative => $path) {
            if ((fileperms($path) & 0777) !== (int) $expectedDirectories[$relative]['mode']) {
                $driftedDirectories[] = $relative;
            }
        }
        return [
            'verified' => count($expected) - count($missing) - count($drifted),
            'verified_directories' => count($expectedDirectories) - count($missingDirectories) - count($driftedDirectories),
            'unexpected' => $unexpected,
            'missing' => $missing,
            'drifted' => $drifted,
            'unexpected_directories' => $unexpectedDirectories,
            'missing_directories' => $missingDirectories,
            'drifted_directories' => $driftedDirectories,
        ];
    }

    /** @return array{artifacts:int,directories:int} */
    public function materializeReleaseRoot(string $releaseRoot): array
    {
        $catalog = $this->build();
        $release = $this->root($releaseRoot);
        $directories = array_values((array) $catalog['directories']);
        usort($directories, static fn (array $left, array $right): int =>
            substr_count((string) $left['path'], '/') <=> substr_count((string) $right['path'], '/'));
        foreach ($directories as $record) {
            $relative = $this->relativeVendorDirectoryPath((string) ($record['path'] ?? ''));
            $target = $release . '/' . $relative;
            if (is_link($target) || (file_exists($target) && !is_dir($target))) {
                throw new RuntimeException("Locked Composer directory is obstructed: {$relative}");
            }
            if (!is_dir($target) && !mkdir($target, (int) $record['mode'])) {
                throw new RuntimeException("Unable to create locked Composer directory: {$relative}");
            }
            if (!chmod($target, (int) $record['mode'])) {
                throw new RuntimeException("Unable to apply locked Composer directory mode: {$relative}");
            }
            clearstatcache(true, $target);
            if ((fileperms($target) & 0777) !== (int) $record['mode']) {
                throw new RuntimeException("Unable to verify locked Composer directory mode: {$relative}");
            }
        }

        foreach ((array) $catalog['candidates'] as $relative => $record) {
            $relative = $this->relativeVendorPath((string) $relative);
            $target = $release . '/' . $relative;
            if (is_link($target) || (file_exists($target) && !is_file($target))) {
                throw new RuntimeException("Locked Composer artifact is obstructed: {$relative}");
            }
            $parent = dirname($target);
            if (!is_dir($parent) || is_link($parent)) {
                throw new RuntimeException("Locked Composer artifact parent is unavailable: {$relative}");
            }
            $temporary = $parent . '/.' . basename($target) . '.locked-' . bin2hex(random_bytes(5));
            try {
                if (!copy((string) $record['path'], $temporary)
                    || !chmod($temporary, (int) $record['mode'])
                    || !is_file($temporary)
                    || is_link($temporary)
                    || filesize($temporary) !== (int) $record['size']) {
                    throw new RuntimeException("Unable to stage locked Composer artifact: {$relative}");
                }
                $hash = hash_file('sha256', $temporary);
                if (!is_string($hash) || !hash_equals((string) $record['sha256'], $hash)) {
                    throw new RuntimeException("Staged locked Composer artifact failed validation: {$relative}");
                }
                if (!rename($temporary, $target)) {
                    throw new RuntimeException("Unable to install locked Composer artifact: {$relative}");
                }
            } finally {
                if (is_file($temporary) || is_link($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        return ['artifacts' => count((array) $catalog['candidates']), 'directories' => count($directories)];
    }

    /** @return array<string,mixed> */
    private function decode(string $path): array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException("Locked Composer provenance is unavailable: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Locked Composer provenance must be a JSON object.');
        }
        return $decoded;
    }

    private function root(string $path): string
    {
        $root = realpath($path);
        if ($root === false || !is_dir($root) || is_link($path)) {
            throw new RuntimeException('Locked Composer application root is unavailable or symbolic.');
        }
        return rtrim($root, '/');
    }

    private function relativeVendorPath(string $path): string
    {
        $path = $this->relativePath($path);
        if (!str_starts_with($path, 'vendor/') || $path === 'vendor/') {
            throw new RuntimeException('Locked Composer artifact escaped the vendor boundary.');
        }
        return $path;
    }

    private function relativeVendorDirectoryPath(string $path): string
    {
        $path = $this->relativePath($path);
        if ($path !== 'vendor' && !str_starts_with($path, 'vendor/')) {
            throw new RuntimeException('Locked Composer directory escaped the vendor boundary.');
        }
        return $path;
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#', $path)) {
            throw new RuntimeException('Locked Composer provenance contains an unsafe relative path.');
        }
        return $path;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
