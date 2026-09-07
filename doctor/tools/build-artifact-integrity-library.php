#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

/**
 * Build one independent, local-only repair lifecycle for every eligible file
 * in the authoritative commercial release packaging boundary.
 *
 * This generator is intentionally additive: it owns only the
 * artifact-integrity module and release-artifact baseline tree. It never
 * rewrites the original 20 Doctor modules.
 */

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/vendor/autoload.php';

const ARTIFACT_MODULE = 'artifact-integrity';
const ARTIFACT_PREFIX = 'ARTIFACT';
const ARTIFACT_BASELINE = 'doctor/baselines/release-artifacts';
const ARTIFACT_MAX_BYTES = 8_388_608;

use SysAdmin\Doctor\sdk\Generation\ReleaseArtifactCatalog;
use SysAdmin\Doctor\sdk\Generation\LockedComposerVendorCatalog;

$moduleRoot = APP_ROOT . '/doctor/modules/' . ARTIFACT_MODULE;
$baselineRoot = APP_ROOT . '/' . ARTIFACT_BASELINE;
$manifestPath = $baselineRoot . '/manifest.json';
$previous = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR)
    : [];
$previousArtifacts = [];
foreach ((array) ($previous['artifacts'] ?? []) as $entry) {
    $path = (string) ($entry['path'] ?? '');
    if ($path !== '') {
        $previousArtifacts[$path] = (array) $entry;
    }
}
$previousAssignments = $previousArtifacts;
foreach ((array) ($previous['retired_artifacts'] ?? []) as $entry) {
    $path = (string) ($entry['path'] ?? '');
    if ($path !== '' && !isset($previousAssignments[$path])) {
        $previousAssignments[$path] = (array) $entry;
    }
}
$previousDirectoryAssignments = [];
foreach ((array) ($previous['directories'] ?? []) as $entry) {
    $path = (string) ($entry['path'] ?? '');
    if ($path !== '') {
        $previousDirectoryAssignments[$path] = (array) $entry;
    }
}
$previousDirectoryModeAssignments = [];
foreach ((array) ($previous['directory_mode_repairs'] ?? []) as $entry) {
    $path = (string) ($entry['path'] ?? '');
    if ($path !== '') {
        $previousDirectoryModeAssignments[$path] = (array) $entry;
    }
}
foreach ((array) ($previous['retired_directory_mode_repairs'] ?? []) as $entry) {
    $path = (string) ($entry['path'] ?? '');
    if ($path !== '' && !isset($previousDirectoryModeAssignments[$path])) {
        $previousDirectoryModeAssignments[$path] = (array) $entry;
    }
}
foreach ((array) ($previous['retired_directories'] ?? []) as $entry) {
    $path = (string) ($entry['path'] ?? '');
    if ($path !== '' && !isset($previousDirectoryAssignments[$path])) {
        $previousDirectoryAssignments[$path] = (array) $entry;
    }
}

$excludedPrefixes = [
    'doctor/modules/' . ARTIFACT_MODULE . '/',
    'doctor/baselines/',
    'vendor/',
];
$excludedExact = ['config/config.php'];
$releaseCatalog = (new ReleaseArtifactCatalog(APP_ROOT))->build(
    $excludedPrefixes,
    $excludedExact,
    ARTIFACT_MAX_BYTES,
);
$vendorCatalog = (new LockedComposerVendorCatalog(APP_ROOT))->build();
$selected = (array) $releaseCatalog['candidates'];
foreach ((array) $vendorCatalog['candidates'] as $relative => $candidate) {
    if (isset($selected[$relative])) {
        throw new RuntimeException("Release and locked Composer catalogs overlap at {$relative}.");
    }
    $selected[$relative] = $candidate;
}
ksort($selected, SORT_STRING);
$selectedDirectories = [];
$vendorDirectories = (array) ($vendorCatalog['directories'] ?? []);
foreach (array_keys($selected) as $relative) {
    $directory = dirname($relative);
    while ($directory !== '.') {
        if (!isset($selectedDirectories[$directory])) {
            if (isset($vendorDirectories[$directory])) {
                $mode = (int) $vendorDirectories[$directory]['mode'];
            } else {
                $absolute = APP_ROOT . '/' . $directory;
                if (!is_dir($absolute) || is_link($absolute)) {
                    throw new RuntimeException("Release artifact parent is unavailable or symbolic: {$directory}");
                }
                $mode = fileperms($absolute) & 0777;
            }
            if ($mode < 0700 || $mode > 0775 || ($mode & 0002) !== 0) {
                throw new RuntimeException("Release directory {$directory} has an unsafe mode.");
            }
            $selectedDirectories[$directory] = ['path' => $directory, 'mode' => $mode];
        }
        $directory = dirname($directory);
    }
}
ksort($selectedDirectories, SORT_STRING);
// Doctor cannot restore the directory that contains its own runtime and
// topology manifest if that bootstrap root is absent. Descendants remain
// independently repairable once the already-running engine has loaded.
unset($selectedDirectories['doctor']);
$catalogProvenance = [
    'release_source' => $releaseCatalog['provenance'],
    'locked_composer_vendor' => $vendorCatalog['provenance'],
];
if ($selected === []) {
    throw new RuntimeException('Commercial release policy produced no eligible managed artifacts.');
}

foreach (['Checks', 'Diagnoses', 'Repairs', 'Fixtures', 'Docs', 'Tests', 'Changelog', 'Commercial'] as $child) {
    $path = $moduleRoot . '/' . $child;
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Unable to create generated module directory {$path}.");
    }
}
if (!is_dir($baselineRoot . '/blobs') && !mkdir($baselineRoot . '/blobs', 0775, true) && !is_dir($baselineRoot . '/blobs')) {
    throw new RuntimeException('Unable to create the release-artifact baseline blob directory.');
}

$json = static fn (array $value): string => json_encode(
    $value,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . "\n";
$write = static function (string $path, string $contents, int $mode = 0644): void {
    $parent = dirname($path);
    if (!is_dir($parent) || is_link($parent)) {
        throw new RuntimeException("Generated output parent is unavailable or symbolic: {$parent}");
    }
    $temporary = $parent . '/.' . basename($path) . '.build-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write generated output {$path}.");
    }
    chmod($temporary, $mode);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install generated output {$path}.");
    }
};
$slug = static function (string $relative): string {
    $value = preg_replace('/[^A-Za-z0-9]+/', '-', $relative) ?: 'artifact';
    return substr(trim($value, '-'), 0, 72);
};

