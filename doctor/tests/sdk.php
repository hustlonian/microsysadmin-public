#!/usr/bin/env php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('STORAGE_PATH', APP_ROOT . '/storage');
require APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/classes/helpers.php';
$GLOBALS['sysadmin_config'] = [
    'environment' => [
        'privileged_helper_path' => APP_ROOT . '/scripts/service-control',
        'xampp_root' => '/opt/lampp',
        'storage_root' => STORAGE_PATH,
    ],
];

use SysAdmin\Doctor\engine\Backup\BackupManager;
use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
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
use SysAdmin\Doctor\sdk\Generation\RepairGenerator;
use SysAdmin\Doctor\sdk\Generation\ReleaseArtifactCatalog;
use SysAdmin\Doctor\sdk\Generation\LockedComposerVendorCatalog;
use SysAdmin\Doctor\sdk\Primitives\DoctorPrivilegedPrimitive;
use SysAdmin\Doctor\sdk\Quality\CommercialLibraryAuditor;
use SysAdmin\Doctor\sdk\Quality\ReportOnlyCandidateRanker;
use SysAdmin\Doctor\sdk\RepairPrimitiveRegistry;

$temporary = sys_get_temp_dir() . '/microsysadmin-doctor-sdk-' . bin2hex(random_bytes(6));
$tests = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$tests, &$failures): void {
    $tests++;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$replace = static function (mixed $value, array $replacements) use (&$replace): mixed {
    if (is_string($value)) {
        return strtr($value, $replacements);
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = $replace($item, $replacements);
        }
    }
    return $value;
};
$load = static function (string $module, string $folder, string $id): array {
    $path = glob(APP_ROOT . "/doctor/modules/{$module}/{$folder}/{$id}-*.json")[0] ?? '';
    if ($path === '') {
        throw new RuntimeException("Missing SDK contract definition {$id}.");
    }
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
};

