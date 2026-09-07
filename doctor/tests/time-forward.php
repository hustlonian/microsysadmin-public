#!/opt/lampp/bin/php
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
$artifacts = (array) ($manifest['artifacts'] ?? []);
$directories = (array) ($manifest['directories'] ?? []);
$directoryModeRepairs = (array) ($manifest['directory_mode_repairs'] ?? []);
$horizons = (array) ($manifest['time_forward_horizons'] ?? []);
if (count($artifacts) < 1024 || count($horizons) < 3) {
    fwrite(STDERR, "Time-forward library requires at least 1,024 artifacts and three horizons.\n");
    exit(1);
}
if (($manifest['selection']['strategy'] ?? '') !== 'all_eligible_release_files_locked_vendor_and_cause_specific_directory_topology'
    || (float) ($manifest['selection']['coverage_percent'] ?? 0) !== 100.0
    || (int) ($manifest['target_count'] ?? 0) !== count($artifacts) + count($directories) + count($directoryModeRepairs)
    || (int) ($manifest['repair_count'] ?? 0) !== count($artifacts) + count($directories) + count($directoryModeRepairs)) {
    fwrite(STDERR, "Time-forward library does not cover the complete eligible commercial release boundary.\n");
    exit(1);
}

$temporary = sys_get_temp_dir() . '/microsysadmin-artifact-time-forward-' . bin2hex(random_bytes(6));
$shadow = $temporary . '/application';
$runtime = $temporary . '/runtime';
foreach ([$shadow, $runtime, $runtime . '/backups', $runtime . '/locks'] as $directory) {
    if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create time-forward directory {$directory}.");
    }
}

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
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        $value[$key] = $replaceRoot($item);
    }
    return $value;
};