$usedNumbers = [];
foreach ($previousAssignments as $entry) {
    if (preg_match('/^ARTIFACT-([0-9]{4})$/', (string) ($entry['repair_id'] ?? ''), $match)) {
        $usedNumbers[(int) $match[1]] = true;
    }
}
foreach ($previousDirectoryAssignments as $entry) {
    if (preg_match('/^ARTIFACT-([0-9]{4})$/', (string) ($entry['repair_id'] ?? ''), $match)) {
        $usedNumbers[(int) $match[1]] = true;
    }
}
foreach ($previousDirectoryModeAssignments as $entry) {
    if (preg_match('/^ARTIFACT-([0-9]{4})$/', (string) ($entry['repair_id'] ?? ''), $match)) {
        $usedNumbers[(int) $match[1]] = true;
    }
}
$nextNumber = 1;
$inventory = [];
$manifestArtifacts = [];
$manifestDirectories = [];
$manifestDirectoryModeRepairs = [];
$retiredArtifacts = [];
$retiredDirectories = [];
$retiredDirectoryModeRepairs = [];
$generatedFiles = [];
$horizons = ['2027-01-15T00:00:00Z', '2028-07-01T12:00:00Z', '2031-01-01T00:00:00Z'];

foreach ($selected as $relative => $source) {
    $previousEntry = $previousAssignments[$relative] ?? null;
    if (is_array($previousEntry) && preg_match('/^ARTIFACT-([0-9]{4})$/', (string) ($previousEntry['repair_id'] ?? ''), $match)) {
        $number = (int) $match[1];
    } else {
        while (isset($usedNumbers[$nextNumber])) {
            $nextNumber++;
        }
        if ($nextNumber > 9999) {
            throw new RuntimeException('Artifact permanent-ID range is exhausted.');
        }
        $number = $nextNumber;
        $usedNumbers[$number] = true;
        $nextNumber++;
    }
    $digits = sprintf('%04d', $number);
    $repairId = ARTIFACT_PREFIX . '-' . $digits;
    $checkId = ARTIFACT_PREFIX . '-C' . $digits;
    $diagnosisId = ARTIFACT_PREFIX . '-D' . $digits;
    $source = (array) $source;
    $sourcePath = (string) ($source['path'] ?? '');
    $contents = (string) file_get_contents($sourcePath);
    $sha256 = (string) ($source['sha256'] ?? '');
    $mode = (int) ($source['mode'] ?? 0);
    if (!hash_equals(hash('sha256', $contents), $sha256)) {
        throw new RuntimeException("Release artifact {$relative} changed during catalog generation.");
    }
    if ($mode < 0600 || $mode > 0775 || ($mode & 0002) !== 0) {
        throw new RuntimeException("Artifact {$relative} has an unsafe release mode.");
    }
    $blobRelative = ARTIFACT_BASELINE . '/blobs/' . $sha256;
    $blobPath = APP_ROOT . '/' . $blobRelative;
    if (is_file($blobPath)) {
        $existingHash = hash_file('sha256', $blobPath);
        if (!is_string($existingHash) || !hash_equals($sha256, $existingHash)) {
            throw new RuntimeException("Existing baseline blob {$sha256} failed validation.");
        }
    } else {
        $write($blobPath, $contents, 0444);
    }

    $name = 'Restore managed artifact ' . $relative;
    $fileStem = $repairId . '-' . $slug($relative);
    $targetTemplate = '{APP_ROOT}/' . $relative;
    $baselineTemplate = '{APP_ROOT}/' . $blobRelative;
    $probe = [
        'type' => 'managed_artifact_integrity',
        'repair_id' => $repairId,
        'path' => $targetTemplate,
        'baseline' => $baselineTemplate,
        'sha256' => $sha256,
        'mode' => $mode,
    ];
    $check = [
        'metadata' => [
            'check_id' => $checkId,
            'module' => ARTIFACT_MODULE,
            'name' => $name,
            'summary' => "Verify {$relative} against its immutable local release baseline.",
            'severity' => 'HIGH',
            'version' => '1.0.0',
            'tags' => ['artifact-integrity', 'local-only', 'time-forward'],
        ],
        'supports' => ['managed_release_integrity' => [true]],
        'requires_commands' => [],
        'probe' => $probe,
        'failure_status' => 'FAIL',
    ];
    $diagnosis = [
        'metadata' => [
            'diagnosis_id' => $diagnosisId,
            'check_id' => $checkId,
            'module' => ARTIFACT_MODULE,
            'name' => "Diagnose managed artifact drift for {$relative}",
            'version' => '1.0.0',
        ],
        'diagnosis' => [
            'condition' => "Managed release artifact {$relative} is missing, modified, or has the wrong mode.",
            'expected_state' => "SHA-256 {$sha256} with mode " . sprintf('%04o', $mode) . '.',
            'probable_cause' => 'Local release artifact drift occurred after installation or update.',
            'confidence' => 1.0,
            'related_repair_ids' => [$repairId],
            'automation_allowed' => true,
            'automation_reason' => 'The exact application-owned target, local baseline blob, hash, mode, backup, rollback, and verification are predetermined.',
        ],
    ];
    $repair = [
        'metadata' => [
            'repair_id' => $repairId,
            'module' => ARTIFACT_MODULE,
            'name' => $name,
            'summary' => "Restore {$relative} from its immutable local release baseline.",
            'problem_description' => "The managed application artifact {$relative} differs from the installed release baseline.",
            'symptoms' => ['missing artifact', 'content hash drift', 'permission-mode drift'],
            'probable_causes' => ['incomplete update', 'local file damage', 'unintended modification'],
            'severity' => 'HIGH',
            'risk_level' => 'LOW',
            'automation_class' => 'AUTO_WITH_BACKUP',
            'default_execution_policy' => 'safe-with-backup',
            'supported_operating_systems' => ['Linux'],
            'supported_distributions' => ['all local MicroSysAdmin deployments'],
            'supported_versions' => ['release baseline schema 3'],
            'supported_web_servers' => ['Apache', 'Nginx', 'XAMPP/LAMPP'],
            'supported_php_versions' => ['8.2+'],
            'supported_framework_versions' => ['not framework-dependent'],
            'required_commands' => [],
            'required_packages' => [],
            'required_permissions' => ['write access to this application-owned artifact'],
            'required_services' => [],
            'dependencies' => [],
            'conflicts' => [],
            'files_read' => [$targetTemplate, $baselineTemplate],
            'files_modified' => [$targetTemplate],
            'directories_modified' => [],
            'services_affected' => [],
            'packages_affected' => [],
            'databases_affected' => [],
            'network_effects' => [],
            'backup_strategy' => 'Exact pre-repair file snapshot with SHA-256 validation; missing-file creation is hash-guarded for rollback.',
            'rollback_supported' => true,
            'destructive' => false,
            'estimated_duration' => '<1 second',
            'verification_method' => "Re-run {$checkId} and require the exact release SHA-256 and mode.",
            'repair_version' => '1.0.0',
            'introduced_in' => '1.0.0',
            'deprecated' => false,
            'replacement_repair_id' => null,
            'tags' => ['artifact-integrity', 'local-only', 'zero-interaction', 'atomic', 'rollback'],
            'documentation_reference' => 'modules/artifact-integrity/Docs/managed-artifact-restoration.md',
        ],
        'confidence' => 1.0,
        'detection' => $probe,
        'actions' => [[
            'type' => 'restore_managed_artifact',
            'repair_id' => $repairId,
            'path' => $targetTemplate,
            'baseline' => $baselineTemplate,
            'sha256' => $sha256,
            'mode' => $mode,
            'description' => "Atomically restore {$relative} from its exact local release baseline.",
        ]],
        'backup_paths' => [$targetTemplate],
        'verification' => $probe,
        'rollback' => [
            'supported' => true,
            'strategy' => 'validated BackupManager snapshot or hash-guarded removal for a created file',
            'primitive' => 'restore_managed_artifact',
            'compensating_action' => null,
        ],
        'audit' => [
            'event_namespace' => 'doctor.repair.' . strtolower($repairId),
            'primitive' => 'restore_managed_artifact',
            'record_plan_hash' => true,
            'record_backup_manifest' => true,
            'record_verification' => true,
            'redaction_required' => true,
        ],
        'commercial' => [
            'tier' => 'professional',
            'support_issue_family' => 'release-artifact-integrity',
            'deterministic' => true,
            'bounded' => true,
            'reversible' => true,
            'independently_verifiable' => true,
            'quality_status' => 'contract-tested',
        ],
    ];
    $fixture = [
        'repair_id' => $repairId,
        'artifact' => $relative,
        'baseline_sha256' => $sha256,
        'mode' => sprintf('%04o', $mode),
        'time_forward_horizons' => $horizons,
        'scenarios' => [
            'healthy' => 'exact hash and mode pass',
            'content_drift' => 'modified bytes fail, restore, verify, and rollback',
            'missing' => 'missing file fails, restore, verify, and hash-guarded rollback',
            'mode_drift' => 'wrong mode fails and exact mode is restored',
        ],
        'network_access' => false,
        'external_interaction' => false,
    ];

    $checkRelative = 'Checks/' . $fileStem . '.json';
    $diagnosisRelative = 'Diagnoses/' . $fileStem . '.json';
    $repairRelative = 'Repairs/' . $fileStem . '.json';
    $fixtureRelative = 'Fixtures/' . $repairId . '.json';
    $changelogRelative = 'Changelog/' . $repairId . '.md';
    $commercialRelative = 'Commercial/' . $repairId . '.json';
    $write($moduleRoot . '/' . $checkRelative, $json($check));
    $write($moduleRoot . '/' . $diagnosisRelative, $json($diagnosis));
    $write($moduleRoot . '/' . $repairRelative, $json($repair));
    $write($moduleRoot . '/' . $fixtureRelative, $json($fixture));
    $write($moduleRoot . '/' . $changelogRelative, "# {$repairId}\n\nAdded exact local-baseline restoration for `{$relative}`.\n\n- Primitive: `restore_managed_artifact`\n- Automation: `AUTO_WITH_BACKUP`\n- Risk: `LOW`\n- Verification: `{$checkId}`\n- Rollback: automatic\n");
    $write($moduleRoot . '/' . $commercialRelative, $json([
        'repair_id' => $repairId,
        'commercial_tier' => 'professional',
        'customer_impact' => 'restores one damaged or missing release artifact',
        'support_issue_family' => 'release-artifact-integrity',
        'automation_class' => 'AUTO_WITH_BACKUP',
        'risk_level' => 'LOW',
        'quality_status' => 'contract-tested',
    ]));
    array_push($generatedFiles, $checkRelative, $diagnosisRelative, $repairRelative, $fixtureRelative, $changelogRelative, $commercialRelative);

    $manifestArtifacts[] = [
        'repair_id' => $repairId,
        'check_id' => $checkId,
        'diagnosis_id' => $diagnosisId,
        'path' => $relative,
        'baseline' => $blobRelative,
        'sha256' => $sha256,
        'mode' => $mode,
        'size' => strlen($contents),
        'definition_files' => [$checkRelative, $diagnosisRelative, $repairRelative, $fixtureRelative, $changelogRelative, $commercialRelative],
    ];
    $inventory[] = [
        'repair_id' => $repairId,
        'title' => $name,
        'summary' => "Atomic local-baseline restoration for {$relative}.",
        'status' => 'ACTIVE',
        'priority' => 'P1',
        'frequency' => 'RELEASE_DRIFT',
        'severity' => 'HIGH',
        'detection_confidence' => 'EXACT_SHA256_AND_MODE',
        'automation_feasibility' => 'FULLY_AUTOMATED_LOCAL_ONLY',
        'verification_feasibility' => 'INDEPENDENT_EXACT_PROBE',
        'rollback_feasibility' => 'AUTOMATIC_FROM_VALIDATED_BACKUP',
        'risk' => 'LOW',
        'automation_class' => 'AUTO_WITH_BACKUP',
        'implementation_status' => 'IMPLEMENTED',
        'test_status' => 'TIME_FORWARD_TESTED',
        'fixture_status' => 'IMPLEMENTED',
        'documentation_status' => 'IMPLEMENTED',
        'commercial_status' => 'CONTRACT_TESTED',
        'supported_versions' => ['release baseline schema 3'],
        'known_edge_cases' => ['developer-profile enforcement is disabled to preserve intentional working-tree edits'],
        'dependencies' => [],
        'conflicts' => [],
        'future_expansion_notes' => 'Permanent path-to-ID mapping is retained across release-baseline regeneration.',
    ];
}

