<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Detection;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Filesystem\ManagedArtifactCatalog;
use SysAdmin\Doctor\engine\Support\TemplateResolver;
use SysAdmin\Doctor\sdk\Support\DotenvDocument;

final readonly class ProbeExecutor
{
    public function __construct(
        private TemplateResolver $resolver = new TemplateResolver(),
        private ManagedArtifactCatalog $artifacts = new ManagedArtifactCatalog(),
    )
    {
    }

    public function run(array $probe, DoctorContext $context): ProbeResult
    {
        $type = (string) ($probe['type'] ?? 'unknown');
        $resolved = (array) $this->resolver->value($probe, $context);
        $started = microtime(true);
        try {
            $result = match ($type) {
                'file_exists' => $this->pathProbe($resolved, 'file'),
                'directory_exists' => $this->pathProbe($resolved, 'directory'),
                'path_exists' => $this->pathProbe($resolved, 'any'),
                'path_absent' => $this->pathAbsentProbe($resolved),
                'path_readable' => $this->accessProbe($resolved, 'readable'),
                'path_writable' => $this->accessProbe($resolved, 'writable'),
                'executable_exists' => $this->executableProbe($resolved, $context),
                'any_executable_exists' => $this->anyExecutableProbe($resolved, $context),
                'symlink_target' => $this->symlinkProbe($resolved),
                'symlink_wrong_target' => $this->wrongSymlinkProbe($resolved),
                'file_contains' => $this->fileContainsProbe($resolved, $context),
                'file_from_template_state' => $this->fileFromTemplateStateProbe($resolved),
                'env_value' => $this->environmentValueProbe($resolved),
                'json_valid' => $this->jsonProbe($resolved, $context),
                'php_extension' => $this->phpExtensionProbe($resolved),
                'php_version' => $this->phpVersionProbe($resolved, $context),
                'disk_free_percent' => $this->diskProbe($resolved),
                'filesystem_mount_writable' => $this->filesystemMountProbe($resolved, $context),
                'time_sync_enabled' => $this->timeSyncProbe($resolved, $context),
                'production_debug_disabled' => $this->productionDebugProbe($resolved, $context),
                'stale_git_index_lock' => $this->staleGitIndexLockProbe($resolved, $context),
                'glob_exists' => $this->globProbe($resolved),
                'managed_artifact_integrity' => $this->managedArtifactIntegrityProbe($resolved, $context),
                'managed_directory_missing', 'managed_directory_mode' => $this->managedDirectoryIntegrityProbe($resolved, $context),
                'command' => $this->commandProbe($resolved, $context),
                'command_stdout_empty' => $this->commandStdoutEmptyProbe($resolved, $context),
                'command_occurrences' => $this->commandOccurrencesProbe($resolved, $context),
                'certificate_key_match' => $this->certificateKeyMatchProbe($resolved, $context),
                'certificate_expiry' => $this->certificateExpiryProbe($resolved, $context),
                'service_active' => $this->serviceProbe($resolved, $context),
                'service_enabled' => $this->serviceEnabledProbe($resolved, $context),
                'tcp_connect' => $this->tcpProbe($resolved),
                'database_exists' => $this->databaseExistsProbe($resolved),
                'database_table_exists' => $this->databaseTableExistsProbe($resolved),
                'dns_resolves' => $this->dnsProbe($resolved),
                default => ['healthy' => false, 'expected' => 'supported probe', 'actual' => $type, 'message' => 'Unknown probe type.', 'details' => []],
            };
        } catch (\Throwable $exception) {
            $result = ['healthy' => false, 'expected' => $resolved['expected'] ?? null, 'actual' => null, 'message' => $exception->getMessage(), 'details' => ['exception' => get_class($exception)]];
        }
        $result['type'] = $type;
        $result['duration_ms'] = round((microtime(true) - $started) * 1000, 3);
        $result['timestamp'] = gmdate(DATE_ATOM);
        return new ProbeResult($result);
    }

    private function pathProbe(array $probe, string $kind): array
    {
        $path = (string) ($probe['path'] ?? '');
        $exists = match ($kind) {
            'file' => is_file($path),
            'directory' => is_dir($path),
            default => file_exists($path) || is_link($path),
        };
        return ['healthy' => $exists, 'expected' => "{$kind} exists", 'actual' => $exists ? 'present' : 'missing', 'message' => $exists ? 'Required path exists.' : 'Required path is missing.', 'details' => ['path' => $path]];
    }

    private function accessProbe(array $probe, string $access): array
    {
        $path = (string) ($probe['path'] ?? '');
        $healthy = $access === 'readable' ? is_readable($path) : is_writable($path);
        return ['healthy' => $healthy, 'expected' => $access, 'actual' => $healthy ? $access : 'not ' . $access, 'message' => "Path {$access} check " . ($healthy ? 'passed.' : 'failed.'), 'details' => ['path' => $path, 'mode' => file_exists($path) ? sprintf('%04o', fileperms($path) & 07777) : null, 'uid' => @fileowner($path), 'gid' => @filegroup($path)]];
    }

    private function pathAbsentProbe(array $probe): array
    {
        $path = (string) ($probe['path'] ?? '');
        $absent = !file_exists($path) && !is_link($path);
        return ['healthy' => $absent, 'expected' => 'path absent', 'actual' => $absent ? 'absent' : 'present', 'message' => $absent ? 'Path is absent as expected.' : 'Unexpected or stale path is present.', 'details' => ['path' => $path]];
    }

    private function executableProbe(array $probe, DoctorContext $context): array
    {
        $name = (string) ($probe['command'] ?? '');
        $path = str_contains($name, '/') ? $name : $context->environment->command($name);
        $healthy = is_string($path) && is_file($path) && is_executable($path);
        return ['healthy' => $healthy, 'expected' => 'executable installed', 'actual' => $path ?: 'missing', 'message' => $healthy ? 'Executable is available.' : 'Executable is unavailable.', 'details' => ['command' => $name, 'path' => $path]];
    }

    private function anyExecutableProbe(array $probe, DoctorContext $context): array
    {
        $found = [];
        foreach ((array) ($probe['commands'] ?? []) as $name) {
            $name = (string) $name;
            $candidate = str_contains($name, '/') ? $name : $context->environment->command($name);
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                $found[$name] = $candidate;
            }
        }
        $healthy = $found !== [];
        return ['healthy' => $healthy, 'expected' => 'at least one supported executable', 'actual' => $healthy ? $found : 'none found', 'message' => $healthy ? 'A supported executable is available.' : 'No supported executable is available.', 'details' => ['candidates' => array_values((array) ($probe['commands'] ?? [])), 'found' => $found]];
    }

    private function symlinkProbe(array $probe): array
    {
        $link = (string) ($probe['path'] ?? '');
        $expected = realpath((string) ($probe['target'] ?? '')) ?: (string) ($probe['target'] ?? '');
        $actual = is_link($link) ? (realpath($link) ?: readlink($link)) : null;
        $healthy = is_link($link) && $actual === $expected;
        return ['healthy' => $healthy, 'expected' => $expected, 'actual' => $actual ?? (file_exists($link) ? 'non-link path' : 'missing'), 'message' => $healthy ? 'Symbolic link target is correct.' : 'Symbolic link is missing or points elsewhere.', 'details' => ['path' => $link]];
    }

    private function wrongSymlinkProbe(array $probe): array
    {
        $link = (string) ($probe['path'] ?? '');
        $expected = realpath((string) ($probe['target'] ?? '')) ?: (string) ($probe['target'] ?? '');
        if (!is_link($link)) {
            return [
                'healthy' => true,
                'expected' => 'existing symbolic link points to ' . $expected,
                'actual' => file_exists($link) ? 'non-link path' : 'link absent',
                'message' => 'Wrong-target repair is not applicable because the path is not a symbolic link.',
                'details' => ['path' => $link, 'applicable' => false],
            ];
        }
        $actual = realpath($link) ?: readlink($link);
        $healthy = $actual === $expected;
        return [
            'healthy' => $healthy,
            'expected' => $expected,
            'actual' => $actual,
            'message' => $healthy ? 'Symbolic link target is correct.' : 'Existing symbolic link points to the wrong target.',
            'details' => ['path' => $link, 'applicable' => true],
        ];
    }

    private function fileContainsProbe(array $probe, DoctorContext $context): array
    {
        $path = (string) ($probe['path'] ?? '');
        $needle = (string) ($probe['contains'] ?? '');
        $content = is_file($path) && is_readable($path) ? $context->filesystem->read($path) : '';
        $healthy = $content !== '' && str_contains($content, $needle);
        return ['healthy' => $healthy, 'expected' => $needle, 'actual' => $healthy ? 'present' : 'absent', 'message' => $healthy ? 'Expected configuration content is present.' : 'Expected configuration content is absent.', 'details' => ['path' => $path]];
    }

    private function fileFromTemplateStateProbe(array $probe): array
    {
        $path = (string) ($probe['path'] ?? '');
        $source = (string) ($probe['source'] ?? '');
        $targetPresent = is_file($path) && !is_link($path);
        $sourceReady = is_file($source) && !is_link($source) && is_readable($source);
        // Repair detection is active only when the target is missing and the
        // exact local template needed to restore it is available.
        $healthy = $targetPresent || !$sourceReady;
        return [
            'healthy' => $healthy,
            'expected' => 'target present or exact local template unavailable',
            'actual' => ['target_present' => $targetPresent, 'source_ready' => $sourceReady],
            'message' => !$targetPresent && $sourceReady
                ? 'Target is missing and an exact local restoration template is available.'
                : ($targetPresent ? 'Target file is already present.' : 'Target and approved local template are both unavailable; automatic repair is not applicable.'),
            'details' => ['path' => $path, 'source' => $source],
        ];
    }

    private function environmentValueProbe(array $probe): array
    {
        $path = (string) ($probe['path'] ?? '');
        $key = (string) ($probe['key'] ?? '');
        $expected = array_key_exists('equals', $probe) ? (string) $probe['equals'] : null;
        $actual = null;
        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$candidate, $value] = explode('=', $line, 2);
                if (trim($candidate) === $key) {
                    $actual = trim($value, " \t\n\r\0\x0B\"'");
                    break;
                }
            }
        }
        $healthy = $actual !== null && $actual !== '' && ($expected === null || hash_equals($expected, $actual));
        $shown = preg_match('/pass|secret|token|key|credential/i', $key) ? ($actual === null || $actual === '' ? 'missing' : '[PRESENT]') : ($actual ?? 'missing');
        return ['healthy' => $healthy, 'expected' => $expected ?? 'non-empty value', 'actual' => $shown, 'message' => $healthy ? 'Environment setting is configured.' : 'Environment setting is missing or does not match policy.', 'details' => ['path' => $path, 'key' => $key]];
    }

    private function jsonProbe(array $probe, DoctorContext $context): array
    {
        $path = (string) ($probe['path'] ?? '');
        $valid = false;
        $error = 'file missing';
        if (is_file($path) && is_readable($path)) {
            json_decode($context->filesystem->read($path), true);
            $error = json_last_error_msg();
            $valid = json_last_error() === JSON_ERROR_NONE;
        }
        return ['healthy' => $valid, 'expected' => 'valid JSON', 'actual' => $valid ? 'valid JSON' : $error, 'message' => $valid ? 'JSON document is valid.' : 'JSON document is invalid.', 'details' => ['path' => $path]];
    }

    private function phpExtensionProbe(array $probe): array
    {
        $extension = (string) ($probe['extension'] ?? '');
        $loaded = extension_loaded($extension);
        return ['healthy' => $loaded, 'expected' => 'extension loaded', 'actual' => $loaded ? 'loaded' : 'missing', 'message' => $loaded ? 'PHP extension is loaded.' : 'PHP extension is missing.', 'details' => ['extension' => $extension, 'php_version' => PHP_VERSION]];
    }

    private function phpVersionProbe(array $probe, DoctorContext $context): array
    {
        $minimum = (string) ($probe['minimum'] ?? '8.2.0');
        $actual = (string) $context->environment->get('php_version', PHP_VERSION);
        $healthy = version_compare($actual, $minimum, '>=');
        return ['healthy' => $healthy, 'expected' => '>=' . $minimum, 'actual' => $actual, 'message' => $healthy ? 'PHP version is supported.' : 'PHP version is unsupported.', 'details' => []];
    }

    private function timeSyncProbe(array $probe, DoctorContext $context): array
    {
        $timedatectl = $context->environment->command('timedatectl');
        $timeout = max(1, min(60, (int) ($probe['timeout'] ?? 30)));
        if ($timedatectl === null) {
            return [
                'healthy' => true,
                'skipped' => true,
                'expected' => 'a supported operating-system NTP provider',
                'actual' => 'timedatectl unavailable',
                'message' => 'Time-synchronization policy is not applicable because timedatectl is unavailable.',
                'details' => ['applicable' => false],
            ];
        }
        $capability = $context->process->run([$timedatectl, 'show', '--property=CanNTP', '--value'], $timeout);
        $canNtp = strtolower(trim((string) $capability->get('stdout')));
        if (!$capability->successful() || $canNtp !== 'yes') {
            return [
                'healthy' => true,
                'skipped' => true,
                'expected' => 'CanNTP=yes',
                'actual' => $capability->successful() ? ($canNtp ?: 'empty') : 'inspection failed',
                'message' => 'Time-synchronization policy is not applicable because no supported NTP provider is available.',
                'details' => ['applicable' => false, 'can_ntp' => $canNtp, 'capability' => $capability->toArray()],
            ];
        }
        $state = $context->process->run([$timedatectl, 'show', '--property=NTP', '--value'], $timeout);
        $enabled = strtolower(trim((string) $state->get('stdout')));
        if (!$state->successful() || !in_array($enabled, ['yes', 'no'], true)) {
            return [
                'healthy' => true,
                'skipped' => true,
                'expected' => 'NTP=yes or NTP=no',
                'actual' => $state->successful() ? ($enabled ?: 'empty') : 'inspection failed',
                'message' => 'Time-synchronization state is inconclusive and will not be repaired automatically.',
                'details' => ['applicable' => false, 'state' => $state->toArray()],
            ];
        }
        return [
            'healthy' => $enabled === 'yes',
            'skipped' => false,
            'expected' => 'NTP=yes',
            'actual' => 'NTP=' . $enabled,
            'message' => $enabled === 'yes' ? 'Operating-system time synchronization is enabled.' : 'Operating-system time synchronization is disabled.',
            'details' => ['applicable' => true, 'can_ntp' => true, 'enabled' => $enabled === 'yes'],
        ];
    }

    private function productionDebugProbe(array $probe, DoctorContext $context): array
    {
        $path = (string) ($probe['path'] ?? '');
        $project = realpath($context->projectPath);
        if ($project === false
            || $path !== $project . DIRECTORY_SEPARATOR . '.env'
            || !is_file($path)
            || is_link($path)
            || !is_readable($path)) {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'readable project .env', 'actual' => 'unavailable', 'message' => 'Production debug policy is not applicable to this project file.', 'details' => ['applicable' => false]];
        }
        $contents = $context->filesystem->read($path);
        $environment = DotenvDocument::assignments($contents, 'APP_ENV');
        if (count($environment) !== 1 || strtolower($environment[0]['value']) !== 'production') {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'APP_ENV=production', 'actual' => count($environment) === 1 ? $environment[0]['value'] : 'ambiguous', 'message' => 'Production debug policy is not applicable outside an unambiguous production environment.', 'details' => ['applicable' => false]];
        }
        $debug = DotenvDocument::assignments($contents, 'APP_DEBUG');
        if ($debug === []) {
            return ['healthy' => true, 'skipped' => false, 'expected' => 'APP_DEBUG absent or disabled', 'actual' => 'absent', 'message' => 'Production application debug output is disabled by default.', 'details' => ['applicable' => true, 'configured' => false]];
        }
        if (count($debug) !== 1) {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'one unambiguous APP_DEBUG assignment', 'actual' => 'ambiguous', 'message' => 'Production debug state is ambiguous and will not be changed automatically.', 'details' => ['applicable' => false]];
        }
        $value = strtolower($debug[0]['value']);
        if (!in_array($value, ['true', '1', 'yes', 'on', 'false', '0', 'no', 'off'], true)) {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'a supported boolean APP_DEBUG value', 'actual' => 'unsupported value', 'message' => 'Production debug state is not an exact supported boolean.', 'details' => ['applicable' => false]];
        }
        $enabled = in_array($value, ['true', '1', 'yes', 'on'], true);
        if ($enabled && !empty($probe['require_mutable'])) {
            $metadata = $context->filesystem->metadata($path);
            $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : (int) ($metadata['uid'] ?? -2);
            $effectiveGid = function_exists('posix_getegid') ? posix_getegid() : (int) ($metadata['gid'] ?? -2);
            if (!is_writable($path) || (int) ($metadata['uid'] ?? -1) !== $effectiveUid || (int) ($metadata['gid'] ?? -1) !== $effectiveGid) {
                return ['healthy' => true, 'skipped' => false, 'expected' => 'execution-account-owned writable .env', 'actual' => 'manual ownership boundary', 'message' => 'Production debug is enabled but this exact file requires manual ownership-aware repair.', 'details' => ['applicable' => false, 'manual' => true]];
            }
        }
        return [
            'healthy' => !$enabled,
            'skipped' => false,
            'expected' => 'APP_DEBUG=false',
            'actual' => $enabled ? 'APP_DEBUG enabled' : 'APP_DEBUG disabled',
            'message' => $enabled ? 'Production application debug output is enabled.' : 'Production application debug output is disabled.',
            'details' => ['applicable' => true, 'enabled' => $enabled],
        ];
    }

    private function staleGitIndexLockProbe(array $probe, DoctorContext $context): array
    {
        $path = (string) ($probe['path'] ?? '');
        $minimumAge = max(300, min(604800, (int) ($probe['minimum_age_seconds'] ?? 3600)));
        $project = realpath($context->projectPath);
        if ($project === false || $path !== $project . '/.git/index.lock') {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'project Git index lock', 'actual' => 'outside exact scope', 'message' => 'Stale-lock policy is outside its exact project scope.', 'details' => ['applicable' => false]];
        }
        if (!file_exists($path) && !is_link($path)) {
            return ['healthy' => true, 'skipped' => false, 'expected' => 'index.lock absent', 'actual' => 'absent', 'message' => 'Git index lock is absent.', 'details' => ['applicable' => true]];
        }
        if (!is_file($path) || is_link($path) || !is_readable($path) || !is_writable($path)) {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'writable regular index.lock', 'actual' => 'unsafe file type or access', 'message' => 'Git index lock cannot be classified safely.', 'details' => ['applicable' => false]];
        }
        $stat = lstat($path);
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : (int) ($stat['uid'] ?? -2);
        $effectiveGid = function_exists('posix_getegid') ? posix_getegid() : (int) ($stat['gid'] ?? -2);
        if ($stat === false
            || (int) $stat['uid'] !== $effectiveUid
            || (int) $stat['gid'] !== $effectiveGid
            || (time() - (int) $stat['mtime']) < $minimumAge) {
            return ['healthy' => true, 'skipped' => true, 'expected' => "execution-owned lock aged at least {$minimumAge}s", 'actual' => 'young or ownership boundary', 'message' => 'Git index lock is not proven stale for this execution account.', 'details' => ['applicable' => false]];
        }
        $lsof = $context->environment->command('lsof');
        if ($lsof === null) {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'lsof ownership inspection', 'actual' => 'unavailable', 'message' => 'Git index lock ownership is inconclusive.', 'details' => ['applicable' => false]];
        }
        $owners = $context->process->run([$lsof, '-t', '--', $path], 15);
        $unowned = (int) $owners->get('exit_code', -1) === 1
            && trim((string) $owners->get('stdout')) === ''
            && trim((string) $owners->get('stderr')) === '';
        if (!$unowned) {
            return ['healthy' => true, 'skipped' => true, 'expected' => 'no process owns index.lock', 'actual' => 'active or inconclusive', 'message' => 'Git index lock is active or process ownership is inconclusive.', 'details' => ['applicable' => false]];
        }
        return ['healthy' => false, 'skipped' => false, 'expected' => 'verified stale index.lock absent', 'actual' => 'aged unowned lock present', 'message' => 'A verified stale Git index lock is present.', 'details' => ['applicable' => true, 'minimum_age_seconds' => $minimumAge]];
    }

    private function diskProbe(array $probe): array
    {
        $path = (string) ($probe['path'] ?? '/');
        $minimum = (float) ($probe['minimum'] ?? 10);
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        $percent = $free !== false && $total !== false && $total > 0 ? ($free / $total) * 100 : 0;
        return ['healthy' => $percent >= $minimum, 'expected' => $minimum . '% free', 'actual' => round($percent, 2) . '% free', 'message' => $percent >= $minimum ? 'Disk capacity is within policy.' : 'Disk free space is below policy.', 'details' => ['path' => $path, 'free_bytes' => $free, 'total_bytes' => $total]];
    }

    private function filesystemMountProbe(array $probe, DoctorContext $context): array
    {
        $path = (string) ($probe['path'] ?? '');
        $findmnt = $context->environment->command('findmnt');
        if ($findmnt === null || !is_dir($path)) {
            return ['healthy' => false, 'skipped' => true, 'expected' => 'read-write filesystem mount', 'actual' => 'mount inspection unavailable', 'message' => 'Filesystem mount mode could not be inspected.', 'details' => ['path' => $path]];
        }
        $result = $context->process->run([$findmnt, '-n', '-o', 'OPTIONS', '--target', $path], 10);
        $options = trim((string) $result->get('stdout'));
        $optionList = array_filter(array_map('trim', explode(',', $options)));
        $healthy = $result->successful() && !in_array('ro', $optionList, true);
        return [
            'healthy' => $healthy,
            'expected' => 'read-write filesystem mount',
            'actual' => $options !== '' ? $options : 'unknown',
            'message' => $healthy ? 'Application filesystem is mounted read-write.' : 'Application filesystem is mounted read-only or could not be inspected.',
            'details' => ['path' => $path, 'command' => $result->toArray()],
        ];
    }

    private function globProbe(array $probe): array
    {
        $pattern = (string) ($probe['pattern'] ?? '');
        $matches = $pattern !== '' ? array_values(array_filter(glob($pattern) ?: [], 'is_file')) : [];
        $healthy = $matches !== [];
        return [
            'healthy' => $healthy,
            'expected' => 'at least one matching file',
            'actual' => $healthy ? 'matching file present' : 'no matching file',
            'message' => $healthy ? 'A required matching file exists.' : 'No required matching file exists.',
            'details' => ['pattern' => $pattern, 'matches' => $matches],
        ];
    }

    private function managedArtifactIntegrityProbe(array $probe, DoctorContext $context): array
    {
        $authorized = $this->artifacts->authorize(
            (string) ($probe['repair_id'] ?? ''),
            (string) ($probe['path'] ?? ''),
            (string) ($probe['baseline'] ?? ''),
            strtolower((string) ($probe['sha256'] ?? '')),
            (int) ($probe['mode'] ?? 0),
        );
        $path = $authorized['target_path'];
        $expectedHash = $authorized['sha256'];
        $expectedMode = $authorized['mode'];
        $actualHash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : null;
        $actualMode = is_file($path) && !is_link($path) ? (fileperms($path) & 0777) : null;
        $healthy = is_string($actualHash)
            && hash_equals($expectedHash, $actualHash)
            && $actualMode === $expectedMode;
        $asOf = trim((string) $context->option('as_of', ''));

        return [
            'healthy' => $healthy,
            'expected' => ['sha256' => $expectedHash, 'mode' => sprintf('%04o', $expectedMode)],
            'actual' => [
                'state' => is_link($path) ? 'symbolic-link-rejected' : (is_file($path) ? 'file' : 'missing'),
                'sha256' => $actualHash,
                'mode' => $actualMode === null ? null : sprintf('%04o', $actualMode),
            ],
            'message' => $healthy
                ? 'Managed release artifact matches its immutable local baseline.'
                : 'Managed release artifact is missing or differs from its immutable local baseline.',
            'details' => [
                'repair_id' => $authorized['repair_id'],
                'path' => $path,
                'baseline' => $authorized['baseline_path'],
                'size' => $authorized['size'],
                'as_of' => $asOf !== '' ? $asOf : null,
            ],
        ];
    }

    private function managedDirectoryIntegrityProbe(array $probe, DoctorContext $context): array
    {
        $authorized = $this->artifacts->authorizeDirectory(
            (string) ($probe['repair_id'] ?? ''),
            (string) ($probe['path'] ?? ''),
            (int) ($probe['mode'] ?? 0),
            (string) ($probe['condition'] ?? ''),
        );
        $path = $authorized['target_path'];
        $obstruction = null;
        $cursor = $authorized['root_path'];
        foreach (explode('/', $authorized['path']) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                $obstruction = 'symbolic-link-obstruction';
                break;
            }
            if (file_exists($cursor) && !is_dir($cursor)) {
                $obstruction = 'non-directory-obstruction';
                break;
            }
        }
        if ($obstruction !== null) {
            return [
                'healthy' => true,
                'skipped' => true,
                'expected' => ['state' => 'directory', 'mode' => sprintf('%04o', $authorized['mode'])],
                'actual' => ['state' => $obstruction],
                'message' => 'Managed-directory automation is not applicable because an existing path requires operator review.',
                'details' => ['repair_id' => $authorized['repair_id'], 'path' => $path, 'applicable' => false],
            ];
        }
        $present = is_dir($path);
        $actualMode = $present ? (fileperms($path) & 0777) : null;
        $asOf = trim((string) $context->option('as_of', ''));
        if ($authorized['condition'] === 'mode' && !$present) {
            return [
                'healthy' => true,
                'skipped' => true,
                'expected' => ['state' => 'existing directory', 'mode' => sprintf('%04o', $authorized['mode'])],
                'actual' => ['state' => 'missing', 'mode' => null],
                'message' => 'Directory-mode repair is not applicable because the distinct missing-directory contract owns this state.',
                'details' => [
                    'repair_id' => $authorized['repair_id'],
                    'path' => $path,
                    'condition' => 'mode',
                    'as_of' => $asOf !== '' ? $asOf : null,
                    'applicable' => false,
                ],
            ];
        }
        $healthy = $authorized['condition'] === 'missing'
            ? $present
            : $actualMode === $authorized['mode'];
        return [
            'healthy' => $healthy,
            'expected' => $authorized['condition'] === 'missing'
                ? ['state' => 'directory']
                : ['state' => 'existing directory', 'mode' => sprintf('%04o', $authorized['mode'])],
            'actual' => [
                'state' => $present ? 'directory' : 'missing',
                'mode' => $actualMode === null ? null : sprintf('%04o', $actualMode),
            ],
            'message' => $healthy
                ? ($authorized['condition'] === 'missing'
                    ? 'Required managed release directory is present.'
                    : 'Managed release directory mode matches its immutable contract.')
                : ($authorized['condition'] === 'missing'
                    ? 'Required managed release directory is missing.'
                    : 'Managed release directory has the wrong mode.'),
            'details' => [
                'repair_id' => $authorized['repair_id'],
                'path' => $path,
                'condition' => $authorized['condition'],
                'as_of' => $asOf !== '' ? $asOf : null,
                'applicable' => true,
            ],
        ];
    }

    private function commandProbe(array $probe, DoctorContext $context): array
    {
        $command = array_map('strval', (array) ($probe['command'] ?? []));
        if ($command === []) {
            throw new \InvalidArgumentException('Command probe is missing its command.');
        }
        if (!str_contains($command[0], '/')) {
            $command[0] = $context->environment->command($command[0]) ?? $command[0];
        }
        $environment = (array) ($probe['environment'] ?? []);
        if (basename($command[0]) === 'composer') {
            // Apache/XAMPP exports its private libraries into the web process.
            // System Composer runs with system PHP and must not inherit those
            // incompatible libraries. Apache also commonly runs without HOME,
            // so Composer receives an isolated, writable Doctor runtime home.
            $runtimeRoot = rtrim((string) $context->option('doctor_runtime_root', STORAGE_PATH . '/doctor'), '/');
            $composerHome = $runtimeRoot . '/composer-home';
            $environment['LD_LIBRARY_PATH'] = '';
            $environment['HOME'] = $composerHome;
            $environment['COMPOSER_HOME'] = $composerHome;
            $environment['COMPOSER_CACHE_DIR'] = $composerHome . '/cache';
            $environment['XDG_CACHE_HOME'] = $composerHome . '/cache';
        }
        $result = $context->process->run(
            $command,
            (int) ($probe['timeout'] ?? 20),
            isset($probe['cwd']) ? (string) $probe['cwd'] : null,
            array_map('strval', $environment),
        );
        $expectedExits = isset($probe['expected_exits'])
            ? array_values(array_unique(array_map('intval', (array) $probe['expected_exits'])))
            : [(int) ($probe['expected_exit'] ?? 0)];
        $healthy = in_array($result->exitCode(), $expectedExits, true);
        if (isset($probe['stdout_contains'])) {
            $healthy = $healthy && str_contains((string) $result->get('stdout'), (string) $probe['stdout_contains']);
        }
        foreach ((array) ($probe['stdout_contains_all'] ?? []) as $needle) {
            $healthy = $healthy && str_contains((string) $result->get('stdout'), (string) $needle);
        }
        if (!empty($probe['stderr_empty'])) {
            $healthy = $healthy && trim((string) $result->get('stderr')) === '';
        }
        $permissionDenied = str_contains(strtolower((string) $result->get('stdout') . "\n" . (string) $result->get('stderr')), 'permission denied');
        $skipped = !$healthy && !empty($probe['skip_on_permission_denied']) && $permissionDenied;
        $inspectionOnly = false;
        $writePath = (string) ($probe['healthy_when_path_not_writable'] ?? '');
        if (!$healthy && $writePath !== '' && (file_exists($writePath) || is_link($writePath)) && !is_writable($writePath)) {
            $healthy = true;
            $inspectionOnly = true;
            $skipped = false;
        }
        return [
            'healthy' => $healthy,
            'skipped' => $skipped,
            'expected' => [
                'exit_codes' => $expectedExits,
                'stdout_contains' => $probe['stdout_contains'] ?? null,
                'stdout_contains_all' => $probe['stdout_contains_all'] ?? [],
                'stderr_empty' => !empty($probe['stderr_empty']),
                'write_required' => $writePath !== '' ? $writePath : null,
            ],
            'actual' => ['exit_code' => $result->exitCode(), 'stdout' => $result->get('stdout'), 'stderr' => $result->get('stderr')],
            'message' => $inspectionOnly
                ? 'Repository is inspection-only; a web-service Git author identity is not required.'
                : ($skipped ? 'Command probe is inconclusive because the execution account lacks inspection permission.' : ($healthy ? 'Command probe passed.' : 'Command probe failed.')),
            'details' => [...$result->toArray(), 'inspection_only' => $inspectionOnly, 'write_path' => $writePath !== '' ? $writePath : null],
        ];
    }

    private function commandStdoutEmptyProbe(array $probe, DoctorContext $context): array
    {
        $base = $this->commandProbe($probe, $context);
        if (!empty($base['skipped'])) {
            return $base;
        }
        $stdout = (string) (($base['actual']['stdout'] ?? ''));
        $healthy = (bool) $base['healthy'] && trim($stdout) === '';
        return ['healthy' => $healthy, 'expected' => 'successful command with empty output', 'actual' => trim($stdout) === '' ? 'empty' : 'unexpected records present', 'message' => $healthy ? 'Command returned no problem records.' : 'Command returned one or more problem records.', 'details' => $base['details']];
    }

    private function commandOccurrencesProbe(array $probe, DoctorContext $context): array
    {
        $base = $this->commandProbe($probe, $context);
        $needle = (string) ($probe['needle'] ?? '');
        $count = $needle === '' ? 0 : substr_count((string) ($base['actual']['stdout'] ?? ''), $needle);
        $minimum = (int) ($probe['minimum'] ?? 0);
        $maximum = (int) ($probe['maximum'] ?? PHP_INT_MAX);
        $healthy = (bool) $base['healthy'] && $count >= $minimum && $count <= $maximum;
        return ['healthy' => $healthy, 'expected' => "{$minimum}..{$maximum} occurrences", 'actual' => $count, 'message' => $healthy ? 'Command output occurrence policy passed.' : 'Command output occurrence policy failed.', 'details' => [...$base['details'], 'needle' => $needle, 'occurrences' => $count]];
    }

    private function certificateKeyMatchProbe(array $probe, DoctorContext $context): array
    {
        $openssl = $context->environment->command('openssl');
        $certificate = (string) ($probe['certificate'] ?? '');
        $key = (string) ($probe['key'] ?? '');
        if ($openssl === null || !is_file($certificate) || !is_file($key)) {
            return ['healthy' => false, 'expected' => 'matching certificate and private key', 'actual' => 'required input unavailable', 'message' => 'Certificate/key matching cannot be completed.', 'details' => ['certificate' => $certificate, 'key' => $key]];
        }
        if (!is_readable($certificate) || !is_readable($key)) {
            return [
                'healthy' => false,
                'skipped' => true,
                'expected' => 'matching certificate and private key',
                'actual' => 'private key is intentionally unavailable to the web execution account',
                'message' => 'Certificate/key matching is inconclusive without private-key read permission.',
                'details' => ['certificate' => $certificate, 'key' => $key],
            ];
        }
        $certificatePublic = $context->process->run([$openssl, 'x509', '-in', $certificate, '-pubkey', '-noout'], 10);
        $keyPublic = $context->process->run([$openssl, 'pkey', '-in', $key, '-pubout'], 10);
        $certificateHash = hash('sha256', trim((string) $certificatePublic->get('stdout')));
        $keyHash = hash('sha256', trim((string) $keyPublic->get('stdout')));
        $healthy = $certificatePublic->successful() && $keyPublic->successful() && hash_equals($certificateHash, $keyHash);
        return ['healthy' => $healthy, 'expected' => 'matching public-key fingerprints', 'actual' => $healthy ? 'matched' : 'mismatch or unreadable key', 'message' => $healthy ? 'TLS certificate and private key match.' : 'TLS certificate and private key do not match.', 'details' => ['certificate' => $certificate, 'key' => $key, 'certificate_public_hash' => $certificateHash, 'key_public_hash' => $keyHash]];
    }

    private function certificateExpiryProbe(array $probe, DoctorContext $context): array
    {
        $openssl = $context->environment->command('openssl');
        $certificate = (string) ($probe['certificate'] ?? '');
        $minimum = max(0, (int) ($probe['minimum_valid_seconds'] ?? 0));
        $mode = (string) ($probe['mode'] ?? 'expired');
        if ($openssl === null || !is_file($certificate)) {
            return ['healthy' => false, 'expected' => 'readable certificate expiry', 'actual' => 'unavailable', 'message' => 'Certificate expiry cannot be inspected.', 'details' => ['certificate' => $certificate]];
        }
        $result = $context->process->run([$openssl, 'x509', '-enddate', '-noout', '-in', $certificate], 10);
        $value = trim((string) $result->get('stdout'));
        $expiresAt = str_starts_with($value, 'notAfter=') ? strtotime(substr($value, strlen('notAfter='))) : false;
        if (!$result->successful() || $expiresAt === false) {
            return ['healthy' => false, 'expected' => 'parseable certificate expiry', 'actual' => $value ?: (string) $result->get('stderr'), 'message' => 'Certificate expiry is invalid or unreadable.', 'details' => ['certificate' => $certificate, 'command' => $result->toArray()]];
        }
        $remaining = $expiresAt - time();
        if ($mode === 'expiring' && $remaining <= 0) {
            return ['healthy' => true, 'skipped' => true, 'expected' => "valid for at least {$minimum} seconds", 'actual' => 'already expired', 'message' => 'Expiring-soon check is skipped because the expired-certificate check owns this cause.', 'details' => ['certificate' => $certificate, 'expires_at' => gmdate(DATE_ATOM, $expiresAt), 'remaining_seconds' => $remaining]];
        }
        $healthy = $mode === 'expired' ? $remaining > 0 : $remaining >= $minimum;
        return ['healthy' => $healthy, 'expected' => $mode === 'expired' ? 'certificate not expired' : "valid for at least {$minimum} seconds", 'actual' => ['expires_at' => gmdate(DATE_ATOM, $expiresAt), 'remaining_seconds' => $remaining], 'message' => $healthy ? 'Certificate expiry is within policy.' : ($mode === 'expired' ? 'Certificate is expired.' : 'Certificate is approaching expiration.'), 'details' => ['certificate' => $certificate, 'expires_at' => gmdate(DATE_ATOM, $expiresAt), 'remaining_seconds' => $remaining]];
    }

    private function serviceProbe(array $probe, DoctorContext $context): array
    {
        $service = (string) ($probe['service'] ?? '');
        if (isset(\SysAdmin\ServiceControlManager::LABELS[$service])) {
            $state = (new \SysAdmin\ServiceControlManager())->perform($service, 'status');
            $healthy = !empty($state['ok']) && !empty($state['running']);
            return [
                'healthy' => $healthy,
                'expected' => 'active',
                'actual' => $healthy ? 'active' : 'inactive',
                'message' => $healthy ? 'Service is active.' : 'Service is not active.',
                'details' => $state,
            ];
        }
        $systemctl = $context->environment->command('systemctl');
        if ($systemctl === null) {
            return ['healthy' => false, 'expected' => 'active', 'actual' => 'service manager unsupported', 'message' => 'Service state is unavailable.', 'details' => ['service' => $service]];
        }
        $result = $context->process->run([$systemctl, 'is-active', $service], 10);
        $state = trim((string) $result->get('stdout')) ?: trim((string) $result->get('stderr'));
        return ['healthy' => $result->successful() && $state === 'active', 'expected' => 'active', 'actual' => $state ?: 'unknown', 'message' => $state === 'active' ? 'Service is active.' : 'Service is not active.', 'details' => ['service' => $service, 'command' => $result->toArray()]];
    }

    private function serviceEnabledProbe(array $probe, DoctorContext $context): array
    {
        $service = (string) ($probe['service'] ?? '');
        $systemctl = $context->environment->command('systemctl');
        if ($systemctl === null) {
            return ['healthy' => false, 'expected' => 'enabled', 'actual' => 'service manager unsupported', 'message' => 'Service enablement is unavailable.', 'details' => ['service' => $service]];
        }
        $result = $context->process->run([$systemctl, 'is-enabled', $service], 10);
        $state = trim((string) $result->get('stdout')) ?: trim((string) $result->get('stderr'));
        return ['healthy' => $result->successful() && $state === 'enabled', 'expected' => 'enabled', 'actual' => $state ?: 'unknown', 'message' => $state === 'enabled' ? 'Service is enabled.' : 'Service is not enabled.', 'details' => ['service' => $service, 'command' => $result->toArray()]];
    }

    private function tcpProbe(array $probe): array
    {
        $host = (string) ($probe['host'] ?? '127.0.0.1');
        $port = (int) ($probe['port'] ?? 0);
        $timeout = min(5.0, max(0.1, (float) ($probe['timeout'] ?? 1.0)));
        $errno = 0;
        $error = '';
        $socket = @fsockopen($host, $port, $errno, $error, $timeout);
        $healthy = is_resource($socket);
        if ($healthy) {
            fclose($socket);
        }
        return ['healthy' => $healthy, 'expected' => 'TCP connection succeeds', 'actual' => $healthy ? 'connected' : "{$errno}: {$error}", 'message' => $healthy ? 'TCP endpoint is reachable.' : 'TCP endpoint is unavailable.', 'details' => ['host' => $host, 'port' => $port]];
    }

    private function databaseExistsProbe(array $probe): array
    {
        $database = (string) ($probe['database'] ?? config_value('database.name', 'microsysadmin'));
        $statement = \SysAdmin\Database::pdo()->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $statement->execute([$database]);
        $exists = $statement->fetchColumn() !== false;
        return ['healthy' => $exists, 'expected' => 'database exists', 'actual' => $exists ? 'present' : 'missing', 'message' => $exists ? 'Configured database exists.' : 'Configured database is missing.', 'details' => ['database' => $database]];
    }

    private function databaseTableExistsProbe(array $probe): array
    {
        $database = (string) ($probe['database'] ?? config_value('database.name', 'microsysadmin'));
        $table = (string) ($probe['table'] ?? '');
        $statement = \SysAdmin\Database::pdo()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1');
        $statement->execute([$database, $table]);
        $exists = $statement->fetchColumn() !== false;
        return ['healthy' => $exists, 'expected' => 'table exists', 'actual' => $exists ? 'present' : 'missing', 'message' => $exists ? 'Required database table exists.' : 'Required database table is missing.', 'details' => ['database' => $database, 'table' => $table]];
    }

    private function dnsProbe(array $probe): array
    {
        $host = (string) ($probe['host'] ?? 'localhost');
        $resolved = gethostbyname($host);
        $healthy = $resolved !== $host || filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost';
        return ['healthy' => $healthy, 'expected' => 'resolvable host', 'actual' => $healthy ? $resolved : 'unresolved', 'message' => $healthy ? 'DNS resolution succeeded.' : 'DNS resolution failed.', 'details' => ['host' => $host, 'address' => $healthy ? $resolved : null]];
    }
}