try {
    $shadowBaseline = $shadow . '/doctor/baselines/release-artifacts';
    mkdir($shadowBaseline . '/blobs', 0770, true);
    copy($manifestPath, $shadowBaseline . '/manifest.json');
    $baselineCopies = [];
    foreach ($artifacts as $artifact) {
        $relativeBaseline = (string) ($artifact['baseline'] ?? '');
        $source = APP_ROOT . '/' . $relativeBaseline;
        $destination = $shadow . '/' . $relativeBaseline;
        if (!isset($baselineCopies[$relativeBaseline])) {
            if (!copy($source, $destination)) {
                throw new RuntimeException("Unable to copy time-forward baseline {$relativeBaseline}.");
            }
            chmod($destination, 0444);
            $baselineCopies[$relativeBaseline] = true;
        }
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE doctor_audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, execution_id TEXT, level TEXT, event_type TEXT, message TEXT, context_json TEXT, created_at TEXT)');
    $repository = new DoctorRepository($pdo);
    $redactor = new SecretRedactor();
    $audit = new DoctorAuditLogger($repository, $redactor, 'doc_time_forward');
    $runner = new ProcessRunner($redactor, $audit, 65536, 5);
    $filesystem = new SafeFilesystem([$temporary]);
    $backups = new BackupManager($filesystem, $runtime . '/backups');
    $environment = new EnvironmentContext([
        'managed_release_integrity' => true,
        'commands' => [],
    ]);
    $catalog = new ManagedArtifactCatalog($shadow);
    $resolver = new TemplateResolver();
    $probes = new ProbeExecutor($resolver, $catalog);
    $policy = new PolicyEngine(require APP_ROOT . '/doctor/config/policies.php');
    $seenIds = [];
    $seenPaths = [];
    $scenarios = 0;

    // A file repair must own its manifest-authorized missing parent chain so
    // correctness never depends on directory-repair execution order.
    $deepArtifact = null;
    foreach ($artifacts as $candidate) {
        if (str_starts_with((string) ($candidate['path'] ?? ''), 'vendor/')
            && substr_count((string) $candidate['path'], '/') >= 3) {
            $deepArtifact = $candidate;
            break;
        }
    }
    if (is_array($deepArtifact)) {
        $definitionFiles = (array) ($deepArtifact['definition_files'] ?? []);
        $repairDefinition = $replaceRoot(json_decode((string) file_get_contents(
            APP_ROOT . '/doctor/modules/artifact-integrity/' . (string) ($definitionFiles[2] ?? '')
        ), true, 512, JSON_THROW_ON_ERROR));
        $repair = new DefinitionRepair($repairDefinition, $probes, $resolver, $catalog);
        $context = new DoctorContext('doc_artifact_parent_chain', $environment, $shadow, ['as_of' => $horizons[0], 'doctor_runtime_root' => $runtime], $runner, $filesystem, $backups, $audit, $redactor);
        $detection = $repair->detect($context);
        $plan = $repair->plan($context, $detection);
        $repair->backup($context, $plan);
        $result = $repair->repair($context, $plan);
        $createdDirectories = (array) (($result->get('changes')[0]['created_directories'] ?? []));
        $assert($result->successful() && count($createdDirectories) >= 2, 'A deep file repair creates its complete missing manifest-authorized parent chain.');
        $assert($repair->verify($context, $result)->passed(), 'The deep file repair passes independent verification after parent-chain creation.');
        $rollback = $repair->rollback($context, $result);
        $assert($rollback->successful() && !file_exists($shadow . '/' . (string) $deepArtifact['path']), 'The deep file repair removes its file and created parent chain on guarded rollback.');
    }

    foreach ($artifacts as $artifactIndex => $artifact) {
        $repairId = (string) ($artifact['repair_id'] ?? '');
        $relative = (string) ($artifact['path'] ?? '');
        $sha256 = (string) ($artifact['sha256'] ?? '');
        $mode = (int) ($artifact['mode'] ?? 0);
        $assert(!isset($seenIds[$repairId]) && preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId), "{$repairId} retains a unique permanent ID.");
        $assert(!isset($seenPaths[$relative]) && $relative !== '', "{$repairId} owns exactly one unique application artifact.");
        $seenIds[$repairId] = true;
        $seenPaths[$relative] = true;

        $definitionFiles = (array) ($artifact['definition_files'] ?? []);
        $checkFile = APP_ROOT . '/doctor/modules/artifact-integrity/' . (string) ($definitionFiles[0] ?? '');
        $diagnosisFile = APP_ROOT . '/doctor/modules/artifact-integrity/' . (string) ($definitionFiles[1] ?? '');
        $repairFile = APP_ROOT . '/doctor/modules/artifact-integrity/' . (string) ($definitionFiles[2] ?? '');
        $checkDefinition = $replaceRoot(json_decode((string) file_get_contents($checkFile), true, 512, JSON_THROW_ON_ERROR));
        $diagnosisDefinition = $replaceRoot(json_decode((string) file_get_contents($diagnosisFile), true, 512, JSON_THROW_ON_ERROR));
        $repairDefinition = $replaceRoot(json_decode((string) file_get_contents($repairFile), true, 512, JSON_THROW_ON_ERROR));
        $check = new DefinitionCheck($checkDefinition, $probes);
        $diagnosis = new DefinitionDiagnosis($diagnosisDefinition);
        $repair = new DefinitionRepair($repairDefinition, $probes, $resolver, $catalog);
        $repairMetadata = $repair->metadata();
        $assert($repairMetadata->automationClass()->value === 'AUTO_WITH_BACKUP'
            && $repairMetadata->get('required_commands') === []
            && $repairMetadata->get('required_packages') === []
            && $repairMetadata->get('network_effects') === [],
            "{$repairId} is backup-controlled, local-only, and dependency-free.");
        $target = $shadow . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0770, true) && !is_dir(dirname($target))) {
            throw new RuntimeException("Unable to create time-forward artifact parent for {$repairId}.");
        }
        $baseline = (string) file_get_contents($shadow . '/' . (string) $artifact['baseline']);

        foreach ($horizons as $horizonIndex => $horizon) {
            $scenario = $horizonIndex % 3;
            if (file_exists($target) || is_link($target)) {
                unlink($target);
            }
            if ($scenario === 0) {
                file_put_contents($target, "time-forward-content-drift:{$repairId}:{$horizon}\n");
                chmod($target, $mode);
            } elseif ($scenario === 1) {
                file_put_contents($target, $baseline);
                $driftMode = $mode === 0600 ? 0640 : 0600;
                chmod($target, $driftMode);
            }
            $context = new DoctorContext(
                'doc_time_forward',
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
            $assert($failedCheck->status === CheckStatus::FAIL, "{$repairId} detects {$horizon} drift.");
            $diagnosed = $diagnosis->diagnose($context, $failedCheck);
            $assert((bool) $diagnosed->get('condition_detected') && $diagnosed->repairIds() === [$repairId], "{$repairId} diagnoses only its own {$horizon} cause.");
            $detection = $repair->detect($context);
            $plan = $repair->plan($context, $detection);
            $assert($detection->detected() && count($plan->actions()) === 1, "{$repairId} produces one exact {$horizon} restoration plan.");
            $decision = $policy->evaluate($repairMetadata, $diagnosed, $plan, 'safe-with-backup');
            $assert($decision->allowed() && !$decision->requiresApproval(), "{$repairId} is zero-interaction under the local safe-with-backup policy at {$horizon}.");
            $backup = $repair->backup($context, $plan);
            $result = $repair->repair($context, $plan);
            $verification = $repair->verify($context, $result);
            $assert($backup->successful() && $result->successful(), "{$repairId} backs up and repairs locally at {$horizon}.");
            $assert($verification->passed() && hash_file('sha256', $target) === $sha256 && (fileperms($target) & 0777) === $mode, "{$repairId} independently verifies exact {$horizon} state.");
            $repeat = $repair->repair($context, $plan);
            $assert($repeat->successful() && hash_file('sha256', $target) === $sha256, "{$repairId} remains idempotent at {$horizon}.");
            $rollback = $repair->rollback($context, $result);
            $assert($rollback->successful(), "{$repairId} rolls back its {$horizon} repair.");
            $assert(!$repair->verify($context, $result)->passed(), "{$repairId} rollback restores the prior {$horizon} drift.");
            $scenarios++;
        }
    }

    $assert(count($seenIds) === count($artifacts), 'Time-forward suite covers every active independent permanent artifact repair ID.');
    $assert($scenarios === count($artifacts) * count($horizons), 'Every managed artifact is exercised at every future horizon.');
    $assert(count($baselineCopies) > 0 && count($baselineCopies) <= count($artifacts), 'Content-addressed local baselines are present and de-duplicated.');
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
    fwrite(STDERR, sprintf("Artifact time-forward tests: %d scenarios, %d assertions, %d failures.\n", $scenarios ?? 0, $assertions, count($failures)));
    exit(1);
}

echo sprintf(
    "Artifact time-forward tests: %d artifacts x %d horizons = %d scenarios, %d assertions, 0 failures.\n",
    count($artifacts),
    count($horizons),
    $scenarios,
    $assertions,
);