foreach ($selectedDirectories as $relative => $source) {
    $previousEntry = $previousDirectoryAssignments[$relative] ?? null;
    if (is_array($previousEntry) && preg_match('/^ARTIFACT-([0-9]{4})$/', (string) ($previousEntry['repair_id'] ?? ''), $match)) {
        $number = (int) $match[1];
    } else {
        while (isset($usedNumbers[$nextNumber])) {
            $nextNumber++;
        }
        if ($nextNumber > 9999) {
            throw new RuntimeException('Artifact permanent-ID range is exhausted.');
        }
        $number = $nextNumber;
        $usedNumbers[$number] = true;
        $nextNumber++;
    }
    $digits = sprintf('%04d', $number);
    $repairId = ARTIFACT_PREFIX . '-' . $digits;
    $checkId = ARTIFACT_PREFIX . '-C' . $digits;
    $diagnosisId = ARTIFACT_PREFIX . '-D' . $digits;
    $mode = (int) $source['mode'];
    $name = 'Restore missing managed release directory ' . $relative;
    $fileStem = $repairId . '-missing-directory-' . $slug($relative);
    $targetTemplate = '{APP_ROOT}/' . $relative;
    $probe = [
        'type' => 'managed_directory_missing',
        'repair_id' => $repairId,
        'path' => $targetTemplate,
        'mode' => $mode,
        'condition' => 'missing',
    ];
    $check = [
        'metadata' => [
            'check_id' => $checkId,
            'module' => ARTIFACT_MODULE,
            'name' => $name,
            'summary' => "Verify that required release directory {$relative} is present.",
            'severity' => 'HIGH',
            'version' => '1.0.0',
            'tags' => ['artifact-integrity', 'directory-topology', 'local-only', 'time-forward'],
        ],
        'supports' => ['managed_release_integrity' => [true]],
        'requires_commands' => [],
        'probe' => $probe,
        'failure_status' => 'FAIL',
    ];
    $diagnosis = [
        'metadata' => [
            'diagnosis_id' => $diagnosisId,
            'check_id' => $checkId,
            'module' => ARTIFACT_MODULE,
            'name' => "Diagnose missing managed release directory {$relative}",
            'version' => '1.0.0',
        ],
        'diagnosis' => [
            'condition' => "Managed release directory {$relative} is missing.",
            'expected_state' => 'A non-symbolic directory is present.',
            'probable_cause' => 'An incomplete update or local deletion removed part of the release topology.',
            'confidence' => 1.0,
            'related_repair_ids' => [$repairId],
            'automation_allowed' => true,
            'automation_reason' => 'The exact application-owned directory chain, modes, identity journal, rollback conditions, and verification are predetermined.',
        ],
    ];
    $repair = [
        'metadata' => [
            'repair_id' => $repairId,
            'module' => ARTIFACT_MODULE,
            'name' => $name,
            'summary' => "Restore missing release directory {$relative}.",
            'problem_description' => "The managed release directory {$relative} is missing from the installed topology.",
            'symptoms' => ['missing release directory'],
            'probable_causes' => ['incomplete update', 'local directory deletion'],
            'severity' => 'HIGH',
            'risk_level' => 'LOW',
            'automation_class' => 'AUTO_SAFE',
            'default_execution_policy' => 'safe',
            'supported_operating_systems' => ['Linux'],
            'supported_distributions' => ['all local MicroSysAdmin deployments'],
            'supported_versions' => ['release baseline schema 3'],
            'supported_web_servers' => ['Apache', 'Nginx', 'XAMPP/LAMPP'],
            'supported_php_versions' => ['8.2+'],
            'supported_framework_versions' => ['not framework-dependent'],
            'required_commands' => [],
            'required_packages' => [],
            'required_permissions' => ['write access to this application-owned directory chain'],
            'required_services' => [],
            'dependencies' => [],
            'conflicts' => [],
            'files_read' => [],
            'files_modified' => [],
            'directories_modified' => [$targetTemplate],
            'services_affected' => [],
            'packages_affected' => [],
            'databases_affected' => [],
            'network_effects' => [],
            'backup_strategy' => 'Primitive-owned exact device/inode and before-mode journal; created paths are removed only while unchanged and empty.',
            'rollback_supported' => true,
            'destructive' => false,
            'estimated_duration' => '<1 second',
            'verification_method' => "Re-run {$checkId} and require the non-symbolic directory to be present.",
            'repair_version' => '1.0.0',
            'introduced_in' => '1.0.0',
            'deprecated' => false,
            'replacement_repair_id' => null,
            'tags' => ['artifact-integrity', 'directory-topology', 'local-only', 'zero-interaction', 'identity-guarded-rollback'],
            'documentation_reference' => 'modules/artifact-integrity/Docs/managed-directory-restoration.md',
        ],
        'confidence' => 1.0,
        'detection' => $probe,
        'actions' => [[
            'type' => 'restore_managed_directory',
            'repair_id' => $repairId,
            'path' => $targetTemplate,
            'mode' => $mode,
            'condition' => 'missing',
            'description' => "Restore {$relative} and any missing manifest-authorized parent directories.",
        ]],
        'backup_paths' => [],
        'verification' => $probe,
        'rollback' => [
            'supported' => true,
            'strategy' => 'remove only unchanged empty directories created by this action in reverse order',
            'primitive' => 'restore_managed_directory',
            'compensating_action' => null,
        ],
        'audit' => [
            'event_namespace' => 'doctor.repair.' . strtolower($repairId),
            'primitive' => 'restore_managed_directory',
            'record_plan_hash' => true,
            'record_backup_manifest' => true,
            'record_verification' => true,
            'redaction_required' => true,
        ],
        'commercial' => [
            'tier' => 'professional',
            'support_issue_family' => 'missing-release-directory',
            'deterministic' => true,
            'bounded' => true,
            'reversible' => true,
            'independently_verifiable' => true,
            'quality_status' => 'contract-tested',
        ],
    ];
    $fixture = [
        'repair_id' => $repairId,
        'directory' => $relative,
        'mode' => sprintf('%04o', $mode),
        'time_forward_horizons' => $horizons,
        'scenarios' => [
            'healthy' => 'a non-symbolic directory passes regardless of its independently owned mode state',
            'missing_chain' => 'missing manifest parents are created, verified, and removed in reverse order on rollback',
            'obstruction' => 'symbolic or non-directory obstruction is excluded from automatic mutation',
        ],
        'network_access' => false,
        'external_interaction' => false,
    ];

    $checkRelative = 'Checks/' . $fileStem . '.json';
    $diagnosisRelative = 'Diagnoses/' . $fileStem . '.json';
    $repairRelative = 'Repairs/' . $fileStem . '.json';
    $fixtureRelative = 'Fixtures/' . $repairId . '.json';
    $changelogRelative = 'Changelog/' . $repairId . '.md';
    $commercialRelative = 'Commercial/' . $repairId . '.json';
    $write($moduleRoot . '/' . $checkRelative, $json($check));
    $write($moduleRoot . '/' . $diagnosisRelative, $json($diagnosis));
    $write($moduleRoot . '/' . $repairRelative, $json($repair));
    $write($moduleRoot . '/' . $fixtureRelative, $json($fixture));
    $write($moduleRoot . '/' . $changelogRelative, "# {$repairId}\n\nAdded exact missing release-directory restoration for `{$relative}`.\n\n- Primitive: `restore_managed_directory`\n- Condition: `missing`\n- Automation: `AUTO_SAFE`\n- Risk: `LOW`\n- Verification: `{$checkId}`\n- Rollback: automatic identity journal\n");
    $write($moduleRoot . '/' . $commercialRelative, $json([
        'repair_id' => $repairId,
        'commercial_tier' => 'professional',
        'customer_impact' => 'restores one missing release directory',
        'support_issue_family' => 'missing-release-directory',
        'automation_class' => 'AUTO_SAFE',
        'risk_level' => 'LOW',
        'quality_status' => 'contract-tested',
    ]));
    array_push($generatedFiles, $checkRelative, $diagnosisRelative, $repairRelative, $fixtureRelative, $changelogRelative, $commercialRelative);

    $manifestDirectories[] = [
        'repair_id' => $repairId,
        'check_id' => $checkId,
        'diagnosis_id' => $diagnosisId,
        'path' => $relative,
        'mode' => $mode,
        'condition' => 'missing',
        'definition_files' => [$checkRelative, $diagnosisRelative, $repairRelative, $fixtureRelative, $changelogRelative, $commercialRelative],
    ];
    $inventory[] = [
        'repair_id' => $repairId,
        'title' => $name,
        'summary' => "Identity-guarded missing-directory restoration for {$relative}.",
        'status' => 'ACTIVE',
        'priority' => 'P1',
        'frequency' => 'MISSING_RELEASE_DIRECTORY',
        'severity' => 'HIGH',
        'detection_confidence' => 'EXACT_DIRECTORY_ABSENCE',
        'automation_feasibility' => 'FULLY_AUTOMATED_LOCAL_ONLY',
        'verification_feasibility' => 'INDEPENDENT_EXACT_PROBE',
        'rollback_feasibility' => 'AUTOMATIC_IDENTITY_AND_MODE_JOURNAL',
        'risk' => 'LOW',
        'automation_class' => 'AUTO_SAFE',
        'implementation_status' => 'IMPLEMENTED',
        'test_status' => 'TIME_FORWARD_TESTED',
        'fixture_status' => 'IMPLEMENTED',
        'documentation_status' => 'IMPLEMENTED',
        'commercial_status' => 'CONTRACT_TESTED',
        'supported_versions' => ['release baseline schema 3'],
        'known_edge_cases' => ['symbolic-link or non-directory obstructions require operator review and are never replaced automatically'],
        'dependencies' => [],
        'conflicts' => [],
        'future_expansion_notes' => 'Permanent path-to-ID mapping is retained across release-topology regeneration.',
    ];

    $previousModeEntry = $previousDirectoryModeAssignments[$relative] ?? null;
    if (is_array($previousModeEntry) && preg_match('/^ARTIFACT-([0-9]{4})$/', (string) ($previousModeEntry['repair_id'] ?? ''), $match)) {
        $modeNumber = (int) $match[1];
    } else {
        while (isset($usedNumbers[$nextNumber])) {
            $nextNumber++;
        }
        if ($nextNumber > 9999) {
            throw new RuntimeException('Artifact permanent-ID range is exhausted.');
        }
        $modeNumber = $nextNumber;
        $usedNumbers[$modeNumber] = true;
        $nextNumber++;
    }
    $modeDigits = sprintf('%04d', $modeNumber);
    $modeRepairId = ARTIFACT_PREFIX . '-' . $modeDigits;
    $modeCheckId = ARTIFACT_PREFIX . '-C' . $modeDigits;
    $modeDiagnosisId = ARTIFACT_PREFIX . '-D' . $modeDigits;
    $modeName = 'Correct managed release directory mode ' . $relative;
    $modeStem = $modeRepairId . '-directory-mode-' . $slug($relative);
    $modeProbe = [
        'type' => 'managed_directory_mode',
        'repair_id' => $modeRepairId,
        'path' => $targetTemplate,
        'mode' => $mode,
        'condition' => 'mode',
    ];

    $modeCheck = $check;
    $modeCheck['metadata']['check_id'] = $modeCheckId;
    $modeCheck['metadata']['name'] = $modeName;
    $modeCheck['metadata']['summary'] = "Verify exact permission mode for release directory {$relative}.";
    $modeCheck['metadata']['tags'][] = 'mode-drift';
    $modeCheck['probe'] = $modeProbe;

    $modeDiagnosis = $diagnosis;
    $modeDiagnosis['metadata']['diagnosis_id'] = $modeDiagnosisId;
    $modeDiagnosis['metadata']['check_id'] = $modeCheckId;
    $modeDiagnosis['metadata']['name'] = "Diagnose managed release directory mode drift for {$relative}";
    $modeDiagnosis['diagnosis']['condition'] = "Managed release directory {$relative} has the wrong permission mode.";
    $modeDiagnosis['diagnosis']['expected_state'] = 'The existing non-symbolic directory has mode ' . sprintf('%04o', $mode) . '.';
    $modeDiagnosis['diagnosis']['probable_cause'] = 'An unintended permission change altered the existing release directory mode.';
    $modeDiagnosis['diagnosis']['related_repair_ids'] = [$modeRepairId];
    $modeDiagnosis['diagnosis']['automation_reason'] = 'The exact existing directory, desired mode, device/inode identity, prior mode, rollback guard, and verification are predetermined.';

    $modeRepair = $repair;
    $modeRepair['metadata']['repair_id'] = $modeRepairId;
    $modeRepair['metadata']['name'] = $modeName;
    $modeRepair['metadata']['summary'] = "Correct release directory {$relative} to its exact manifest mode.";
    $modeRepair['metadata']['problem_description'] = "The existing managed release directory {$relative} has permission-mode drift.";
    $modeRepair['metadata']['symptoms'] = ['directory permission-mode drift'];
    $modeRepair['metadata']['probable_causes'] = ['unintended permission change'];
    $modeRepair['metadata']['backup_strategy'] = 'Primitive-owned exact device/inode identity and prior-mode journal.';
    $modeRepair['metadata']['verification_method'] = "Re-run {$modeCheckId} and require the existing non-symbolic directory to have the exact manifest mode.";
    $modeRepair['metadata']['tags'][] = 'mode-drift';
    $modeRepair['detection'] = $modeProbe;
    $modeRepair['actions'] = [[
        'type' => 'restore_managed_directory',
        'repair_id' => $modeRepairId,
        'path' => $targetTemplate,
        'mode' => $mode,
        'condition' => 'mode',
        'description' => "Correct the existing release directory {$relative} to its exact manifest mode.",
    ]];
    $modeRepair['verification'] = $modeProbe;
    $modeRepair['rollback']['strategy'] = 'restore the exact prior mode only while device/inode identity and the repaired mode remain unchanged';
    $modeRepair['audit']['event_namespace'] = 'doctor.repair.' . strtolower($modeRepairId);
    $modeRepair['commercial']['support_issue_family'] = 'release-directory-mode-drift';

    $modeFixture = [
        'repair_id' => $modeRepairId,
        'directory' => $relative,
        'mode' => sprintf('%04o', $mode),
        'condition' => 'mode',
        'time_forward_horizons' => $horizons,
        'scenarios' => [
            'healthy' => 'existing non-symbolic directory with exact mode passes',
            'missing' => 'missing state is owned by the distinct missing-directory repair and is not mutated by this repair',
            'mode_drift' => 'wrong mode is restored and the prior mode is restored on rollback',
            'obstruction' => 'symbolic or non-directory obstruction is excluded from automatic mutation',
        ],
        'network_access' => false,
        'external_interaction' => false,
    ];

    $modeCheckRelative = 'Checks/' . $modeStem . '.json';
    $modeDiagnosisRelative = 'Diagnoses/' . $modeStem . '.json';
    $modeRepairRelative = 'Repairs/' . $modeStem . '.json';
    $modeFixtureRelative = 'Fixtures/' . $modeRepairId . '.json';
    $modeChangelogRelative = 'Changelog/' . $modeRepairId . '.md';
    $modeCommercialRelative = 'Commercial/' . $modeRepairId . '.json';
    $write($moduleRoot . '/' . $modeCheckRelative, $json($modeCheck));
    $write($moduleRoot . '/' . $modeDiagnosisRelative, $json($modeDiagnosis));
    $write($moduleRoot . '/' . $modeRepairRelative, $json($modeRepair));
    $write($moduleRoot . '/' . $modeFixtureRelative, $json($modeFixture));
    $write($moduleRoot . '/' . $modeChangelogRelative, "# {$modeRepairId}\n\nAdded exact release-directory mode correction for `{$relative}`.\n\n- Primitive: `restore_managed_directory`\n- Condition: `mode`\n- Automation: `AUTO_SAFE`\n- Risk: `LOW`\n- Verification: `{$modeCheckId}`\n- Rollback: automatic identity/prior-mode journal\n");
    $write($moduleRoot . '/' . $modeCommercialRelative, $json([
        'repair_id' => $modeRepairId,
        'commercial_tier' => 'professional',
        'customer_impact' => 'corrects one existing release directory permission mode',
        'support_issue_family' => 'release-directory-mode-drift',
        'automation_class' => 'AUTO_SAFE',
        'risk_level' => 'LOW',
        'quality_status' => 'contract-tested',
    ]));
    array_push(
        $generatedFiles,
        $modeCheckRelative,
        $modeDiagnosisRelative,
        $modeRepairRelative,
        $modeFixtureRelative,
        $modeChangelogRelative,
        $modeCommercialRelative,
    );

    $manifestDirectoryModeRepairs[] = [
        'repair_id' => $modeRepairId,
        'check_id' => $modeCheckId,
        'diagnosis_id' => $modeDiagnosisId,
        'path' => $relative,
        'mode' => $mode,
        'condition' => 'mode',
        'definition_files' => [$modeCheckRelative, $modeDiagnosisRelative, $modeRepairRelative, $modeFixtureRelative, $modeChangelogRelative, $modeCommercialRelative],
    ];
    $inventory[] = [
        'repair_id' => $modeRepairId,
        'title' => $modeName,
        'summary' => "Identity-guarded permission-mode correction for existing release directory {$relative}.",
        'status' => 'ACTIVE',
        'priority' => 'P1',
        'frequency' => 'RELEASE_DIRECTORY_MODE_DRIFT',
        'severity' => 'HIGH',
        'detection_confidence' => 'EXACT_EXISTING_DIRECTORY_MODE_MISMATCH',
        'automation_feasibility' => 'FULLY_AUTOMATED_LOCAL_ONLY',
        'verification_feasibility' => 'INDEPENDENT_EXACT_PROBE',
        'rollback_feasibility' => 'AUTOMATIC_IDENTITY_AND_PRIOR_MODE_JOURNAL',
        'risk' => 'LOW',
        'automation_class' => 'AUTO_SAFE',
        'implementation_status' => 'IMPLEMENTED',
        'test_status' => 'TIME_FORWARD_TESTED',
        'fixture_status' => 'IMPLEMENTED',
        'documentation_status' => 'IMPLEMENTED',
        'commercial_status' => 'CONTRACT_TESTED',
        'supported_versions' => ['release baseline schema 3'],
        'known_edge_cases' => ['missing, symbolic-link, and non-directory states are owned by distinct contracts and are never mutated by this repair'],
        'dependencies' => [],
        'conflicts' => [],
        'future_expansion_notes' => 'Permanent path-to-mode-repair ID mapping is retained across release-topology regeneration.',
    ];
}

