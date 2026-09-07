<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Generation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Enumerates immutable, application-owned files from the commercial release
 * packaging policy. This is the single provenance boundary used by generated
 * artifact repairs; developer files and customer state never enter it.
 */
final readonly class ReleaseArtifactCatalog
{
    public function __construct(
        private string $applicationRoot,
        private string $allowlist = 'packaging/release-allowlist.txt',
        private string $excludes = 'packaging/release-excludes.txt',
    ) {
    }

    /**
     * @param list<string> $additionalExcludedPrefixes
     * @param list<string> $additionalExcludedExact
     * @return array{
     *   candidates: array<string, array{path:string,size:int,mode:int,sha256:string}>,
     *   excluded: list<array{path:string,reason:string}>,
     *   provenance: array<string,mixed>,
     *   diagnostics: array{discovered_file_entries:int,excluded_entries:int}
     * }
     */
    public function build(
        array $additionalExcludedPrefixes = [],
        array $additionalExcludedExact = [],
        int $maximumFileBytes = 8_388_608,
    ): array {
        $root = realpath($this->applicationRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Release artifact application root is unavailable.');
        }
        if ($maximumFileBytes < 1) {
            throw new RuntimeException('Release artifact maximum file size must be positive.');
        }

        $allowlistPath = $this->policyPath($root, $this->allowlist);
        $excludesPath = $this->policyPath($root, $this->excludes);
        $allowlistedRoots = $this->policyEntries($allowlistPath);
        $releaseExcludes = $this->policyEntries($excludesPath);
        $additionalExcludedPrefixes = $this->normaliseEntries($additionalExcludedPrefixes, true);
        $additionalExcludedExact = $this->normaliseEntries($additionalExcludedExact, false);

        $discovered = [];
        foreach ($allowlistedRoots as $relativeRoot) {
            $absoluteRoot = $root . '/' . $relativeRoot;
            if (!file_exists($absoluteRoot) && !is_link($absoluteRoot)) {
                throw new RuntimeException("Allow-listed release path is missing: {$relativeRoot}");
            }
            if (is_link($absoluteRoot) || is_file($absoluteRoot)) {
                $discovered[$relativeRoot] = new SplFileInfo($absoluteRoot);
                continue;
            }
            if (!is_dir($absoluteRoot)) {
                $discovered[$relativeRoot] = new SplFileInfo($absoluteRoot);
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($iterator as $entry) {
                $relative = $this->relativePath($root, $entry->getPathname());
                $discovered[$relative] = $entry;
            }
        }
        ksort($discovered, SORT_STRING);

        $candidates = [];
        $excluded = [];
        foreach ($discovered as $relative => $entry) {
            $reason = $this->exclusionReason(
                $relative,
                $entry,
                $releaseExcludes,
                $additionalExcludedPrefixes,
                $additionalExcludedExact,
                $maximumFileBytes,
            );
            if ($reason !== null) {
                $excluded[] = ['path' => $relative, 'reason' => $reason];
                continue;
            }
            $absolute = $entry->getPathname();
            $sha256 = hash_file('sha256', $absolute);
            if (!is_string($sha256)) {
                throw new RuntimeException("Unable to hash release artifact {$relative}.");
            }
            $mode = fileperms($absolute) & 0777;
            if ($mode < 0600 || $mode > 0775 || ($mode & 0002) !== 0) {
                throw new RuntimeException("Release artifact {$relative} has an unsafe mode.");
            }
            $candidates[$relative] = [
                'path' => $absolute,
                'size' => (int) $entry->getSize(),
                'mode' => $mode,
                'sha256' => $sha256,
            ];
        }

        return [
            'candidates' => $candidates,
            'excluded' => $excluded,
            'provenance' => [
                'source' => 'commercial_release_packaging_policy',
                'allowlist_path' => $this->allowlist,
                'allowlist_sha256' => hash_file('sha256', $allowlistPath),
                'exclude_path' => $this->excludes,
                'exclude_sha256' => hash_file('sha256', $excludesPath),
                'allowlisted_roots' => $allowlistedRoots,
                'release_excludes' => $releaseExcludes,
                'additional_excluded_prefixes' => $additionalExcludedPrefixes,
                'additional_excluded_exact_paths' => $additionalExcludedExact,
                'maximum_file_bytes' => $maximumFileBytes,
                'eligible_artifacts' => count($candidates),
            ],
            'diagnostics' => [
                'discovered_file_entries' => count($discovered),
                'excluded_entries' => count($excluded),
            ],
        ];
    }

    private function policyPath(string $root, string $relative): string
    {
        $this->assertRelativePath($relative);
        $path = $root . '/' . $relative;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException("Release policy is unavailable or unsafe: {$relative}");
        }
        return $path;
    }

    /** @return list<string> */
    private function policyEntries(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException("Unable to read release policy {$path}.");
        }
        $entries = [];
        foreach ($lines as $line) {
            $entry = trim($line);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }
            $this->assertRelativePath($entry);
            $entries[$entry] = true;
        }
        return array_keys($entries);
    }

    /** @param list<string> $entries @return list<string> */
    private function normaliseEntries(array $entries, bool $prefix): array
    {
        $normalised = [];
        foreach ($entries as $entry) {
            $value = str_replace('\\', '/', trim((string) $entry));
            $value = $prefix ? rtrim($value, '/') . '/' : rtrim($value, '/');
            $this->assertRelativePath(rtrim($value, '/'));
            $normalised[$value] = true;
        }
        return array_keys($normalised);
    }

    private function assertRelativePath(string $path): void
    {
        $normalised = str_replace('\\', '/', $path);
        if ($normalised === ''
            || str_starts_with($normalised, '/')
            || str_contains($normalised, "\0")
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $normalised)
            || preg_match('#(?:^|/)\.(?:/|$)#', $normalised)) {
            throw new RuntimeException("Release policy path is unsafe: {$path}");
        }
    }

    private function relativePath(string $root, string $absolute): string
    {
        $prefix = rtrim($root, '/') . '/';
        if (!str_starts_with($absolute, $prefix)) {
            throw new RuntimeException('Release artifact escaped the application root.');
        }
        return str_replace('\\', '/', substr($absolute, strlen($prefix)));
    }

    /**
     * @param list<string> $releaseExcludes
     * @param list<string> $additionalExcludedPrefixes
     * @param list<string> $additionalExcludedExact
     */
    private function exclusionReason(
        string $relative,
        SplFileInfo $entry,
        array $releaseExcludes,
        array $additionalExcludedPrefixes,
        array $additionalExcludedExact,
        int $maximumFileBytes,
    ): ?string {
        foreach ($releaseExcludes as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, rtrim($excluded, '/') . '/')) {
                return 'release_policy_excluded';
            }
        }
        if (in_array($relative, $additionalExcludedExact, true)) {
            return 'artifact_policy_exact_exclusion';
        }
        foreach ($additionalExcludedPrefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return 'artifact_policy_prefix_exclusion';
            }
        }
        if ($entry->isLink()) {
            return 'symbolic_link_requires_distinct_contract';
        }
        if (!$entry->isFile()) {
            return 'not_a_regular_file';
        }
        if (!$entry->isReadable()) {
            return 'source_not_readable';
        }
        if ($entry->getSize() > $maximumFileBytes) {
            return 'exceeds_bounded_artifact_size';
        }
        if (preg_match('#(?:^|/)(?:\.env(?:\..*)?|[^/]*(?:private|secret)[^/]*\.(?:key|pem))$#i', $relative)) {
            return 'secret_bearing_path';
        }
        return null;
    }
}
