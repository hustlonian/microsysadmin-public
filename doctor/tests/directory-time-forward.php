#!/usr/bin/env php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('STORAGE_PATH', APP_ROOT . '/storage');
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\engine\Backup\BackupManager;
use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Detection\ProbeExecutor;
use SysAdmin\Doctor\engine\Filesystem\ManagedArtifactCatalog;
use SysAdmin\Doctor\engine\Filesystem\SafeFilesystem;
use SysAdmin\Doctor\engine\Logging\DoctorAuditLogger;
use SysAdmin\Doctor\engine\Logging\DoctorRepository;
use SysAdmin\Doctor\engine\Planning\PolicyEngine;
use SysAdmin\Doctor\engine\Process\ProcessRunner;
use SysAdmin\Doctor\engine\Registry\DefinitionCheck;
use SysAdmin\Doctor\engine\Registry\DefinitionDiagnosis;
use SysAdmin\Doctor\engine\Registry\DefinitionRepair;
use SysAdmin\Doctor\engine\Security\SecretRedactor;
use SysAdmin\Doctor\engine\Support\CheckStatus;
use SysAdmin\Doctor\engine\Support\TemplateResolver;

$manifestPath = APP_ROOT . '/doctor/baselines/release-artifacts/manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$missingDirectories = array_values((array) ($manifest['directories'] ?? []));
$modeRepairs = array_values((array) ($manifest['directory_mode_repairs'] ?? []));
$directories = [...$missingDirectories, ...$modeRepairs];
$horizons = array_values((array) ($manifest['time_forward_horizons'] ?? []));
if ((int) ($manifest['schema_version'] ?? 0) !== 3
    || count($missingDirectories) < 200
    || count($missingDirectories) !== count($modeRepairs)
    || count($horizons) < 3) {
    fwrite(STDERR, "Directory time-forward library requires schema 3, paired cause owners for at least 200 directories, and three horizons.\n");
    exit(1);
}
usort($directories, static function (array $left, array $right): int {
    $depth = substr_count((string) $right['path'], '/') <=> substr_count((string) $left['path'], '/');
    return $depth !== 0 ? $depth : strcmp((string) $left['path'], (string) $right['path']);
});