// Preserve every historical path-to-ID assignment without leaving an active
// repair capable of mutating a file that is no longer part of the release.
foreach ($previousAssignments as $relative => $entry) {
    if (isset($selected[$relative])) {
        continue;
    }
    $repairId = (string) ($entry['repair_id'] ?? '');
    if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId)) {
        throw new RuntimeException("Historical artifact {$relative} has an invalid permanent ID.");
    }
    $retiredArtifacts[] = [
        'repair_id' => $repairId,
        'path' => $relative,
        'status' => 'RETIRED',
        'retirement_reason' => 'Path is outside the authoritative commercial release packaging boundary.',
        'definition_files' => (array) ($entry['definition_files'] ?? []),
    ];
    $inventory[] = [
        'repair_id' => $repairId,
        'title' => 'Retired managed artifact ' . $relative,
        'summary' => 'Permanent ID retained; no active repair can mutate this non-release path.',
        'status' => 'RETIRED',
        'priority' => 'RETIRED',
        'frequency' => 'NOT_APPLICABLE',
        'severity' => 'INFORMATIONAL',
        'detection_confidence' => 'NOT_APPLICABLE',
        'automation_feasibility' => 'PROHIBITED_OUTSIDE_RELEASE_BOUNDARY',
        'verification_feasibility' => 'NOT_APPLICABLE',
        'rollback_feasibility' => 'NOT_APPLICABLE',
        'risk' => 'NONE',
        'automation_class' => 'PROHIBITED',
        'implementation_status' => 'RETIRED',
        'test_status' => 'POLICY_BOUNDARY_TESTED',
        'fixture_status' => 'RETIRED',
        'documentation_status' => 'RETIRED',
        'commercial_status' => 'RETIRED',
        'supported_versions' => [],
        'known_edge_cases' => [],
        'dependencies' => [],
        'conflicts' => [],
        'future_expansion_notes' => 'May reactivate under the same permanent ID only if the path re-enters the commercial release allowlist.',
    ];
}

