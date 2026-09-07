#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\sdk\Generation\RepairGenerator;

$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $temporary = dirname($path) . '/.' . basename($path) . '.cycle-004-' . bin2hex(random_bytes(5));
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
$find = static function (string $folder, string $id): string {
    $paths = glob(APP_ROOT . "/doctor/modules/xampp/{$folder}/{$id}-*.json") ?: [];
    if (count($paths) !== 1) {
        throw new RuntimeException("Expected one {$folder} definition for {$id}.");
    }
    return $paths[0];
};

$probe = ['type' => 'directory_exists', 'path' => '/opt/lampp/logs'];
$checkPath = $find('Checks', 'XAMPP-0010');
$check = json_decode((string) file_get_contents($checkPath), true, 512, JSON_THROW_ON_ERROR);
$check['supports'] = ['primary_web_server' => ['xampp']];
$check['probe'] = $probe;
$write($checkPath, $json($check));

$diagnosisPath = $find('Diagnoses', 'XAMPP-0010');
$diagnosis = json_decode((string) file_get_contents($diagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$diagnosis['diagnosis']['expected_state'] = 'The fixed XAMPP installation root contains a real logs directory.';
$diagnosis['diagnosis']['automation_allowed'] = true;
$diagnosis['diagnosis']['automation_reason'] = 'Only the fixed missing /opt/lampp/logs directory is created through an allowlisted helper token; rollback validates device, inode, mode, UID, and GID and removes it only while empty.';
$diagnosis['repair_generation'] = [
    'repair_id' => 'XAMPP-0010',
    'name' => 'Restore the missing XAMPP log directory',
    'automation_class' => 'AUTO_WITH_BACKUP',
    'risk_level' => 'LOW',
    'backup_strategy' => 'exact helper-created directory identity with empty-directory rollback guard',
    'directories_modified' => ['/opt/lampp/logs'],
    'required_permissions' => ['scoped privileged helper access to the fixed XAMPP logs path'],
    'action' => [
        'type' => 'create_known_directory',
        'target' => 'xampp-logs',
        'path' => '/opt/lampp/logs',
        'timeout' => 60,
        'description' => 'Create only the fixed XAMPP logs directory through the allowlisted helper token.',
    ],
    'commercial_tier' => 'professional',
    'support_issue_family' => 'xampp-log-directory',
];
$write($diagnosisPath, $json($diagnosis));

(new RepairGenerator())->generate($diagnosisPath, APP_ROOT . '/doctor/modules', true);

$inventoryPath = APP_ROOT . '/doctor/modules/xampp/repair-inventory.json';
$inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
foreach ($inventory as &$entry) {
    if (($entry['repair_id'] ?? null) !== 'XAMPP-0010') {
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

$write(APP_ROOT . '/doctor/modules/xampp/Docs/XAMPP-0010.md', <<<'MD'
# XAMPP-0010: Restore the missing XAMPP log directory

Applies only when XAMPP is the primary web server, the fixed installation root exists, and `/opt/lampp/logs` is absent. The structured action carries an allowlisted `xampp-logs` token and its exact audited path; arbitrary privileged paths are impossible.

The helper creates one directory without recursive parent creation and returns its device, inode, mode, UID, and GID. Rollback requires that complete identity, refuses symbolic or non-directory targets, refuses any non-empty directory, removes only that exact path, and independently verifies absence.
MD
);

echo "Cycle 004 conversion cohort: XAMPP-0010 is executable through an exact privileged directory contract.\n";