mkdir($temporary, 0770, true);
mkdir($temporary . '/project', 0770, true);
mkdir($temporary . '/project/storage', 0770, true);
mkdir($temporary . '/project/scripts', 0770, true);
mkdir($temporary . '/runtime/backups', 0770, true);
mkdir($temporary . '/runtime/executions', 0770, true);

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE doctor_audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, execution_id TEXT, level TEXT, event_type TEXT, message TEXT, context_json TEXT, created_at TEXT)');
    $repository = new DoctorRepository($pdo);
    $redactor = new SecretRedactor();
    $audit = new DoctorAuditLogger($repository, $redactor, 'doc_sdk_contract');
    $runner = new ProcessRunner($redactor, $audit, 65536, 10);
    $filesystem = new SafeFilesystem([$temporary, sys_get_temp_dir()], [APP_ROOT]);
    $backups = new BackupManager($filesystem, $temporary . '/runtime/backups');
    $policy = new PolicyEngine(require APP_ROOT . '/doctor/config/policies.php');
    $sdk = new RepairPrimitiveRegistry();
    $descriptors = $sdk->descriptors();

    foreach (['artisan_command', 'atomic_write', 'chmod', 'composer_command', 'copy_file', 'create_directory', 'create_known_directory', 'create_symlink', 'deduplicate_crontab_entry', 'doctor_action', 'ensure_crontab_entry', 'ensure_crontab_line', 'quarantine_stale_git_lock', 'repair_symlink', 'restore_managed_artifact', 'restore_managed_directory', 'run_command', 'service_action', 'set_env_value', 'time_sync'] as $type) {
        $assert(isset($descriptors[$type]) && !empty($descriptors[$type]['category']) && array_key_exists('reversible', $descriptors[$type]), "SDK primitive {$type} has a discoverable safety contract.");
    }
    $assert(($descriptors['create_known_directory']['allowed_targets'] ?? []) === ['xampp-logs', 'xampp-ssl-key'], 'Privileged directory SDK exposes only the two reviewed fixed XAMPP targets.');

    $actionTypes = [];
    foreach (glob(APP_ROOT . '/doctor/modules/*/Repairs/*.json') ?: [] as $path) {
        $definition = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ((array) ($definition['actions'] ?? []) as $action) {
            $type = (string) ($action['type'] ?? '');
            $actionTypes[$type] = true;
            $assert($sdk->supports($type), "Live repair action {$type} resolves through the Repair SDK.");
        }
    }
    $assert($actionTypes !== [], 'Repair SDK coverage audit inspected live repair actions.');

    $commercialAudit = (new CommercialLibraryAuditor(APP_ROOT . '/doctor/modules', $sdk))->audit();
    $assert(($commercialAudit['defects']['duplicate_ids'] ?? []) === [], 'Commercial audit proves definition and inventory IDs are unique.');
    $assert(($commercialAudit['defects']['unreachable_repairs'] ?? []) === [], 'Commercial audit proves every repair is reachable from a diagnosis.');
    $assert(($commercialAudit['defects']['unknown_primitives'] ?? []) === [], 'Commercial audit proves every executable action resolves through the SDK.');
    $assert(($commercialAudit['defects']['unsafe_automation'] ?? []) === [], 'Commercial audit proves the executable portfolio contains no known unsafe automation.');
    $assert(($commercialAudit['defects']['duplicate_lifecycle_owners'] ?? []) === [], 'Commercial audit proves every executable lifecycle has one canonical cause owner.');
    $assert(($commercialAudit['defects']['orphan_test_cases'] ?? []) === [], 'Commercial audit proves every indexed contract case reaches an active repair.');
    $candidateRanking = (new ReportOnlyCandidateRanker(APP_ROOT . '/doctor/modules'))->rank();
    $assert(($candidateRanking['report_only_count'] ?? 0) === ($commercialAudit['counts']['report_only'] ?? -1), 'Report-only ranker covers every advisory repair in the commercial audit.');
    $assert((array) ($candidateRanking['overlap_groups'] ?? []) === [], 'Report-only ranker proves every active advisory probe has one canonical cause owner.');
    $rankedIds = array_column((array) ($candidateRanking['candidates'] ?? []), 'repair_id');
    $assert(count($rankedIds) === count(array_unique($rankedIds)), 'Report-only ranker emits each permanent advisory ID exactly once.');
    $retiredDuplicates = [
        'COMPOSER-0010' => 'SECURITY-0010',
        'CRON-0007' => 'LINUX-0009',
        'DNS-0006' => 'LINUX-0007',
        'DNS-0010' => 'DNS-0001',
        'FILESYSTEM-0003' => 'FILESYSTEM-0002',
        'FILESYSTEM-0006' => 'BACKUP-0003',
        'LINUX-0005' => 'CRON-0002',
        'LINUX-0006' => 'CRON-0003',
        'LINUX-0010' => 'FILESYSTEM-0010',
        'MAIL-0009' => 'LINUX-0009',
        'MAIL-0010' => 'DNS-0001',
        'MYSQL-0001' => 'XAMPP-0005',
        'PERFORMANCE-0009' => 'MYSQL-0003',
        'PERFORMANCE-0010' => 'REDIS-0003',
        'PHP-0001' => 'XAMPP-0003',
        'PHP-0010' => 'PHP-0009',
        'CRON-0010' => 'LARAVEL-0005',
        'XAMPP-0007' => 'MYSQL-0002',
    ];
    $retiredInventory = [];
    foreach (glob(APP_ROOT . '/doctor/modules/*/repair-inventory.json') ?: [] as $inventoryPath) {
        foreach (json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR) as $record) {
            $id = (string) ($record['repair_id'] ?? '');
            if (isset($retiredDuplicates[$id])) {
                $retiredInventory[$id] = $record;
            }
        }
    }
    foreach ($retiredDuplicates as $retiredId => $canonicalId) {
        $record = (array) ($retiredInventory[$retiredId] ?? []);
        $assert(($record['status'] ?? '') === 'RETIRED'
            && ($record['automation_class'] ?? '') === 'PROHIBITED'
            && ($record['replacement_repair_id'] ?? '') === $canonicalId
            && (glob(APP_ROOT . "/doctor/modules/*/Repairs/{$retiredId}-*.json") ?: []) === []
            && count(glob(APP_ROOT . "/doctor/modules/*/Repairs/{$canonicalId}-*.json") ?: []) === 1,
            "Retired duplicate {$retiredId} is non-executable and points to canonical owner {$canonicalId}.");
    }

    $releaseCatalog = (new ReleaseArtifactCatalog(APP_ROOT))->build(
        ['doctor/modules/artifact-integrity/', 'doctor/baselines/', 'vendor/'],
        ['config/config.php'],
    );
    $releaseCandidates = (array) $releaseCatalog['candidates'];
    $releaseProvenance = (array) $releaseCatalog['provenance'];
    $assert(($releaseProvenance['source'] ?? '') === 'commercial_release_packaging_policy'
        && ($releaseProvenance['eligible_artifacts'] ?? 0) === count($releaseCandidates),
        'Artifact catalog records its authoritative commercial release provenance.');
    foreach (['doctor/DoctorApplication.php', 'doctor/engine/Registry/DoctorRegistry.php', 'doctor/sdk/Generation/ReleaseArtifactCatalog.php'] as $runtimeArtifact) {
        $assert(isset($releaseCandidates[$runtimeArtifact]), "Release provenance includes Doctor runtime artifact {$runtimeArtifact}.");
    }
    foreach (['.github/workflows/ci.yml', 'config/config.php', 'doctor/reports/commercial-cycle-004.md', 'doctor/tests/run.php'] as $nonReleaseArtifact) {
        $assert(!isset($releaseCandidates[$nonReleaseArtifact]), "Release provenance excludes non-release or mutable path {$nonReleaseArtifact}.");
    }
    $assert(count($releaseCandidates) >= 1024, 'Artifact catalog retains at least the established commercial repair scale without a fixed cohort cap.');
    $artifactManifest = json_decode((string) file_get_contents(APP_ROOT . '/doctor/baselines/release-artifacts/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $missingDirectoryRepairs = (array) ($artifactManifest['directories'] ?? []);
    $modeDirectoryRepairs = (array) ($artifactManifest['directory_mode_repairs'] ?? []);
    $assert((int) ($artifactManifest['schema_version'] ?? 0) === 3
        && count($missingDirectoryRepairs) >= 200
        && count($missingDirectoryRepairs) === count($modeDirectoryRepairs)
        && (int) ($artifactManifest['repair_count'] ?? 0) === (int) ($artifactManifest['artifact_count'] ?? 0) + count($missingDirectoryRepairs) + count($modeDirectoryRepairs),
        'Release topology schema assigns paired cause-specific repairs to every safely reachable directory.');
    $missingByPath = [];
    foreach ($missingDirectoryRepairs as $entry) {
        $missingByPath[(string) ($entry['path'] ?? '')] = (string) ($entry['repair_id'] ?? '');
    }
    $modeByPath = [];
    foreach ($modeDirectoryRepairs as $entry) {
        $modeByPath[(string) ($entry['path'] ?? '')] = (string) ($entry['repair_id'] ?? '');
    }
    $missingPaths = array_keys($missingByPath);
    $modePaths = array_keys($modeByPath);
    sort($missingPaths, SORT_STRING);
    sort($modePaths, SORT_STRING);
    $assert($missingPaths === $modePaths
        && array_intersect(array_values($missingByPath), array_values($modeByPath)) === [],
        'Missing-directory and directory-mode contracts own the same topology with disjoint permanent IDs.');
    $lockedVendorCatalog = (new LockedComposerVendorCatalog(APP_ROOT))->build();
    $lockedVendorCandidates = (array) $lockedVendorCatalog['candidates'];
    $lockedVendorDirectories = (array) $lockedVendorCatalog['directories'];
    $lockedVendorProvenance = (array) $lockedVendorCatalog['provenance'];
    $composerLock = json_decode((string) file_get_contents(APP_ROOT . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $assert(count($lockedVendorCandidates) >= 200
        && ($lockedVendorProvenance['artifact_count'] ?? 0) === count($lockedVendorCandidates)
        && ($lockedVendorProvenance['directory_count'] ?? 0) === count($lockedVendorDirectories)
        && count((array) ($lockedVendorProvenance['packages'] ?? [])) === count((array) ($composerLock['packages'] ?? [])),
        'Locked Composer catalog is attested to every production package and the complete clean release artifact set.');
    $assert(isset($lockedVendorCandidates['vendor/autoload.php'], $lockedVendorCandidates['vendor/composer/installed.json']),
        'Locked Composer catalog includes generated autoload and installed-package evidence.');
    $assert(array_filter(array_keys($lockedVendorCandidates), static fn (string $path): bool => !str_starts_with($path, 'vendor/')) === [],
        'Locked Composer provenance cannot authorize a target outside vendor/.');
    $assert(count($lockedVendorDirectories) >= 20 && isset($lockedVendorDirectories['vendor'], $lockedVendorDirectories['vendor/composer'])
        && array_filter(array_keys($lockedVendorDirectories), static fn (string $path): bool => $path !== 'vendor' && !str_starts_with($path, 'vendor/')) === [],
        'Locked Composer provenance includes the exact bounded vendor directory topology.');
    $lockedRelease = $temporary . '/locked-vendor-release';
    mkdir($lockedRelease . '/doctor/baselines/release-artifacts/blobs', 0770, true);
    copy(APP_ROOT . '/composer.lock', $lockedRelease . '/composer.lock');
    copy(APP_ROOT . '/doctor/baselines/locked-composer-vendor.json', $lockedRelease . '/doctor/baselines/locked-composer-vendor.json');
    $orderedVendorDirectories = array_values($lockedVendorDirectories);
    usort($orderedVendorDirectories, static fn (array $left, array $right): int => substr_count($left['path'], '/') <=> substr_count($right['path'], '/'));
    foreach ($orderedVendorDirectories as $record) {
        $directoryTarget = $lockedRelease . '/' . (string) $record['path'];
        if (!is_dir($directoryTarget)) {
            mkdir($directoryTarget, (int) $record['mode']);
        }
        chmod($directoryTarget, (int) $record['mode']);
    }
    foreach ($lockedVendorCandidates as $relative => $record) {
        $vendorTarget = $lockedRelease . '/' . $relative;
        if (!is_dir(dirname($vendorTarget))) {
            mkdir(dirname($vendorTarget), 0770, true);
        }
        copy((string) $record['path'], $vendorTarget);
        chmod($vendorTarget, (int) $record['mode']);
        $blobTarget = $lockedRelease . '/doctor/baselines/release-artifacts/blobs/' . (string) $record['sha256'];
        if (!is_file($blobTarget)) {
            copy((string) $record['path'], $blobTarget);
        }
    }
    $lockedVerifier = new LockedComposerVendorCatalog($lockedRelease);
    $lockedVerification = $lockedVerifier->verifyReleaseRoot($lockedRelease);
    $assert($lockedVerification['verified'] === count($lockedVendorCandidates)
        && $lockedVerification['verified_directories'] === count($lockedVendorDirectories)
        && $lockedVerification['missing'] === [] && $lockedVerification['unexpected'] === [] && $lockedVerification['drifted'] === []
        && $lockedVerification['missing_directories'] === [] && $lockedVerification['unexpected_directories'] === [] && $lockedVerification['drifted_directories'] === [],
        'Locked Composer verifier accepts the exact complete clean vendor set.');
    file_put_contents($lockedRelease . '/vendor/autoload.php', "drift\n");
    $assert(in_array('vendor/autoload.php', $lockedVerifier->verifyReleaseRoot($lockedRelease)['drifted'], true),
        'Locked Composer verifier detects content drift.');
    copy((string) $lockedVendorCandidates['vendor/autoload.php']['path'], $lockedRelease . '/vendor/autoload.php');
    chmod($lockedRelease . '/vendor/autoload.php', (int) $lockedVendorCandidates['vendor/autoload.php']['mode']);
    file_put_contents($lockedRelease . '/vendor/unexpected.php', "<?php\n");
    $assert(in_array('vendor/unexpected.php', $lockedVerifier->verifyReleaseRoot($lockedRelease)['unexpected'], true),
        'Locked Composer verifier rejects an unmanifested vendor artifact.');
    unlink($lockedRelease . '/vendor/unexpected.php');
    unlink($lockedRelease . '/vendor/autoload.php');
    $assert(in_array('vendor/autoload.php', $lockedVerifier->verifyReleaseRoot($lockedRelease)['missing'], true),
        'Locked Composer verifier detects a missing vendor artifact.');
    copy((string) $lockedVendorCandidates['vendor/autoload.php']['path'], $lockedRelease . '/vendor/autoload.php');
    chmod($lockedRelease . '/vendor/autoload.php', (int) $lockedVendorCandidates['vendor/autoload.php']['mode']);
    $directoryMode = (int) $lockedVendorDirectories['vendor/composer']['mode'];
    chmod($lockedRelease . '/vendor/composer', $directoryMode === 0700 ? 0755 : 0700);
    $assert(in_array('vendor/composer', $lockedVerifier->verifyReleaseRoot($lockedRelease)['drifted_directories'], true),
        'Locked Composer verifier detects directory-mode drift.');
    chmod($lockedRelease . '/vendor/composer', $directoryMode);
    mkdir($lockedRelease . '/vendor/unexpected-directory', 0755);
    $assert(in_array('vendor/unexpected-directory', $lockedVerifier->verifyReleaseRoot($lockedRelease)['unexpected_directories'], true),
        'Locked Composer verifier rejects an unmanifested vendor directory.');
    rmdir($lockedRelease . '/vendor/unexpected-directory');
    usort($orderedVendorDirectories, static fn (array $left, array $right): int => substr_count($right['path'], '/') <=> substr_count($left['path'], '/'));
    $missingDirectory = (string) $orderedVendorDirectories[0]['path'];
    rename($lockedRelease . '/' . $missingDirectory, $lockedRelease . '/held-vendor-directory');
    $assert(in_array($missingDirectory, $lockedVerifier->verifyReleaseRoot($lockedRelease)['missing_directories'], true),
        'Locked Composer verifier detects a missing vendor directory.');
    rename($lockedRelease . '/held-vendor-directory', $lockedRelease . '/' . $missingDirectory);

    unlink($lockedRelease . '/vendor/autoload.php');
    chmod($lockedRelease . '/vendor/composer', $directoryMode === 0700 ? 0755 : 0700);
    $materialized = $lockedVerifier->materializeReleaseRoot($lockedRelease);
    $materializedVerification = $lockedVerifier->verifyReleaseRoot($lockedRelease);
    $assert($materialized['artifacts'] === count($lockedVendorCandidates)
        && $materialized['directories'] === count($lockedVendorDirectories)
        && $materializedVerification['verified'] === count($lockedVendorCandidates)
        && $materializedVerification['verified_directories'] === count($lockedVendorDirectories)
        && $materializedVerification['missing'] === [] && $materializedVerification['unexpected'] === []
        && $materializedVerification['drifted'] === [] && $materializedVerification['missing_directories'] === []
        && $materializedVerification['unexpected_directories'] === [] && $materializedVerification['drifted_directories'] === [],
        'Locked Composer materializer reconstructs missing artifacts and exact directory modes entirely from immutable local blobs.');
    file_put_contents($lockedRelease . '/vendor/unmanifested-customer-file', "preserve\n");
    $lockedVerifier->materializeReleaseRoot($lockedRelease);
    $assert(in_array('vendor/unmanifested-customer-file', $lockedVerifier->verifyReleaseRoot($lockedRelease)['unexpected'], true),
        'Locked Composer materialization never deletes an unmanifested file outside its exact ownership set.');
    unlink($lockedRelease . '/vendor/unmanifested-customer-file');

    // Generator contract: one diagnosis blueprint produces the complete package.
    $sourceModule = $temporary . '/source/sdkfixture';
    mkdir($sourceModule . '/Checks', 0770, true);
    mkdir($sourceModule . '/Diagnoses', 0770, true);
    $checkDefinition = [
        'metadata' => ['check_id' => 'SDKTEST-C9001', 'module' => 'sdkfixture', 'name' => 'Restore generated cache directory', 'summary' => 'Restore generated cache directory.', 'severity' => 'HIGH', 'version' => '1.0.0'],
        'supports' => [], 'requires_commands' => [],
        'probe' => ['type' => 'directory_exists', 'path' => '{PROJECT}/generated/cache/data'],
        'failure_status' => 'FAIL',
    ];
    $diagnosisDefinition = [
        'metadata' => ['diagnosis_id' => 'SDKTEST-D9001', 'check_id' => 'SDKTEST-C9001', 'module' => 'sdkfixture', 'name' => 'Generated cache diagnosis', 'version' => '1.0.0'],
        'diagnosis' => ['condition' => 'Generated cache directory is missing.', 'expected_state' => 'Directory exists.', 'probable_cause' => 'Runtime bootstrap did not create the directory.', 'confidence' => 0.99, 'related_repair_ids' => ['SDKTEST-9001'], 'automation_allowed' => true, 'automation_reason' => 'Exact local directory creation is reversible.'],
        'repair_generation' => [
            'repair_id' => 'SDKTEST-9001', 'name' => 'Restore generated cache directory',
            'automation_class' => 'AUTO_SAFE', 'risk_level' => 'LOW',
            'action' => ['type' => 'create_directory', 'path' => '{PROJECT}/generated/cache/data', 'mode' => 504],
            'directories_modified' => ['{PROJECT}/generated/cache/data'],
            'commercial_tier' => 'essential',
        ],
    ];
    file_put_contents($sourceModule . '/Checks/SDKTEST-9001.json', json_encode($checkDefinition, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $diagnosisPath = $sourceModule . '/Diagnoses/SDKTEST-9001.json';
    file_put_contents($diagnosisPath, json_encode($diagnosisDefinition, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $generated = (new RepairGenerator($sdk))->generate($diagnosisPath, $temporary . '/generated');
    foreach ((array) $generated['outputs'] as $label => $path) {
        $assert(is_file((string) $path), "Repair Generator emits {$label} output.");
    }
    $assert(is_file($temporary . '/generated/sdkfixture/repair-inventory.json'), 'Repair Generator adds the permanent inventory record.');
    try {
        (new RepairGenerator($sdk))->generate($diagnosisPath, $temporary . '/generated');
        $assert(false, 'Repair Generator refuses implicit overwrite.');
    } catch (Throwable) {
        $assert(true, 'Repair Generator refuses implicit overwrite.');
    }

    $assertGeneratorRejects = static function (
        array $sourceDiagnosis,
        string $diagnosisId,
        string $repairId,
        array $action,
        string $message,
    ) use ($sourceModule, $temporary, $assert): void {
        $sourceDiagnosis['metadata']['diagnosis_id'] = $diagnosisId;
        $sourceDiagnosis['diagnosis']['related_repair_ids'] = [$repairId];
        $sourceDiagnosis['repair_generation']['repair_id'] = $repairId;
        $sourceDiagnosis['repair_generation']['action'] = $action;
        $path = $sourceModule . '/Diagnoses/' . $diagnosisId . '.json';
        file_put_contents($path, json_encode($sourceDiagnosis, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        try {
            (new RepairGenerator())->generate($path, $temporary . '/generated');
            $assert(false, $message);
        } catch (InvalidArgumentException) {
            $assert(true, $message);
        }
    };
    $assertGeneratorRejects(
        $diagnosisDefinition,
        'SDKTEST-D9002',
        'SDKTEST-9002',
        ['type' => 'service_action', 'service' => 'apache2', 'action' => 'restart'],
        'Repair Generator rejects temporal service restart actions without exact rollback.',
    );
    $assertGeneratorRejects(
        $diagnosisDefinition,
        'SDKTEST-D9003',
        'SDKTEST-9003',
        ['type' => 'composer_command', 'command' => 'update'],
        'Repair Generator rejects Composer commands outside the local deterministic allowlist.',
    );

    $generatedRepairDefinition = json_decode((string) file_get_contents($generated['outputs']['repair']), true, 512, JSON_THROW_ON_ERROR);
    $generatedCheck = new DefinitionCheck($checkDefinition);
    $generatedDiagnosis = new DefinitionDiagnosis($diagnosisDefinition);
    $generatedRepair = new DefinitionRepair($generatedRepairDefinition);
    $environment = new EnvironmentContext(['commands' => [], 'laravel_detected' => true]);
    $context = new DoctorContext('doc_sdk_contract', $environment, $temporary . '/project', ['doctor_runtime_root' => $temporary . '/runtime'], $runner, $filesystem, $backups, $audit, $redactor);
    $failure = $generatedCheck->run($context);
    $diagnosed = $generatedDiagnosis->diagnose($context, $failure);
    $detection = $generatedRepair->detect($context);
    $plan = $generatedRepair->plan($context, $detection);
    $decision = $policy->evaluate($generatedRepair->metadata(), $diagnosed, $plan, 'safe');
    $backup = $generatedRepair->backup($context, $plan);
    $result = $generatedRepair->repair($context, $plan);
    $verification = $generatedRepair->verify($context, $result);
    $rollback = $generatedRepair->rollback($context, $result);
    $assert($failure->status === CheckStatus::FAIL && $detection->detected(), 'Generated contract detects the isolated failure.');
    $assert($decision->allowed() && !$decision->requiresApproval(), 'Generated AUTO_SAFE contract is zero-interaction under safe policy.');
    $assert($backup->successful() && $result->successful() && $verification->passed(), 'Generated contract backs up, repairs, and verifies.');
    $assert($rollback->successful() && !$generatedRepair->verify($context, $result)->passed(), 'Generated contract rolls back and verification detects the restored failure.');
    $assert(!file_exists($temporary . '/project/generated'), 'Directory-chain rollback removes every helper-created ancestor in reverse order.');

    $guardedChain = $sdk->execute([
        'type' => 'create_directory',
        'path' => $temporary . '/project/guarded-chain/cache/data',
        'mode' => 0770,
    ], $context);
    chmod($temporary . '/project/guarded-chain/cache/data', 0700);
    try {
        $sdk->rollback($guardedChain, $context);
        $assert(false, 'Directory-chain rollback refuses post-repair mode drift.');
    } catch (RuntimeException) {
        $assert(true, 'Directory-chain rollback refuses post-repair mode drift.');
    }
    chmod($temporary . '/project/guarded-chain/cache/data', 0770);
    $assert($sdk->rollback($guardedChain, $context)
        && !file_exists($temporary . '/project/guarded-chain'),
        'Directory-chain rollback succeeds after exact identity and mode are restored.');

    // Permanent conversion cohort lifecycle contracts.
    $runLifecycle = static function (
        string $module,
        string $id,
        DoctorContext $contractContext,
        array $replacements = [],
    ) use ($load, $replace, $assert, $policy): void {
        $checkData = $replace($load($module, 'Checks', $id), $replacements);
        $diagnosisData = $replace($load($module, 'Diagnoses', $id), $replacements);
        $repairData = $replace($load($module, 'Repairs', $id), $replacements);
        $check = new DefinitionCheck($checkData);
        $diagnosis = new DefinitionDiagnosis($diagnosisData);
        $repair = new DefinitionRepair($repairData);
        $failure = $check->run($contractContext);
        $diagnosed = $diagnosis->diagnose($contractContext, $failure);
        $detection = $repair->detect($contractContext);
        $plan = $repair->plan($contractContext, $detection);
        $decision = $policy->evaluate($repair->metadata(), $diagnosed, $plan, 'safe-with-backup');
        $backup = $repair->backup($contractContext, $plan);
        $result = $repair->repair($contractContext, $plan);
        $verification = $repair->verify($contractContext, $result);
        $rollback = $repair->rollback($contractContext, $result);
        $problemStatus = in_array($failure->status, [CheckStatus::FAIL, CheckStatus::WARNING], true);
        $assert($problemStatus && $diagnosed->repairIds() === [$id] && $detection->detected(), "{$id} detects and diagnoses its isolated cause.");
        $assert($decision->allowed(), "{$id} produces a policy-eligible exact plan.");
        $assert($backup->successful() && $result->successful() && $verification->passed(), "{$id} repairs and independently verifies.");
        $assert($rollback->successful() && !$repair->verify($contractContext, $result)->passed(), "{$id} automatically rolls back to the original failure.");
    };

    $storageRoot = $temporary . '/project/storage';
    $runLifecycle('security', 'SECURITY-0006', $context, ['{STORAGE_PATH}' => $storageRoot]);

    file_put_contents($temporary . '/project/.env.example', "APP_ENV=local\nAPP_KEY=\n");
    file_put_contents($temporary . '/project/artisan', <<<'PHP'
#!/usr/bin/env php
<?php
$cache = __DIR__ . '/bootstrap/cache/config.php';
if (($argv[1] ?? '') === 'config:clear') {
    if (is_file($cache)) { unlink($cache); }
    exit(0);
}
if (($argv[1] ?? '') === 'config:cache') {
    if (!is_dir(dirname($cache))) { mkdir(dirname($cache), 0770, true); }
    file_put_contents($cache, "<?php return ['cached' => true];\n");
    exit(0);
}
exit(64);
PHP);
    file_put_contents($temporary . '/project/composer.json', "{}\n");
    $runLifecycle('laravel', 'LARAVEL-0001', $context);

    mkdir($temporary . '/project/bootstrap/cache', 0770, true);
    $configCache = $temporary . '/project/bootstrap/cache/config.php';
    file_put_contents($configCache, "<?php return ['stale' => true];\n");
    $artisanContext = new DoctorContext(
        'doc_sdk_contract',
        new EnvironmentContext([
            'commands' => [
                'php' => PHP_BINARY,
                'xampp-php' => PHP_BINARY,
            ],
            'laravel_detected' => true,
        ]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    $runLifecycle('laravel', 'LARAVEL-0010', $artisanContext);
    unlink($configCache);
    $runLifecycle('performance', 'PERFORMANCE-0008', $artisanContext);

    // Composer SDK: local-only locked install/dump, exact tree snapshots, and
    // manifest-guarded removal/restoration for all four migrated definitions.
    $composerProject = $temporary . '/composer-project';
    mkdir($composerProject, 0770, true);
    file_put_contents($composerProject . '/composer.json', "{}\n");
    file_put_contents($composerProject . '/composer.lock', "{\"packages\":[]}\n");
    $composerStub = $temporary . '/composer';
    file_put_contents($composerStub, <<<'SH'
#!/bin/sh
set -eu
[ "${COMPOSER_DISABLE_NETWORK:-}" = "1" ]
[ "${COMPOSER_NO_INTERACTION:-}" = "1" ]
case "${1:-}" in
    install)
        mkdir -p vendor/composer
        printf '%s\n' '<?php return true;' > vendor/autoload.php
        printf '%s\n' '<?php return [];' > vendor/composer/autoload_classmap.php
        ;;
    dump-autoload)
        mkdir -p vendor/composer
        printf '%s\n' '<?php return true;' > vendor/autoload.php
        printf '%s\n' '<?php return [];' > vendor/composer/autoload_classmap.php
        ;;
    *) exit 64 ;;
esac
SH);
    chmod($composerStub, 0750);
    $composerContext = new DoctorContext(
        'doc_sdk_composer',
        new EnvironmentContext(['commands' => ['composer' => $composerStub], 'laravel_detected' => true]),
        $composerProject,
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    $runLifecycle('composer', 'COMPOSER-0005', $composerContext);
    $runLifecycle('laravel', 'LARAVEL-0003', $composerContext);
    mkdir($composerProject . '/vendor/composer', 0770, true);
    file_put_contents($composerProject . '/vendor/package.php', "<?php return 'original';\n");
    $runLifecycle('composer', 'COMPOSER-0006', $composerContext);
    $runLifecycle('performance', 'PERFORMANCE-0007', $composerContext);

    // Time-sync SDK: prior state capture, scoped helper mutation, independent
    // verification, and exact restoration of the disabled state.
    $timeState = $temporary . '/time-sync-state';
    file_put_contents($timeState, "no\n");
    $timeCapability = $temporary . '/time-sync-capability';
    file_put_contents($timeCapability, "yes\n");
    $timedatectlStub = $temporary . '/timedatectl';
    file_put_contents($timedatectlStub, "#!/bin/sh\ncase \"\$*\" in *CanNTP*) cat " . escapeshellarg($timeCapability) . ";; *NTP*) cat " . escapeshellarg($timeState) . ";; *) exit 64;; esac\n");
    chmod($timedatectlStub, 0750);
    $sudoStub = $temporary . '/sudo';
    file_put_contents($sudoStub, "#!/bin/sh\nset -eu\n[ \"\${1:-}\" = -n ] && shift\nshift\n[ \"\${1:-}\" = doctor ]\n[ \"\${2:-}\" = set-ntp ]\ncase \"\${3:-}\" in true) printf 'yes\\n' > " . escapeshellarg($timeState) . ";; false) printf 'no\\n' > " . escapeshellarg($timeState) . ";; *) exit 64;; esac\nprintf 'NTP=%s\\n' \"\$(cat " . escapeshellarg($timeState) . ")\"\n");
    chmod($sudoStub, 0750);
    $timeContext = new DoctorContext(
        'doc_sdk_time_sync',
        new EnvironmentContext(['commands' => ['timedatectl' => $timedatectlStub, 'sudo' => $sudoStub]]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    file_put_contents($timeCapability, "no\n");
    $unsupportedTimeCheck = new DefinitionCheck($load('linux', 'Checks', 'LINUX-0008'));
    $assert($unsupportedTimeCheck->run($timeContext)->status === CheckStatus::SKIPPED, 'Time-sync contract is not applicable when the operating system reports CanNTP=no.');
    file_put_contents($timeCapability, "yes\n");
    $runLifecycle('linux', 'LINUX-0008', $timeContext);

    // Production dotenv SDK: semantic one-key mutation, no secret-bearing
    // evidence, exact file restoration, and non-production exclusion.
    $dotenvPath = $temporary . '/project/.env';
    $dotenvSecret = 'cycle003-secret-must-never-enter-audit';
    $dotenvOriginal = "APP_ENV=production\nAPP_DEBUG=true # reviewed transition\nAPP_KEY={$dotenvSecret}\n";
    file_put_contents($dotenvPath, $dotenvOriginal);
    $dotenvMtime = time() - 120;
    touch($dotenvPath, $dotenvMtime);
    $runLifecycle('security', 'SECURITY-0007', $context);
    $assert(file_get_contents($dotenvPath) === $dotenvOriginal && filemtime($dotenvPath) === $dotenvMtime, 'SECURITY-0007 restores the byte-exact dotenv document and original modification time.');
    $auditPayloads = implode("\n", array_map('strval', $pdo->query('SELECT context_json FROM doctor_audit_events')->fetchAll(PDO::FETCH_COLUMN)));
    $assert(!str_contains($auditPayloads, $dotenvSecret), 'SECURITY-0007 never emits unrelated dotenv secrets into audit evidence.');
    file_put_contents($dotenvPath, "APP_ENV=local\nAPP_DEBUG=true\n");
    $nonProductionCheck = new DefinitionCheck($load('security', 'Checks', 'SECURITY-0007'));
    $assert($nonProductionCheck->run($context)->status === CheckStatus::SKIPPED, 'SECURITY-0007 is not applicable outside an exact production environment.');

    // Git stale-lock SDK: age, ownership, process ownership, identity recheck,
    // exact quarantine, and absent-path guarded restoration.
    mkdir($temporary . '/project/.git', 0770, true);
    $lockPath = $temporary . '/project/.git/index.lock';
    $lockOriginal = "disposable-index-lock\n";
    file_put_contents($lockPath, $lockOriginal);
    $lockMtime = time() - 7200;
    touch($lockPath, $lockMtime);
    $lsofStub = $temporary . '/lsof';
    file_put_contents($lsofStub, "#!/bin/sh\n[ \"\${1:-}\" = -t ]\n[ \"\${2:-}\" = -- ]\nexit 1\n");
    chmod($lsofStub, 0750);
    $gitContext = new DoctorContext(
        'doc_sdk_stale_lock',
        new EnvironmentContext(['commands' => ['lsof' => $lsofStub]]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    $runLifecycle('git', 'GIT-0007', $gitContext);
    $assert(file_get_contents($lockPath) === $lockOriginal && filemtime($lockPath) === $lockMtime, 'GIT-0007 restores the byte-exact lock and original modification time.');

    $privilegedStorage = $temporary . '/privileged-storage';
    $privilegedXampp = $temporary . '/privileged-xampp';
    mkdir($privilegedStorage . '/doctor/backups/privileged', 0770, true);
    mkdir($privilegedXampp . '/etc', 0770, true);
    $GLOBALS['sysadmin_config']['environment']['storage_root'] = $privilegedStorage;
    $GLOBALS['sysadmin_config']['environment']['xampp_root'] = $privilegedXampp;
    $directorySudo = $temporary . '/sudo-known-directory';
    file_put_contents($directorySudo, "#!/bin/sh\nset -eu\n[ \"\${1:-}\" = -n ] && shift\nexec \"\$@\"\n");
    chmod($directorySudo, 0750);
    $directoryContext = new DoctorContext(
        'doc_sdk_known_directory',
        new EnvironmentContext(['commands' => ['sudo' => $directorySudo], 'primary_web_server' => 'xampp']),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    $runLifecycle('xampp', 'XAMPP-0010', $directoryContext, ['/opt/lampp/logs' => $privilegedXampp . '/logs']);
    $runLifecycle('security', 'SECURITY-0003', $directoryContext, ['/opt/lampp/etc/ssl.key' => $privilegedXampp . '/etc/ssl.key']);

    $privilegedBackup = $privilegedStorage . '/doctor/backups/privileged/php.ini-20260802T120000Z-123.bak';
    file_put_contents($privilegedBackup, "pre-repair\n");
    $privilegedHash = hash_file('sha256', $privilegedBackup);
    $privilegedLog = $temporary . '/privileged-operations';
    $privilegedSudo = $temporary . '/sudo-privileged';
    file_put_contents($privilegedSudo, "#!/bin/sh\nset -eu\n[ \"\${1:-}\" = -n ] && shift\nshift\n[ \"\${1:-}\" = doctor ]\noperation=\"\${2:-}\"\nprintf '%s\\n' \"\$operation\" >> " . escapeshellarg($privilegedLog) . "\ncase \"\$operation\" in enable-opcache) printf '%s\\n' " . escapeshellarg('DOCTOR_BACKUP_PATH=' . $privilegedBackup) . ' ' . escapeshellarg('DOCTOR_BACKUP_SHA256=' . $privilegedHash) . ";; restore-opcache) [ \"\${3:-}\" = " . escapeshellarg($privilegedBackup) . " ] && [ \"\${4:-}\" = " . escapeshellarg($privilegedHash) . " ];; *) exit 64;; esac\n");
    chmod($privilegedSudo, 0750);
    $privilegedContext = new DoctorContext(
        'doc_sdk_privileged',
        new EnvironmentContext(['commands' => ['sudo' => $privilegedSudo]]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    $privileged = new DoctorPrivilegedPrimitive();
    $privilegedChange = $privileged->execute([
        'type' => 'doctor_action',
        'action' => 'enable-opcache',
        'path' => $privilegedXampp . '/etc/php.ini',
    ], $privilegedContext);
    $assert(($privilegedChange['backup_sha256'] ?? null) === $privilegedHash, 'Privileged SDK validates and records the helper rollback reference.');
    $assert($privileged->rollback($privilegedChange, $privilegedContext), 'Privileged SDK invokes the exact matching helper rollback operation.');
    $operations = file($privilegedLog, FILE_IGNORE_NEW_LINES) ?: [];
    $assert($operations === ['enable-opcache', 'restore-opcache'], 'Privileged SDK executes only the paired repair and rollback operations.');
    try {
        $privileged->execute(['type' => 'doctor_action', 'action' => 'enable-opcache', 'path' => $temporary . '/wrong.ini'], $privilegedContext);
        $assert(false, 'Privileged SDK refuses a mismatched audited target path.');
    } catch (Throwable) {
        $assert(true, 'Privileged SDK refuses a mismatched audited target path.');
    }

    // SSL-0005 full lifecycle against a disposable two-day local certificate
    // and the real scoped helper, with byte-exact rollback verification.
    mkdir($privilegedXampp . '/bin', 0770, true);
    mkdir($privilegedXampp . '/etc/ssl.crt', 0770, true);
    mkdir($privilegedXampp . '/etc/ssl.key', 0770, true);
    file_put_contents($privilegedXampp . '/lampp', "#!/bin/sh\nexit 0\n");
    chmod($privilegedXampp . '/lampp', 0750);
    $certificateBuild = $runner->run([
        '/usr/bin/openssl', 'req', '-new', '-newkey', 'rsa:2048', '-nodes', '-x509', '-sha256', '-days', '2',
        '-keyout', $privilegedXampp . '/etc/ssl.key/server.key',
        '-out', $privilegedXampp . '/etc/ssl.crt/server.crt',
        '-subj', '/CN=pre-repair.local',
    ], 30);
    $assert($certificateBuild->successful(), 'Disposable near-expiry TLS fixture is generated locally.');
    $passthroughSudo = $temporary . '/sudo-passthrough';
    file_put_contents($passthroughSudo, "#!/bin/sh\nset -eu\n[ \"\${1:-}\" = -n ] && shift\nexec \"\$@\"\n");
    chmod($passthroughSudo, 0750);
    $tlsContext = new DoctorContext(
        'doc_sdk_tls',
        new EnvironmentContext(['commands' => ['openssl' => '/usr/bin/openssl', 'sudo' => $passthroughSudo]]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    $runLifecycle('ssl', 'SSL-0005', $tlsContext, ['/opt/lampp' => $privilegedXampp]);

    // FILESYSTEM-0009 owns only an existing wrong in-project link; its
    // lifecycle restores that exact wrong target during rollback.
    if (!is_dir($temporary . '/project/public')) {
        mkdir($temporary . '/project/public', 0770, true);
    }
    if (!is_dir($temporary . '/project/storage/app/public')) {
        mkdir($temporary . '/project/storage/app/public', 0770, true);
    }
    mkdir($temporary . '/project/storage/wrong-public', 0770, true);
    symlink($temporary . '/project/storage/wrong-public', $temporary . '/project/public/storage');
    $runLifecycle('filesystem', 'FILESYSTEM-0009', $context);

    $crontabState = $temporary . '/daemon-crontab';
    $crontabStub = $temporary . '/crontab';
    file_put_contents($crontabStub, "#!/bin/sh\nstate=" . escapeshellarg($crontabState) . <<<'SH'

case "${1:-}" in
    -l) [ -f "$state" ] && { cat "$state"; exit 0; }; echo "no crontab for daemon" >&2; exit 1 ;;
    -r) [ ! -f "$state" ] || rm -f -- "$state"; exit 0 ;;
    *) cp -- "$1" "$state" ;;
esac
SH);
    chmod($crontabStub, 0750);
    $cronContext = new DoctorContext(
        'doc_sdk_contract',
        new EnvironmentContext(['commands' => ['crontab' => $crontabStub, 'php' => PHP_BINARY, 'xampp-php' => PHP_BINARY], 'laravel_detected' => true]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner, $filesystem, $backups, $audit, $redactor,
    );
    $runLifecycle('cron', 'CRON-0004', $cronContext, ['/opt/lampp/bin/php' => PHP_BINARY]);
    file_put_contents($crontabState, implode("\n", [
        '* * * * * /first # MicroSysAdmin Doctor CRON-0004',
        '5 * * * * /unrelated # customer entry',
        '* * * * * /second # MicroSysAdmin Doctor CRON-0004',
        '',
    ]));
    $runLifecycle('cron', 'CRON-0005', $cronContext);

    $events = $repository->auditEvents('doc_sdk_contract', 10000);
    $eventTypes = array_column($events, 'event_type');
    $assert(in_array('doctor.primitive.executed', $eventTypes, true), 'Repair SDK records primitive execution audit events.');
    $assert(in_array('doctor.primitive.rolled_back', $eventTypes, true), 'Repair SDK records primitive rollback audit events.');
} finally {
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
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
    fwrite(STDERR, sprintf("Doctor SDK tests: %d assertions, %d failures.\n", $tests, count($failures)));
    exit(1);
}
echo sprintf("Doctor SDK tests: %d assertions, 0 failures.\n", $tests);