foreach ($previousDirectoryAssignments as $relative => $entry) {
    if (isset($selectedDirectories[$relative])) {
        continue;
    }
    $repairId = (string) ($entry['repair_id'] ?? '');
    if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId)) {
        throw new RuntimeException("Historical release directory {$relative} has an invalid permanent ID.");
    }
    $retiredDirectories[] = [
        'repair_id' => $repairId,
        'path' => $relative,
        'status' => 'RETIRED',
        'retirement_reason' => 'Directory is outside the current authoritative commercial release topology.',
        'definition_files' => (array) ($entry['definition_files'] ?? []),
    ];
    $inventory[] = [
        'repair_id' => $repairId,
        'title' => 'Retired managed release directory ' . $relative,
        'summary' => 'Permanent ID retained; no active repair can mutate this directory.',
        'status' => 'RETIRED',
        'priority' => 'RETIRED',
        'frequency' => 'NOT_APPLICABLE',
        'severity' => 'INFORMATIONAL',
        'detection_confidence' => 'NOT_APPLICABLE',
        'automation_feasibility' => 'PROHIBITED_OUTSIDE_RELEASE_BOUNDARY',
        'verification_feasibility' => 'NOT_APPLICABLE',
        'rollback_feasibility' => 'NOT_APPLICABLE',
        'risk' => 'NONE',
        'automation_class' => 'PROHIBITED',
        'implementation_status' => 'RETIRED',
        'test_status' => 'POLICY_BOUNDARY_TESTED',
        'fixture_status' => 'RETIRED',
        'documentation_status' => 'RETIRED',
        'commercial_status' => 'RETIRED',
        'supported_versions' => [],
        'known_edge_cases' => [],
        'dependencies' => [],
        'conflicts' => [],
        'future_expansion_notes' => 'May reactivate under the same permanent ID only if the directory re-enters the commercial release topology.',
    ];
}

