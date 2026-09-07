#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\sdk\Generation\RepairGenerator;

$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $temporary = dirname($path) . '/.' . basename($path) . '.cycle-003-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write {$path}.");
    }
    chmod($temporary, 0644);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install {$path}.");
    }
};
$find = static function (string $module, string $folder, string $id): string {
    $paths = glob(APP_ROOT . "/doctor/modules/{$module}/{$folder}/{$id}-*.json") ?: [];
    if (count($paths) !== 1) {
        throw new RuntimeException("Expected one {$folder} definition for {$id}.");
    }
    return $paths[0];
};

$securityProbe = ['type' => 'production_debug_disabled', 'path' => '{PROJECT}/.env'];
$securityDetection = [...$securityProbe, 'require_mutable' => true];
$securityCheckPath = $find('security', 'Checks', 'SECURITY-0007');
$securityCheck = json_decode((string) file_get_contents($securityCheckPath), true, 512, JSON_THROW_ON_ERROR);
$securityCheck['probe'] = $securityProbe;
$write($securityCheckPath, $json($securityCheck));
$securityDiagnosisPath = $find('security', 'Diagnoses', 'SECURITY-0007');
$securityDiagnosis = json_decode((string) file_get_contents($securityDiagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$securityDiagnosis['diagnosis']['expected_state'] = 'For APP_ENV=production, APP_DEBUG is absent or an exact supported false value.';
$securityDiagnosis['diagnosis']['automation_allowed'] = true;
$securityDiagnosis['diagnosis']['automation_reason'] = 'Only an execution-owned project .env with unambiguous APP_ENV=production and one enabled APP_DEBUG assignment is changed; a secret-safe exact snapshot and post-repair hash guard support rollback.';
$securityDiagnosis['repair_generation'] = [
    'repair_id' => 'SECURITY-0007',
    'name' => 'Disable Laravel debug output in production',
    'automation_class' => 'AUTO_WITH_BACKUP',
    'risk_level' => 'LOW',
    'backup_strategy' => 'secret-safe exact .env snapshot with post-repair hash guard',
    'detection' => $securityDetection,
    'files_modified' => ['{PROJECT}/.env'],
    'required_permissions' => ['execution-account ownership and write access to the project .env'],
    'action' => [
        'type' => 'set_env_value',
        'repair_id' => 'SECURITY-0007',
        'path' => '{PROJECT}/.env',
        'key' => 'APP_DEBUG',
        'value' => 'false',
        'guard_key' => 'APP_ENV',
        'guard_value' => 'production',
        'description' => 'Set the one unambiguous production APP_DEBUG assignment to false without recording any other environment values.',
    ],
    'commercial_tier' => 'professional',
    'support_issue_family' => 'production-debug-exposure',
];
$write($securityDiagnosisPath, $json($securityDiagnosis));

$gitProbe = [
    'type' => 'stale_git_index_lock',
    'path' => '{PROJECT}/.git/index.lock',
    'minimum_age_seconds' => 3600,
];
$gitCheckPath = $find('git', 'Checks', 'GIT-0007');
$gitCheck = json_decode((string) file_get_contents($gitCheckPath), true, 512, JSON_THROW_ON_ERROR);
$gitCheck['requires_commands'] = ['lsof'];
$gitCheck['probe'] = $gitProbe;
$write($gitCheckPath, $json($gitCheck));
$gitDiagnosisPath = $find('git', 'Diagnoses', 'GIT-0007');
$gitDiagnosis = json_decode((string) file_get_contents($gitDiagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$gitDiagnosis['diagnosis']['expected_state'] = 'The exact project .git/index.lock is absent, young, active, inconclusive, or outside the execution account ownership boundary.';
$gitDiagnosis['diagnosis']['automation_allowed'] = true;
$gitDiagnosis['diagnosis']['automation_reason'] = 'Only an execution-owned regular index.lock aged at least one hour and proven unowned by lsof is quarantined; inode/hash revalidation and an exact snapshot guard both repair and rollback races.';
$gitDiagnosis['repair_generation'] = [
    'repair_id' => 'GIT-0007',
    'name' => 'Remove a verified stale Git index lock',
    'automation_class' => 'AUTO_WITH_BACKUP',
    'risk_level' => 'LOW',
    'backup_strategy' => 'exact lock snapshot restored only while the repaired path remains absent',
    'required_commands' => ['lsof'],
    'files_modified' => ['{PROJECT}/.git/index.lock'],
    'required_permissions' => ['execution-account ownership and write access to the project Git index lock'],
    'action' => [
        'type' => 'quarantine_stale_git_lock',
        'repair_id' => 'GIT-0007',
        'path' => '{PROJECT}/.git/index.lock',
        'minimum_age_seconds' => 3600,
        'description' => 'Quarantine only the exact aged, execution-owned, process-unowned project Git index lock after repeated identity validation.',
    ],
    'commercial_tier' => 'professional',
    'support_issue_family' => 'git-stale-lock',
];
$write($gitDiagnosisPath, $json($gitDiagnosis));

$generator = new RepairGenerator();
foreach ([$securityDiagnosisPath, $gitDiagnosisPath] as $diagnosisPath) {
    $generator->generate($diagnosisPath, APP_ROOT . '/doctor/modules', true);
}

foreach (['security' => ['SECURITY-0007'], 'git' => ['GIT-0007']] as $module => $ids) {
    $inventoryPath = APP_ROOT . "/doctor/modules/{$module}/repair-inventory.json";
    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($inventory as &$entry) {
        if (!in_array((string) ($entry['repair_id'] ?? ''), $ids, true)) {
            continue;
        }
        $entry['risk'] = 'LOW';
        $entry['automation_class'] = 'AUTO_WITH_BACKUP';
        $entry['automation_feasibility'] = 'HIGH';
        $entry['rollback_feasibility'] = 'SUPPORTED';
        $entry['implementation_status'] = 'IMPLEMENTED';
        $entry['commercial_status'] = 'CONTRACT_READY';
    }
    unset($entry);
    $write($inventoryPath, $json($inventory));
}

$write(APP_ROOT . '/doctor/modules/security/Docs/SECURITY-0007.md', <<<'MD'
# SECURITY-0007: Disable Laravel debug output in production

Applies only to the exact project `.env` when it contains one unambiguous `APP_ENV=production` assignment and one supported enabled `APP_DEBUG` boolean. Non-production, missing, duplicate, unsupported, symbolic, externally owned, or unwritable states are never changed automatically.

The semantic dotenv primitive captures a secret-safe exact snapshot, changes only `APP_DEBUG`, emits no other environment values or diff content, verifies the file hash and parsed value independently, and restores the original file only while the post-repair hash is unchanged.
MD
);
$write(APP_ROOT . '/doctor/modules/git/Docs/GIT-0007.md', <<<'MD'
# GIT-0007: Remove a verified stale Git index lock

Applies only to the exact project `.git/index.lock` when it is a regular execution-owned file, is at least one hour old, and `lsof` proves no process owns it. Young, active, ambiguous, symbolic, differently owned, or inaccessible locks are excluded.

The stale-lock primitive snapshots the exact file, repeats inode, size, modification-time, hash, age, and process-ownership validation, then removes only that hash. Rollback restores the byte-exact lock only while the path remains absent, refusing to overwrite a new Git operation's lock.
MD
);

echo "Cycle 003 conversion cohort: SECURITY-0007 and GIT-0007 are executable with secret-safe, race-guarded rollback contracts.\n";
