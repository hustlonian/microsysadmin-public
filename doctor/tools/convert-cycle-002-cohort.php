#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('STORAGE_PATH', APP_ROOT . '/storage');
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\sdk\Generation\RepairGenerator;

$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $temporary = dirname($path) . '/.' . basename($path) . '.cycle-002-' . bin2hex(random_bytes(5));
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

$cronProbe = [
    'type' => 'command_occurrences',
    'command' => ['crontab', '-l'],
    'needle' => '# MicroSysAdmin Doctor CRON-0004',
    'minimum' => 0,
    'maximum' => 1,
    'timeout' => 20,
];
$cronRepairPath = $find('cron', 'Repairs', 'CRON-0005');
$cronRepair = json_decode((string) file_get_contents($cronRepairPath), true, 512, JSON_THROW_ON_ERROR);
$cronRepair['metadata']['automation_class'] = 'AUTO_WITH_BACKUP';
$cronRepair['metadata']['default_execution_policy'] = 'safe-with-backup';
$cronRepair['metadata']['risk_level'] = 'LOW';
$cronRepair['metadata']['rollback_supported'] = true;
$cronRepair['metadata']['backup_strategy'] = 'complete service-account crontab snapshot with post-repair hash guard';
$cronRepair['metadata']['required_permissions'] = ['service-account crontab read and write'];
$cronRepair['detection'] = $cronProbe;
$cronRepair['verification'] = $cronProbe;
$cronRepair['actions'] = [[
    'type' => 'deduplicate_crontab_entry',
    'marker' => 'MicroSysAdmin Doctor CRON-0004',
    'description' => 'Keep the first exact Doctor-managed Laravel scheduler entry and remove only later entries with the same permanent marker.',
]];
$write($cronRepairPath, $json($cronRepair));
$cronCheckPath = $find('cron', 'Checks', 'CRON-0005');
$cronCheck = json_decode((string) file_get_contents($cronCheckPath), true, 512, JSON_THROW_ON_ERROR);
$cronCheck['probe'] = $cronProbe;
$write($cronCheckPath, $json($cronCheck));
$cronDiagnosisPath = $find('cron', 'Diagnoses', 'CRON-0005');
$cronDiagnosis = json_decode((string) file_get_contents($cronDiagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$cronDiagnosis['diagnosis']['expected_state'] = 'At most one crontab entry contains the exact permanent marker # MicroSysAdmin Doctor CRON-0004.';
$cronDiagnosis['diagnosis']['automation_allowed'] = true;
$cronDiagnosis['diagnosis']['automation_reason'] = 'Only duplicate entries carrying the exact Doctor-owned marker are removed; the complete crontab is captured and hash-guarded rollback restores it.';
$cronDiagnosis['repair_generation'] = [
    'repair_id' => 'CRON-0005',
    'name' => 'Remove duplicate Laravel scheduler entries',
    'automation_class' => 'AUTO_WITH_BACKUP',
    'risk_level' => 'LOW',
    'required_commands' => ['crontab'],
    'backup_strategy' => 'complete service-account crontab snapshot with post-repair hash guard',
    'action' => $cronRepair['actions'][0],
    'commercial_tier' => 'professional',
    'support_issue_family' => 'laravel-scheduler',
];
$write($cronDiagnosisPath, $json($cronDiagnosis));

$symlinkProbe = [
    'type' => 'symlink_wrong_target',
    'path' => '{PROJECT}/public/storage',
    'target' => '{PROJECT}/storage/app/public',
];
$filesystemRepairPath = $find('filesystem', 'Repairs', 'FILESYSTEM-0009');
$filesystemRepair = json_decode((string) file_get_contents($filesystemRepairPath), true, 512, JSON_THROW_ON_ERROR);
$filesystemRepair['metadata']['automation_class'] = 'AUTO_WITH_BACKUP';
$filesystemRepair['metadata']['default_execution_policy'] = 'safe-with-backup';
$filesystemRepair['metadata']['risk_level'] = 'LOW';
$filesystemRepair['metadata']['rollback_supported'] = true;
$filesystemRepair['metadata']['backup_strategy'] = 'exact pre-repair symlink-target capture with current-target rollback guard';
$filesystemRepair['metadata']['files_modified'] = ['{PROJECT}/public/storage'];
$filesystemRepair['detection'] = $symlinkProbe;
$filesystemRepair['verification'] = $symlinkProbe;
$filesystemRepair['actions'] = [[
    'type' => 'repair_symlink',
    'path' => '{PROJECT}/public/storage',
    'target' => '{PROJECT}/storage/app/public',
    'description' => 'Replace only an existing non-escaping project symlink that points away from the exact Laravel public-storage target.',
]];
$write($filesystemRepairPath, $json($filesystemRepair));
$filesystemCheckPath = $find('filesystem', 'Checks', 'FILESYSTEM-0009');
$filesystemCheck = json_decode((string) file_get_contents($filesystemCheckPath), true, 512, JSON_THROW_ON_ERROR);
$filesystemCheck['probe'] = $symlinkProbe;
$write($filesystemCheckPath, $json($filesystemCheck));
$filesystemDiagnosisPath = $find('filesystem', 'Diagnoses', 'FILESYSTEM-0009');
$filesystemDiagnosis = json_decode((string) file_get_contents($filesystemDiagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$filesystemDiagnosis['diagnosis']['expected_state'] = 'An existing public/storage symlink points to the exact managed storage/app/public directory; an absent link is owned by LARAVEL-0009.';
$filesystemDiagnosis['diagnosis']['automation_allowed'] = true;
$filesystemDiagnosis['diagnosis']['automation_reason'] = 'The repair applies only to an existing project-contained symlink, captures its exact target, rejects escaping links and non-link paths, and verifies both repair and rollback.';
$filesystemDiagnosis['repair_generation'] = [
    'repair_id' => 'FILESYSTEM-0009',
    'name' => 'Repair a broken project public storage link',
    'automation_class' => 'AUTO_WITH_BACKUP',
    'risk_level' => 'LOW',
    'backup_strategy' => 'exact pre-repair symlink-target capture with current-target rollback guard',
    'files_modified' => ['{PROJECT}/public/storage'],
    'action' => $filesystemRepair['actions'][0],
    'commercial_tier' => 'professional',
    'support_issue_family' => 'laravel-storage-link',
];
$write($filesystemDiagnosisPath, $json($filesystemDiagnosis));

$sslRepairPath = $find('ssl', 'Repairs', 'SSL-0005');
$sslRepair = json_decode((string) file_get_contents($sslRepairPath), true, 512, JSON_THROW_ON_ERROR);
$sslRepair['metadata']['automation_class'] = 'AUTO_WITH_BACKUP';
$sslRepair['metadata']['default_execution_policy'] = 'safe-with-backup';
$sslRepair['metadata']['rollback_supported'] = true;
$sslRepair['metadata']['backup_strategy'] = 'privileged helper-owned exact certificate backup with audited hash reference';
$sslRepair['metadata']['files_modified'] = ['/opt/lampp/etc/ssl.crt/server.crt'];
$sslRepair['metadata']['services_affected'] = ['apache'];
$sslRepair['actions'] = [[
    'type' => 'doctor_action',
    'action' => 'renew-local-tls',
    'path' => '/opt/lampp/etc/ssl.crt/server.crt',
    'timeout' => 90,
    'description' => 'Renew the nearing-expiration XAMPP localhost certificate with its existing private key through the scoped helper.',
]];
$sslRepair['backup_paths'] = [];
$write($sslRepairPath, $json($sslRepair));
$sslDiagnosisPath = $find('ssl', 'Diagnoses', 'SSL-0005');
$sslDiagnosis = json_decode((string) file_get_contents($sslDiagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$sslDiagnosis['diagnosis']['expected_state'] = 'The local XAMPP certificate remains valid beyond the 14-day policy window.';
$sslDiagnosis['diagnosis']['automation_allowed'] = true;
$sslDiagnosis['diagnosis']['automation_reason'] = 'The exact local certificate and existing private key are allowlisted; a helper-owned hash-addressed backup supports independently verified byte-exact rollback.';
$sslDiagnosis['repair_generation'] = [
    'repair_id' => 'SSL-0005',
    'name' => 'Renew a TLS certificate nearing expiration',
    'automation_class' => 'AUTO_WITH_BACKUP',
    'risk_level' => 'MEDIUM',
    'required_commands' => ['openssl'],
    'backup_strategy' => 'privileged helper-owned exact certificate backup with audited hash reference',
    'files_modified' => ['/opt/lampp/etc/ssl.crt/server.crt'],
    'services_affected' => ['apache'],
    'action' => $sslRepair['actions'][0],
    'commercial_tier' => 'professional',
    'support_issue_family' => 'local-tls-certificate',
];
$write($sslDiagnosisPath, $json($sslDiagnosis));

$generator = new RepairGenerator();
foreach ([$cronDiagnosisPath, $filesystemDiagnosisPath, $sslDiagnosisPath] as $diagnosisPath) {
    $generator->generate($diagnosisPath, APP_ROOT . '/doctor/modules', true);
}

foreach ([
    'cron' => ['CRON-0005' => ['AUTO_WITH_BACKUP', 'LOW']],
    'filesystem' => ['FILESYSTEM-0009' => ['AUTO_WITH_BACKUP', 'LOW']],
    'ssl' => ['SSL-0005' => ['AUTO_WITH_BACKUP', 'MEDIUM']],
] as $module => $changes) {
    $inventoryPath = APP_ROOT . "/doctor/modules/{$module}/repair-inventory.json";
    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($inventory as &$entry) {
        $id = (string) ($entry['repair_id'] ?? '');
        if (!isset($changes[$id])) {
            continue;
        }
        [$automation, $risk] = $changes[$id];
        $entry['automation_class'] = $automation;
        $entry['risk'] = $risk;
        $entry['automation_feasibility'] = 'HIGH';
        $entry['rollback_feasibility'] = 'SUPPORTED';
        $entry['implementation_status'] = 'IMPLEMENTED';
        $entry['commercial_status'] = 'CONTRACT_READY';
    }
    unset($entry);
    $write($inventoryPath, $json($inventory));
}

$write(APP_ROOT . '/doctor/modules/cron/Docs/CRON-0005.md', <<<'MD'
# CRON-0005: Remove duplicate Laravel scheduler entries

Detects more than one service-account crontab line carrying the exact permanent marker `# MicroSysAdmin Doctor CRON-0004`. It preserves the first marked line and every unrelated crontab line.

The primitive captures the complete pre-repair crontab, installs one exact bounded result, verifies the marker count independently, and restores the snapshot only if the crontab still matches the recorded post-repair hash.
MD
);
$write(APP_ROOT . '/doctor/modules/filesystem/Docs/FILESYSTEM-0009.md', <<<'MD'
# FILESYSTEM-0009: Repair a broken project public storage link

Applies only when `public/storage` is already a symbolic link and points away from the exact managed `storage/app/public` target. Missing links remain owned by `LARAVEL-0009`; regular files and directories are never replaced.

The primitive rejects links escaping the project, captures the exact prior link target, verifies the corrected target independently, and rolls back only while the repaired link still has its recorded target.
MD
);
$write(APP_ROOT . '/doctor/modules/ssl/Docs/SSL-0005.md', <<<'MD'
# SSL-0005: Renew a TLS certificate nearing expiration

Applies to the fixed local XAMPP certificate when less than 14 days of validity remain. The scoped privileged helper uses the existing private key, validates key/certificate matching and the new validity window, and reloads Apache.

Before mutation the helper creates an exact local backup and returns its path plus SHA-256 audit reference. Rollback accepts only that managed namespace and hash, restores the byte-exact certificate, validates its key match, and reloads Apache again.
MD
);

echo "Cycle 002 conversion cohort: CRON-0005, FILESYSTEM-0009, and SSL-0005 are executable with exact rollback contracts.\n";