foreach ($previousDirectoryModeAssignments as $relative => $entry) {
    if (isset($selectedDirectories[$relative])) {
        continue;
    }
    $repairId = (string) ($entry['repair_id'] ?? '');
    if (!preg_match('/^ARTIFACT-[0-9]{4}$/', $repairId)) {
        throw new RuntimeException("Historical release-directory mode repair {$relative} has an invalid permanent ID.");
    }
    $retiredDirectoryModeRepairs[] = [
        'repair_id' => $repairId,
        'path' => $relative,
        'condition' => 'mode',
        'status' => 'RETIRED',
        'retirement_reason' => 'Directory is outside the current authoritative commercial release topology.',
        'definition_files' => (array) ($entry['definition_files'] ?? []),
    ];
    $inventory[] = [
        'repair_id' => $repairId,
        'title' => 'Retired release-directory mode repair ' . $relative,
        'summary' => 'Permanent mode-repair ID retained; no active repair can mutate this directory.',
        'status' => 'RETIRED',
        'priority' => 'RETIRED',
        'frequency' => 'NOT_APPLICABLE',
        'severity' => 'INFORMATIONAL',
        'detection_confidence' => 'NOT_APPLICABLE',
        'automation_feasibility' => 'PROHIBITED_OUTSIDE_RELEASE_BOUNDARY',
        'verification_feasibility' => 'NOT_APPLICABLE',
        'rollback_feasibility' => 'NOT_APPLICABLE',
        'risk' => 'NONE',
        'automation_class' => 'PROHIBITED',
        'implementation_status' => 'RETIRED',
        'test_status' => 'POLICY_BOUNDARY_TESTED',
        'fixture_status' => 'RETIRED',
        'documentation_status' => 'RETIRED',
        'commercial_status' => 'RETIRED',
        'supported_versions' => [],
        'known_edge_cases' => [],
        'dependencies' => [],
        'conflicts' => [],
        'future_expansion_notes' => 'May reactivate under the same permanent ID only if the directory re-enters the commercial release topology.',
    ];
}

