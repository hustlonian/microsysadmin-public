<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SysAdmin\Doctor\engine\Context\DoctorContext;

/**
 * Exact, bounded snapshots for SDK primitives whose effect is a directory tree.
 *
 * The manager never follows links, rejects special files and escaping links,
 * caps both file count and bytes, and will remove/replace a live tree only when
 * its complete post-repair manifest still matches the recorded state.
 */
final readonly class TreeSnapshotManager
{
    public function capture(
        DoctorContext $context,
        string $root,
        string $label,
        int $maxFiles = 50000,
        int $maxBytes = 536870912,
    ): array {
        $root = $context->filesystem->normalize($root, true, true);
        $manifest = $this->inspect($context, $root, $maxFiles, $maxBytes);
        if (empty($manifest['exists'])) {
            throw new \RuntimeException('Tree snapshot source does not exist.');
        }

        $runtime = (string) $context->option('doctor_runtime_root', '');
        if ($runtime === '' || !is_dir($runtime) || is_link($runtime)) {
            throw new \RuntimeException('Tree snapshot runtime root is unavailable or symbolic.');
        }
        $runtime = $context->filesystem->normalize($runtime, true, true);
        $base = $runtime . '/sdk-tree-snapshots';
        $this->ensureDirectory($context, $base);
        $execution = $base . '/' . preg_replace('/[^A-Za-z0-9._-]/', '-', $context->executionId);
        $this->ensureDirectory($context, $execution);
        $safeLabel = preg_replace('/[^A-Za-z0-9._-]/', '-', $label) ?: 'tree';
        $snapshot = $execution . '/' . $safeLabel . '-' . bin2hex(random_bytes(8));
        $context->filesystem->createDirectory($snapshot, 0750);
        $tree = $snapshot . '/tree';
        $context->filesystem->createDirectory($tree, 0750);

        foreach ((array) ($manifest['entries'] ?? []) as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            $source = $root . '/' . $relative;
            $destination = $tree . '/' . $relative;
            if (($entry['type'] ?? '') === 'directory') {
                $context->filesystem->createDirectory($destination, 0750);
            } elseif (($entry['type'] ?? '') === 'file') {
                $context->filesystem->atomicWrite($destination, $context->filesystem->read($source), 0640);
                if (!hash_equals((string) $entry['sha256'], (string) hash_file('sha256', $destination))) {
                    throw new \RuntimeException('Tree snapshot file validation failed.');
                }
            }
        }
        $manifestPath = $snapshot . '/manifest.json';
        $context->filesystem->atomicWrite(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            0640,
        );

        return [
            'snapshot_root' => $snapshot,
            'tree_root' => $tree,
            'manifest_path' => $manifestPath,
            'manifest' => $manifest,
            'manifest_sha256' => $this->fingerprint($manifest),
        ];
    }

    public function inspect(
        DoctorContext $context,
        string $root,
        int $maxFiles = 50000,
        int $maxBytes = 536870912,
    ): array {
        if (!file_exists($root) && !is_link($root)) {
            $normalized = $context->filesystem->normalize($root, false, true);
            return ['exists' => false, 'root' => $normalized, 'entries' => [], 'files' => 0, 'bytes' => 0];
        }
        $root = $context->filesystem->normalize($root, true, true);
        if (!is_dir($root) || is_link($root)) {
            throw new \RuntimeException('Tree snapshot target must be a non-symbolic directory.');
        }
        $rootStat = lstat($root);
        if ($rootStat === false || (($rootStat['mode'] & 0002) !== 0)) {
            throw new \RuntimeException('Tree snapshot root metadata is unavailable or unsafe.');
        }

        $entries = [];
        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($relative === '' || str_contains($relative, "\0")) {
                throw new \RuntimeException('Tree snapshot encountered an invalid relative path.');
            }
            $stat = lstat($path);
            if ($stat === false || (($stat['mode'] & 0002) !== 0)) {
                throw new \RuntimeException("Tree snapshot encountered unsafe metadata at {$relative}.");
            }
            if (is_link($path)) {
                $target = readlink($path);
                if (!is_string($target) || !PathGuard::relativeLinkStaysWithin($relative, $target)) {
                    throw new \RuntimeException("Tree snapshot refuses an absolute or escaping link at {$relative}.");
                }
                $entries[] = ['path' => $relative, 'type' => 'symlink', 'target' => $target];
                continue;
            }
            if (is_dir($path)) {
                $entries[] = [
                    'path' => $relative,
                    'type' => 'directory',
                    'mode' => $stat['mode'] & 0777,
                    'uid' => $stat['uid'],
                    'gid' => $stat['gid'],
                    'mtime' => $stat['mtime'],
                ];
                continue;
            }
            if (!is_file($path)) {
                throw new \RuntimeException("Tree snapshot refuses a special filesystem object at {$relative}.");
            }
            $size = filesize($path);
            if (!is_int($size) || $size < 0 || $size > 67108864) {
                throw new \RuntimeException("Tree snapshot file exceeds the per-file safety limit at {$relative}.");
            }
            $files++;
            $bytes += $size;
            if ($files > $maxFiles || $bytes > $maxBytes) {
                throw new \RuntimeException('Tree snapshot exceeded its bounded file-count or byte limit.');
            }
            $sha256 = hash_file('sha256', $path);
            if (!is_string($sha256)) {
                throw new \RuntimeException("Tree snapshot could not hash {$relative}.");
            }
            $entries[] = [
                'path' => $relative,
                'type' => 'file',
                'size' => $size,
                'sha256' => $sha256,
                'mode' => $stat['mode'] & 0777,
                'uid' => $stat['uid'],
                'gid' => $stat['gid'],
                'mtime' => $stat['mtime'],
            ];
        }
        usort($entries, static fn (array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));

        return [
            'exists' => true,
            'root' => $root,
            'root_mode' => $rootStat['mode'] & 0777,
            'root_uid' => $rootStat['uid'],
            'root_gid' => $rootStat['gid'],
            'root_mtime' => $rootStat['mtime'],
            'entries' => $entries,
            'files' => $files,
            'bytes' => $bytes,
        ];
    }

    public function restore(DoctorContext $context, array $snapshot, array $expectedCurrent): void
    {
        $manifest = (array) ($snapshot['manifest'] ?? []);
        $root = (string) ($manifest['root'] ?? '');
        $current = $this->inspect($context, $root, max(1, (int) ($expectedCurrent['files'] ?? 50000)), max(1, (int) ($expectedCurrent['bytes'] ?? 536870912)));
        $this->assertManifest($expectedCurrent, $current, 'Tree changed after repair; rollback refused.');
        if (!empty($current['exists'])) {
            $this->removeExact($context, $root, $current);
        }

        $tree = (string) ($snapshot['tree_root'] ?? '');
        $tree = $context->filesystem->normalize($tree, true, true);
        $context->filesystem->createDirectory($root, 0750);
        $directories = [];
        foreach ((array) ($manifest['entries'] ?? []) as $entry) {
            $path = $root . '/' . (string) $entry['path'];
            if (($entry['type'] ?? '') === 'directory') {
                $context->filesystem->createDirectory($path, 0750);
                $directories[] = $entry;
            } elseif (($entry['type'] ?? '') === 'file') {
                $source = $tree . '/' . (string) $entry['path'];
                $context->filesystem->atomicWrite($path, $context->filesystem->read($source), (int) $entry['mode']);
                @touch($path, (int) $entry['mtime']);
            } elseif (($entry['type'] ?? '') === 'symlink') {
                $parent = $context->filesystem->normalize(dirname($path), true, true);
                $destination = $parent . '/' . basename($path);
                if (!symlink((string) $entry['target'], $destination)) {
                    throw new \RuntimeException('Unable to restore a bounded tree symlink.');
                }
            }
        }
        foreach (array_reverse($directories) as $entry) {
            $path = $root . '/' . (string) $entry['path'];
            chmod($path, (int) $entry['mode']);
            @touch($path, (int) $entry['mtime']);
        }
        chmod($root, (int) $manifest['root_mode']);
        @touch($root, (int) $manifest['root_mtime']);
        $restored = $this->inspect($context, $root, max(1, (int) ($manifest['files'] ?? 50000)), max(1, (int) ($manifest['bytes'] ?? 536870912)));
        $this->assertManifest($manifest, $restored, 'Tree snapshot restoration failed independent validation.');
    }

    public function removeCreated(DoctorContext $context, string $root, array $expectedCurrent): void
    {
        $current = $this->inspect($context, $root, max(1, (int) ($expectedCurrent['files'] ?? 50000)), max(1, (int) ($expectedCurrent['bytes'] ?? 536870912)));
        $this->assertManifest($expectedCurrent, $current, 'Created tree changed after repair; rollback refused.');
        $this->removeExact($context, $root, $current);
        if (file_exists($root) || is_link($root)) {
            throw new \RuntimeException('Created tree rollback did not remove the exact target.');
        }
    }

    public function fingerprint(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assertManifest(array $expected, array $actual, string $message): void
    {
        if (!hash_equals($this->fingerprint($expected), $this->fingerprint($actual))) {
            throw new \RuntimeException($message);
        }
    }

    private function removeExact(DoctorContext $context, string $root, array $manifest): void
    {
        $root = $context->filesystem->normalize($root, true, true);
        $entries = (array) ($manifest['entries'] ?? []);
        usort($entries, static function (array $left, array $right): int {
            $depth = substr_count((string) $right['path'], '/') <=> substr_count((string) $left['path'], '/');
            return $depth !== 0 ? $depth : strcmp((string) $right['path'], (string) $left['path']);
        });
        foreach ($entries as $entry) {
            $path = $root . '/' . (string) $entry['path'];
            if (($entry['type'] ?? '') === 'directory') {
                if (!rmdir($path)) {
                    throw new \RuntimeException('Unable to remove an exact tree-snapshot directory.');
                }
            } elseif (!unlink($path)) {
                throw new \RuntimeException('Unable to remove an exact tree-snapshot entry.');
            }
        }
        if (!rmdir($root)) {
            throw new \RuntimeException('Unable to remove the exact tree-snapshot root.');
        }
    }

    private function ensureDirectory(DoctorContext $context, string $path): void
    {
        if (!is_dir($path)) {
            $context->filesystem->createDirectory($path, 0750);
        } elseif (is_link($path)) {
            throw new \RuntimeException('Tree snapshot directory cannot be symbolic.');
        }
    }

}
