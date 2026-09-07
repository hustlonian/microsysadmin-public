#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\sdk\Generation\RepairGenerator;

$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $temporary = dirname($path) . '/.' . basename($path) . '.cycle-005-' . bin2hex(random_bytes(5));
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
    $paths = glob(APP_ROOT . "/doctor/modules/security/{$folder}/{$id}-*.json") ?: [];
    if (count($paths) !== 1) {
        throw new RuntimeException("Expected one {$folder} definition for {$id}.");
    }
    return $paths[0];
};

$probe = ['type' => 'directory_exists', 'path' => '/opt/lampp/etc/ssl.key'];
$checkPath = $find('Checks', 'SECURITY-0003');
$check = json_decode((string) file_get_contents($checkPath), true, 512, JSON_THROW_ON_ERROR);
$check['supports'] = ['primary_web_server' => ['xampp']];
$check['probe'] = $probe;
$write($checkPath, $json($check));

$diagnosisPath = $find('Diagnoses', 'SECURITY-0003');
$diagnosis = json_decode((string) file_get_contents($diagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$diagnosis['diagnosis']['expected_state'] = 'The fixed XAMPP installation contains a real private-key storage directory.';
$diagnosis['diagnosis']['automation_allowed'] = true;
$diagnosis['diagnosis']['automation_reason'] = 'Only the fixed missing /opt/lampp/etc/ssl.key directory is created through an allowlisted helper token; rollback validates device, inode, mode, UID, and GID and removes it only while empty.';
$diagnosis['repair_generation'] = [
    'repair_id' => 'SECURITY-0003',
    'name' => 'Restore missing XAMPP private-key storage',
    'automation_class' => 'AUTO_WITH_BACKUP',
    'risk_level' => 'LOW',
    'backup_strategy' => 'exact helper-created directory identity with empty-directory rollback guard',
    'directories_modified' => ['/opt/lampp/etc/ssl.key'],
    'required_permissions' => ['scoped privileged helper access to the fixed XAMPP private-key directory'],
    'supported_web_servers' => ['XAMPP/LAMPP'],
    'action' => [
        'type' => 'create_known_directory',
        'target' => 'xampp-ssl-key',
        'path' => '/opt/lampp/etc/ssl.key',
        'timeout' => 60,
        'description' => 'Create only the fixed XAMPP private-key storage directory through the allowlisted helper token.',
    ],
    'commercial_tier' => 'professional',
    'support_issue_family' => 'xampp-private-key-directory',
];
$write($diagnosisPath, $json($diagnosis));

(new RepairGenerator())->generate($diagnosisPath, APP_ROOT . '/doctor/modules', true);

$inventoryPath = APP_ROOT . '/doctor/modules/security/repair-inventory.json';
$inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
foreach ($inventory as &$entry) {
    if (($entry['repair_id'] ?? null) !== 'SECURITY-0003') {
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

$write(APP_ROOT . '/doctor/modules/security/Docs/SECURITY-0003.md', <<<'MD'
# SECURITY-0003: Restore missing XAMPP private-key storage

Applies only when XAMPP is the primary web server, its fixed installation and `etc` roots already exist, and `/opt/lampp/etc/ssl.key` is absent. The structured action carries the allowlisted `xampp-ssl-key` token and its exact audited path; arbitrary privileged paths and recursive parent creation are impossible.

The helper creates one directory and returns its device, inode, mode, UID, and GID. Rollback requires that complete identity, refuses symbolic or non-directory targets, refuses a directory after any key or other content appears, removes only the exact empty directory created by this repair, and independently verifies absence.
MD
);

echo "Cycle 005 conversion cohort: SECURITY-0003 is executable through an exact nested privileged-directory contract.\n";