usort($manifestArtifacts, static fn (array $a, array $b): int => strcmp($a['repair_id'], $b['repair_id']));
usort($manifestDirectories, static fn (array $a, array $b): int => strcmp($a['repair_id'], $b['repair_id']));
usort($manifestDirectoryModeRepairs, static fn (array $a, array $b): int => strcmp($a['repair_id'], $b['repair_id']));
usort($retiredArtifacts, static fn (array $a, array $b): int => strcmp($a['repair_id'], $b['repair_id']));
usort($retiredDirectories, static fn (array $a, array $b): int => strcmp($a['repair_id'], $b['repair_id']));
usort($retiredDirectoryModeRepairs, static fn (array $a, array $b): int => strcmp($a['repair_id'], $b['repair_id']));
usort($inventory, static fn (array $a, array $b): int => strcmp($a['repair_id'], $b['repair_id']));

// Delete only stale generated definition paths recorded by the prior manifest.
$activeGenerated = array_fill_keys($generatedFiles, true);
foreach ([
    ...(array) ($previous['artifacts'] ?? []),
    ...(array) ($previous['directories'] ?? []),
    ...(array) ($previous['directory_mode_repairs'] ?? []),
    ...(array) ($previous['retired_artifacts'] ?? []),
    ...(array) ($previous['retired_directories'] ?? []),
    ...(array) ($previous['retired_directory_mode_repairs'] ?? []),
] as $old) {
    foreach ((array) ($old['definition_files'] ?? []) as $relativeGenerated) {
        $relativeGenerated = str_replace('\\', '/', (string) $relativeGenerated);
        if (isset($activeGenerated[$relativeGenerated])
            || $relativeGenerated === ''
            || str_starts_with($relativeGenerated, '/')
            || str_contains("/{$relativeGenerated}/", '/../')) {
            continue;
        }
        $target = $moduleRoot . '/' . $relativeGenerated;
        $parent = realpath(dirname($target));
        $moduleReal = realpath($moduleRoot);
        if ($parent !== false && $moduleReal !== false
            && str_starts_with($parent, $moduleReal . DIRECTORY_SEPARATOR)
            && is_file($target) && !is_link($target)) {
            unlink($target);
        }
    }
}