$temporary = sys_get_temp_dir() . '/microsysadmin-directory-time-forward-' . bin2hex(random_bytes(6));
$shadow = $temporary . '/application';
$runtime = $temporary . '/runtime';
foreach ([$shadow, $runtime, $runtime . '/backups', $runtime . '/locks', $shadow . '/doctor/baselines/release-artifacts'] as $directory) {
    if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create directory time-forward path {$directory}.");
    }
}
copy($manifestPath, $shadow . '/doctor/baselines/release-artifacts/manifest.json');

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$replaceRoot = static function (mixed $value) use (&$replaceRoot, $shadow): mixed {
    if (is_string($value)) {
        return str_replace('{APP_ROOT}', $shadow, $value);
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = $replaceRoot($item);
        }
    }
    return $value;
};
$identity = static function (string $path): string {
    $stat = lstat($path);
    return $stat === false ? '' : (int) $stat['dev'] . ':' . (int) $stat['ino'];
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE doctor_audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, execution_id TEXT, level TEXT, event_type TEXT, message TEXT, context_json TEXT, created_at TEXT)');
    $repository = new DoctorRepository($pdo);
    $redactor = new SecretRedactor();
    $audit = new DoctorAuditLogger($repository, $redactor, 'doc_directory_time_forward');
    $runner = new ProcessRunner($redactor, $audit, 65536, 5);
    $filesystem = new SafeFilesystem([$temporary]);
    $backups = new BackupManager($filesystem, $runtime . '/backups');
    $environment = new EnvironmentContext(['managed_release_integrity' => true, 'commands' => []]);
    $catalog = new ManagedArtifactCatalog($shadow);
    $resolver = new TemplateResolver();
    $probes = new ProbeExecutor($resolver, $catalog);
    $policy = new PolicyEngine(require APP_ROOT . '/doctor/config/policies.php');
    $entriesByPath = [];
    foreach ($missingDirectories as $entry) {
        $entriesByPath[(string) $entry['path']] = $entry;
    }
    $seenIds = [];
    $seenPaths = [];
    $scenarios = 0;

    // Prove one deep repair owns and reverses its complete missing ancestor
    // chain before the per-repair horizon matrix creates any release paths.
    $deep = null;
    foreach ($missingDirectories as $entry) {
        if (substr_count((string) $entry['path'], '/') >= 3 && !str_starts_with((string) $entry['path'], 'doctor/')) {
            $deep = $entry;
            break;
        }
    }
    if (is_array($deep)) {
        $definitionFiles = (array) $deep['definition_files'];
        $repairDefinition = $replaceRoot(json_decode((string) file_get_contents(
            APP_ROOT . '/doctor/modules/artifact-integrity/' . (string) $definitionFiles[2]
        ), true, 512, JSON_THROW_ON_ERROR));
        $repair = new DefinitionRepair($repairDefinition, $probes, $resolver, $catalog);
        $context = new DoctorContext('doc_directory_chain', $environment, $shadow, ['as_of' => $horizons[0], 'doctor_runtime_root' => $runtime], $runner, $filesystem, $backups, $audit, $redactor);
        $detection = $repair->detect($context);
        $plan = $repair->plan($context, $detection);
        $repair->backup($context, $plan);
        $result = $repair->repair($context, $plan);
        $created = (array) (($result->get('changes')[0]['created_directories'] ?? []));
        $assert($result->successful() && count($created) >= 2, 'A deep managed-directory repair creates its complete missing authorized parent chain.');
        $assert($repair->verify($context, $result)->passed(), 'The deep managed-directory chain passes independent verification.');
        $rollback = $repair->rollback($context, $result);
        $assert($rollback->successful() && !file_exists($shadow . '/' . (string) $deep['path']), 'The deep managed-directory chain rolls back every created segment in reverse order.');
    }

    foreach ($directories as $entry) {
        $repairId = (string) ($entry['repair_id'] ?? '');
        $relative = (string) ($entry['path'] ?? '');
        $mode = (int) ($entry['mode'] ?? 0);
        $condition = (string) ($entry['condition'] ?? '');
        $assert(!isset($seenIds[$repairId]) && preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId), "{$repairId} retains a unique permanent directory ID.");
        $pathCondition = $relative . ':' . $condition;
        $assert(in_array($condition, ['missing', 'mode'], true) && !isset($seenPaths[$pathCondition]) && $relative !== '', "{$repairId} owns exactly one unique release directory condition.");
        $seenIds[$repairId] = true;
        $seenPaths[$pathCondition] = true;

        $definitionFiles = (array) ($entry['definition_files'] ?? []);
        $base = APP_ROOT . '/doctor/modules/artifact-integrity/';
        $checkDefinition = $replaceRoot(json_decode((string) file_get_contents($base . (string) ($definitionFiles[0] ?? '')), true, 512, JSON_THROW_ON_ERROR));
        $diagnosisDefinition = $replaceRoot(json_decode((string) file_get_contents($base . (string) ($definitionFiles[1] ?? '')), true, 512, JSON_THROW_ON_ERROR));
        $repairDefinition = $replaceRoot(json_decode((string) file_get_contents($base . (string) ($definitionFiles[2] ?? '')), true, 512, JSON_THROW_ON_ERROR));
        $check = new DefinitionCheck($checkDefinition, $probes);
        $diagnosis = new DefinitionDiagnosis($diagnosisDefinition);
        $repair = new DefinitionRepair($repairDefinition, $probes, $resolver, $catalog);
        $metadata = $repair->metadata();
        $assert($metadata->automationClass()->value === 'AUTO_SAFE'
            && $metadata->get('required_commands') === []
            && $metadata->get('required_packages') === []
            && $metadata->get('network_effects') === [],
            "{$repairId} is zero-interaction, local-only, and dependency-free.");

        $target = $shadow . '/' . $relative;
        $parents = [];
        $parent = dirname($relative);
        while ($parent !== '.') {
            $parents[] = $parent;
            $parent = dirname($parent);
        }
        foreach (array_reverse($parents) as $parent) {
            $parentTarget = $shadow . '/' . $parent;
            if (!isset($entriesByPath[$parent])) {
                if ($parent === 'doctor' && is_dir($parentTarget) && !is_link($parentTarget)) {
                    // The Doctor bootstrap root is deliberately outside the
                    // self-repair topology. Preserve the fixture-owned root;
                    // only its manifest-authorized descendants are mutable.
                    continue;
                }
                throw new RuntimeException("Directory parent lacks an exact topology owner: {$parent}");
            }
            if (!is_dir($parentTarget)) {
                mkdir($parentTarget, (int) $entriesByPath[$parent]['mode']);
            }
            chmod($parentTarget, (int) $entriesByPath[$parent]['mode']);
        }

        if (is_dir($target)) {
            $children = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
            if ($children !== []) {
                throw new RuntimeException("Directory cause-ownership target is unexpectedly non-empty: {$relative}");
            }
            rmdir($target);
        }
        $ownershipContext = new DoctorContext(
            'doc_directory_cause_ownership',
            $environment,
            $shadow,
            ['as_of' => (string) $horizons[0], 'doctor_runtime_root' => $runtime],
            $runner,
            $filesystem,
            $backups,
            $audit,
            $redactor,
        );
        if ($condition === 'mode') {
            $ownershipDetection = $repair->detect($ownershipContext);
            $assert($check->run($ownershipContext)->status === CheckStatus::SKIPPED && !$ownershipDetection->detected(),
                "{$repairId} leaves a missing directory exclusively to its absence owner.");
            $stalePlan = $repair->plan($ownershipContext, $ownershipDetection);
            $staleResult = $repair->repair($ownershipContext, $stalePlan);
            $assert(!$staleResult->successful() && !file_exists($target) && !is_link($target),
                "{$repairId} refuses a stale mode plan after the directory becomes missing.");
        } else {
            $driftMode = $mode === 0700 ? 0755 : 0700;
            mkdir($target, $driftMode);
            chmod($target, $driftMode);
            $ownershipDetection = $repair->detect($ownershipContext);
            $assert($check->run($ownershipContext)->status === CheckStatus::PASS && !$ownershipDetection->detected(),
                "{$repairId} leaves existing mode drift exclusively to its mode owner.");
            $stalePlan = $repair->plan($ownershipContext, $ownershipDetection);
            $staleResult = $repair->repair($ownershipContext, $stalePlan);
            clearstatcache(true, $target);
            $assert($staleResult->successful() && (fileperms($target) & 0777) === $driftMode,
                "{$repairId} leaves a stale absence plan idempotent without changing an existing directory mode.");
            rmdir($target);
        }

        foreach ($horizons as $horizonIndex => $horizon) {
            if (is_dir($target)) {
                $children = array_values(array_diff(scandir($target) ?: [], ['.', '..']));
                if ($children !== []) {
                    throw new RuntimeException("Directory time-forward target is unexpectedly non-empty: {$relative}");
                }
                rmdir($target);
            }
            $modeScenario = $condition === 'mode';
            $priorIdentity = '';
            $priorMode = null;
            if ($modeScenario) {
                $priorMode = $mode === 0700 ? 0755 : 0700;
                mkdir($target, $priorMode);
                chmod($target, $priorMode);
                $priorIdentity = $identity($target);
            }
            $context = new DoctorContext(
                'doc_directory_time_forward',
                $environment,
                $shadow,
                ['as_of' => (string) $horizon, 'doctor_runtime_root' => $runtime],
                $runner,
                $filesystem,
                $backups,
                $audit,
                $redactor,
            );
            $failedCheck = $check->run($context);
            $assert($failedCheck->status === CheckStatus::FAIL, "{$repairId} detects {$horizon} directory drift.");
            $diagnosed = $diagnosis->diagnose($context, $failedCheck);
            $assert((bool) $diagnosed->get('condition_detected') && $diagnosed->repairIds() === [$repairId], "{$repairId} diagnoses only its own {$horizon} directory cause.");
            $detection = $repair->detect($context);
            $plan = $repair->plan($context, $detection);
            $assert($detection->detected() && count($plan->actions()) === 1, "{$repairId} produces one exact {$horizon} directory plan.");
            $decision = $policy->evaluate($metadata, $diagnosed, $plan, 'safe');
            $assert($decision->allowed() && !$decision->requiresApproval(), "{$repairId} is zero-interaction under the local safe policy at {$horizon}.");
            $backup = $repair->backup($context, $plan);
            $result = $repair->repair($context, $plan);
            $verification = $repair->verify($context, $result);
            $assert($backup->successful() && $result->successful(), "{$repairId} journals and repairs locally at {$horizon}: " . (string) $result->get('message', 'no result message'));
            $assert($verification->passed() && is_dir($target) && !is_link($target) && (fileperms($target) & 0777) === $mode, "{$repairId} independently verifies exact {$horizon} directory state.");
            $repeat = $repair->repair($context, $plan);
            $assert($repeat->successful() && $identity($target) !== '' && (fileperms($target) & 0777) === $mode, "{$repairId} remains idempotent at {$horizon}.");
            $rollback = $repair->rollback($context, $result);
            $assert($rollback->successful(), "{$repairId} rolls back its {$horizon} directory repair.");
            if ($modeScenario) {
                $assert(is_dir($target) && $identity($target) === $priorIdentity && (fileperms($target) & 0777) === $priorMode, "{$repairId} rollback restores the prior identity and mode at {$horizon}.");
            } else {
                $assert(!file_exists($target) && !is_link($target), "{$repairId} rollback removes only its unchanged empty directory at {$horizon}.");
            }
            $assert(!$repair->verify($context, $result)->passed(), "{$repairId} post-rollback verification detects prior {$horizon} drift.");
            $scenarios++;
        }
        if (is_dir($target)) {
            rmdir($target);
        }
        if (!symlink($runtime, $target)) {
            throw new RuntimeException("Unable to create obstruction contract symlink for {$repairId}.");
        }
        $obstructionContext = new DoctorContext(
            'doc_directory_obstruction',
            $environment,
            $shadow,
            ['as_of' => (string) end($horizons), 'doctor_runtime_root' => $runtime],
            $runner,
            $filesystem,
            $backups,
            $audit,
            $redactor,
        );
        $obstructedCheck = $check->run($obstructionContext);
        $obstructedDetection = $repair->detect($obstructionContext);
        $assert($obstructedCheck->status === CheckStatus::SKIPPED && !$obstructedDetection->detected(),
            "{$repairId} excludes a symbolic obstruction from automatic mutation.");
        unlink($target);
    }

    $assert(count($seenIds) === count($directories), 'Directory time-forward suite covers every active permanent topology repair ID.');
    $assert($scenarios === count($directories) * count($horizons), 'Every managed directory is exercised at every future horizon.');
} finally {
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                rmdir($entry->getPathname());
            }
        }
        rmdir($temporary);
    }
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("Directory time-forward tests: %d scenarios, %d assertions, %d failures.\n", $scenarios ?? 0, $assertions, count($failures)));
    exit(1);
}

echo sprintf(
    "Directory time-forward tests: %d cause-specific repairs x %d horizons = %d scenarios, %d assertions, 0 failures.\n",
    count($directories),
    count($horizons),
    $scenarios,
    $assertions,
);