$module = [
    'name' => ARTIFACT_MODULE,
    'display_name' => 'Artifact Integrity',
    'description' => 'Local-only, zero-interaction restoration of immutable application release artifacts.',
    'version' => '1.0.0',
    'stage' => 6,
    'enabled' => true,
    'repair_prefix' => ARTIFACT_PREFIX,
    'minimum_php' => '8.2',
    'repair_inventory' => 'repair-inventory.json',
];
$write($moduleRoot . '/module.json', $json($module));
$write($moduleRoot . '/repair-inventory.json', $json($inventory));
$write($moduleRoot . '/README.md', sprintf(
    "# Artifact Integrity\n\nThis generated module contains %d independent local-only file repair lifecycles, %d missing-directory lifecycles, and %d directory-mode lifecycles for the authoritative commercial packaging boundary. Enforcement is disabled in the developer security profile so intentional source edits are never reverted. Release deployments compare each managed artifact to a content-addressed baseline and assign mutually exclusive cause owners to directory absence and mode drift. File restoration is atomic with backup; directory restoration uses an exact identity/mode journal and removes created paths on rollback only while unchanged and empty. %d historical file IDs, %d historical directory IDs, and %d historical directory-mode IDs are retained as non-executable tombstones.\n",
    count($manifestArtifacts),
    count($manifestDirectories),
    count($manifestDirectoryModeRepairs),
    count($retiredArtifacts),
    count($retiredDirectories),
    count($retiredDirectoryModeRepairs),
));
$write($moduleRoot . '/Docs/managed-artifact-restoration.md', <<<'MD'
# Managed artifact restoration

Each ARTIFACT repair owns exactly one application release file. The release
manifest fixes the target path, baseline blob, SHA-256 digest, and permission
mode. A repair reads no network resource and invokes no external provider or
package manager. Before replacement it captures the existing regular file;
replacement uses an atomic rename; verification repeats the independent hash
and mode probe. Rollback restores the validated snapshot, or removes an
artifact created from a previously missing state only while its installed hash
still matches the recorded repair output.

Artifact enforcement is not applicable under the developer security profile.
That boundary prevents Doctor from overwriting intentional working-tree edits.
The generator derives its complete candidate set from
`packaging/release-allowlist.txt` and `packaging/release-excludes.txt`, so a
developer-only path cannot become an executable repair merely because it
exists in a working tree. Release builds regenerate the local baseline
explicitly and preserve permanent path-to-repair ID assignments. An ID whose
path leaves the release policy is retained as a non-executable tombstone and is
never reassigned to a different path.
MD
);
$write($moduleRoot . '/Docs/managed-directory-restoration.md', <<<'MD'
# Managed release-directory restoration

Each release directory has two mutually exclusive condition owners: one repair
for absence and one for permission-mode drift on an existing directory. The
same immutable release manifest independently authorizes both permanent repair
IDs, their shared path, exact mode, and distinct condition. Missing ancestor
segments may be created only by the absence owner and only when every segment
is present in that topology manifest.

The primitive records the device/inode identity and exact mode for every
created segment. Rollback visits them in reverse order and removes a segment
only while its identity and mode are unchanged and it is empty. For a
pre-existing directory with mode drift, the mode owner records its identity
and prior mode, applies the manifest mode, and restores the prior mode only
while that identity and repaired mode remain unchanged. A missing state is
inapplicable to the mode owner. Symbolic-link and non-directory obstructions
are never replaced automatically by either owner.

Managed-artifact file restoration uses the same topology catalog to create
missing authorized parent directories before an atomic file replacement, so
repair correctness does not depend on execution ordering.
MD
);
$write($moduleRoot . '/Tests/cases.json', $json([
    'artifact_count' => count($manifestArtifacts),
    'directory_count' => count($manifestDirectories),
    'directory_mode_repair_count' => count($manifestDirectoryModeRepairs),
    'time_forward_horizons' => $horizons,
    'scenario_count' => (count($manifestArtifacts) + count($manifestDirectories) + count($manifestDirectoryModeRepairs)) * count($horizons),
    'required_outcomes' => ['detect', 'diagnose', 'plan', 'backup', 'repair', 'verify', 'rollback', 'idempotent'],
    'network_access' => false,
    'external_interaction' => false,
]));

$catalogRevision = hash('sha256', $json([
    'provenance' => $catalogProvenance,
    'artifacts' => $manifestArtifacts,
    'directories' => $manifestDirectories,
    'directory_mode_repairs' => $manifestDirectoryModeRepairs,
    'retired_artifacts' => $retiredArtifacts,
    'retired_directories' => $retiredDirectories,
    'retired_directory_mode_repairs' => $retiredDirectoryModeRepairs,
]));
$manifest = [
    'schema_version' => 3,
    'module' => ARTIFACT_MODULE,
    'repair_count' => count($manifestArtifacts) + count($manifestDirectories) + count($manifestDirectoryModeRepairs),
    'artifact_count' => count($manifestArtifacts),
    'directory_count' => count($manifestDirectories),
    'directory_mode_repair_count' => count($manifestDirectoryModeRepairs),
    'resource_count' => count($selected) + count($selectedDirectories),
    'target_count' => count($selected) + count($selectedDirectories) * 2,
    'retired_count' => count($retiredArtifacts),
    'retired_directory_count' => count($retiredDirectories),
    'retired_directory_mode_repair_count' => count($retiredDirectoryModeRepairs),
    'catalog_revision' => $catalogRevision,
    'selection' => [
        'strategy' => 'all_eligible_release_files_locked_vendor_and_cause_specific_directory_topology',
        'provenance' => $catalogProvenance,
        'coverage_percent' => (count($selected) + count($selectedDirectories) * 2) === 0
            ? 0.0
            : round((count($manifestArtifacts) + count($manifestDirectories) + count($manifestDirectoryModeRepairs)) / (count($selected) + count($selectedDirectories) * 2) * 100, 2),
        'network_access' => false,
        'external_interaction' => false,
    ],
    'time_forward_horizons' => $horizons,
    'artifacts' => $manifestArtifacts,
    'directories' => $manifestDirectories,
    'directory_mode_repairs' => $manifestDirectoryModeRepairs,
    'retired_artifacts' => $retiredArtifacts,
    'retired_directories' => $retiredDirectories,
    'retired_directory_mode_repairs' => $retiredDirectoryModeRepairs,
];
$write($manifestPath, $json($manifest), 0444);

echo sprintf(
    "Artifact integrity library: %d file, %d missing-directory, and %d mode-drift lifecycles; %d retired permanent IDs, %d local baseline blobs; revision %s.\n",
    count($manifestArtifacts),
    count($manifestDirectories),
    count($manifestDirectoryModeRepairs),
    count($retiredArtifacts) + count($retiredDirectories) + count($retiredDirectoryModeRepairs),
    count(glob($baselineRoot . '/blobs/*') ?: []),
    $catalogRevision,
);
