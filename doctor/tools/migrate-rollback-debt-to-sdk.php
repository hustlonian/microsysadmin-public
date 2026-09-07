#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $temporary = dirname($path) . '/.' . basename($path) . '.rollback-sdk-' . bin2hex(random_bytes(5));
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

$composerRepairs = [
    'COMPOSER-0005' => ['module' => 'composer', 'command' => 'install-locked', 'timeout' => 600, 'automation' => 'APPROVAL_REQUIRED'],
    'COMPOSER-0006' => ['module' => 'composer', 'command' => 'dump-autoload-optimized', 'timeout' => 300, 'automation' => 'AUTO_WITH_BACKUP'],
    'LARAVEL-0003' => ['module' => 'laravel', 'command' => 'install-locked', 'timeout' => 600, 'automation' => 'APPROVAL_REQUIRED'],
    'PERFORMANCE-0007' => ['module' => 'performance', 'command' => 'dump-autoload-optimized', 'timeout' => 300, 'automation' => 'AUTO_WITH_BACKUP'],
];

foreach ($composerRepairs as $id => $configuration) {
    $path = $find($configuration['module'], 'Repairs', $id);
    $definition = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $definition['metadata']['automation_class'] = $configuration['automation'];
    $definition['metadata']['default_execution_policy'] = $configuration['automation'] === 'APPROVAL_REQUIRED' ? 'manual' : 'safe-with-backup';
    $definition['metadata']['rollback_supported'] = true;
    $definition['metadata']['backup_strategy'] = 'bounded exact vendor-tree snapshot with manifest-guarded restore or created-tree removal';
    $definition['metadata']['files_read'] = array_values(array_unique([
        ...(array) ($definition['metadata']['files_read'] ?? []),
        '{PROJECT}/composer.json',
        '{PROJECT}/composer.lock',
    ]));
    $definition['metadata']['directories_modified'] = ['{PROJECT}/vendor'];
    $definition['actions'] = [[
        'type' => 'composer_command',
        'command' => $configuration['command'],
        'timeout' => $configuration['timeout'],
        'max_files' => 50000,
        'max_bytes' => 536870912,
        'description' => $configuration['command'] === 'install-locked'
            ? 'Install only locally cached locked dependencies with plugins, scripts, interaction, and network disabled.'
            : 'Regenerate optimized autoload metadata with plugins, scripts, interaction, and network disabled.',
    ]];
    $definition['backup_paths'] = [];
    $write($path, $json($definition));
}

$linuxRepairPath = $find('linux', 'Repairs', 'LINUX-0008');
$linuxRepair = json_decode((string) file_get_contents($linuxRepairPath), true, 512, JSON_THROW_ON_ERROR);
$timeProbe = [
    'type' => 'time_sync_enabled',
    'timeout' => 30,
];
$linuxRepair['metadata']['rollback_supported'] = true;
$linuxRepair['metadata']['backup_strategy'] = 'capture and restore the exact prior boolean NTP-enabled state';
$linuxRepair['metadata']['automation_class'] = 'AUTO_WITH_BACKUP';
$linuxRepair['metadata']['default_execution_policy'] = 'safe-with-backup';
$linuxRepair['detection'] = $timeProbe;
$linuxRepair['verification'] = $timeProbe;
$linuxRepair['actions'] = [[
    'type' => 'time_sync',
    'enabled' => true,
    'timeout' => 60,
    'description' => 'Enable the configured time-synchronization provider through the scoped privileged helper.',
]];
$linuxRepair['audit']['primitive'] = 'time_sync';
$write($linuxRepairPath, $json($linuxRepair));
$linuxCheckPath = $find('linux', 'Checks', 'LINUX-0008');
$linuxCheck = json_decode((string) file_get_contents($linuxCheckPath), true, 512, JSON_THROW_ON_ERROR);
$linuxCheck['probe'] = $timeProbe;
$write($linuxCheckPath, $json($linuxCheck));
$linuxDiagnosisPath = $find('linux', 'Diagnoses', 'LINUX-0008');
$linuxDiagnosis = json_decode((string) file_get_contents($linuxDiagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$linuxDiagnosis['diagnosis']['expected_state'] = 'The operating system reports NTP=yes.';
$linuxDiagnosis['diagnosis']['automation_reason'] = 'The prior boolean NTP state is captured, the privileged mutation is allowlisted, and rollback restores and independently verifies that exact state.';
$write($linuxDiagnosisPath, $json($linuxDiagnosis));

foreach ([
    'PERFORMANCE-0001' => ['module' => 'performance', 'strategy' => 'privileged helper-owned exact PHP configuration backup with audited hash reference'],
    'SSL-0004' => ['module' => 'ssl', 'strategy' => 'privileged helper-owned exact certificate backup with audited hash reference'],
] as $id => $configuration) {
    $path = $find($configuration['module'], 'Repairs', $id);
    $definition = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $definition['metadata']['rollback_supported'] = true;
    $definition['metadata']['backup_strategy'] = $configuration['strategy'];
    $definition['backup_paths'] = [];
    $write($path, $json($definition));
}

$queueRepairPath = $find('queue', 'Repairs', 'QUEUE-0005');
$queueRepair = json_decode((string) file_get_contents($queueRepairPath), true, 512, JSON_THROW_ON_ERROR);
$queueRepair['metadata']['automation_class'] = 'REPORT_ONLY';
$queueRepair['metadata']['default_execution_policy'] = 'manual';
$queueRepair['metadata']['rollback_supported'] = false;
$queueRepair['metadata']['backup_strategy'] = 'not-applicable-temporal-worker-transition';
$queueRepair['actions'] = [];
$queueRepair['backup_paths'] = [];
$queueRepair['commercial']['deterministic'] = null;
$queueRepair['commercial']['bounded'] = null;
$queueRepair['commercial']['reversible'] = null;
$queueRepair['commercial']['independently_verifiable'] = null;
$queueRepair['commercial']['quality_status'] = 'advisory-only';
$write($queueRepairPath, $json($queueRepair));
$queueDiagnosisPath = $find('queue', 'Diagnoses', 'QUEUE-0005');
$queueDiagnosis = json_decode((string) file_get_contents($queueDiagnosisPath), true, 512, JSON_THROW_ON_ERROR);
$queueDiagnosis['diagnosis']['automation_allowed'] = false;
$queueDiagnosis['diagnosis']['automation_reason'] = 'A queue restart is a temporal worker transition: workers that exit and load new code cannot be returned to their exact pre-repair process state.';
$write($queueDiagnosisPath, $json($queueDiagnosis));

$inventoryChanges = [
    'COMPOSER-0005' => ['module' => 'composer', 'automation' => 'APPROVAL_REQUIRED', 'rollback' => 'SUPPORTED', 'status' => 'IMPLEMENTED'],
    'COMPOSER-0006' => ['module' => 'composer', 'automation' => 'AUTO_WITH_BACKUP', 'rollback' => 'SUPPORTED', 'status' => 'IMPLEMENTED'],
    'LARAVEL-0003' => ['module' => 'laravel', 'automation' => 'APPROVAL_REQUIRED', 'rollback' => 'SUPPORTED', 'status' => 'IMPLEMENTED'],
    'LINUX-0008' => ['module' => 'linux', 'automation' => 'AUTO_WITH_BACKUP', 'rollback' => 'SUPPORTED', 'status' => 'IMPLEMENTED'],
    'PERFORMANCE-0001' => ['module' => 'performance', 'automation' => 'AUTO_WITH_BACKUP', 'rollback' => 'SUPPORTED', 'status' => 'IMPLEMENTED'],
    'PERFORMANCE-0007' => ['module' => 'performance', 'automation' => 'AUTO_WITH_BACKUP', 'rollback' => 'SUPPORTED', 'status' => 'IMPLEMENTED'],
    'QUEUE-0005' => ['module' => 'queue', 'automation' => 'REPORT_ONLY', 'rollback' => 'NOT_APPLICABLE_OR_MANUAL', 'status' => 'REPORT_ONLY'],
    'SSL-0004' => ['module' => 'ssl', 'automation' => 'AUTO_WITH_BACKUP', 'rollback' => 'SUPPORTED', 'status' => 'IMPLEMENTED'],
];
foreach (array_unique(array_column($inventoryChanges, 'module')) as $module) {
    $inventoryPath = APP_ROOT . "/doctor/modules/{$module}/repair-inventory.json";
    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($inventory as &$entry) {
        $id = (string) ($entry['repair_id'] ?? '');
        if (($inventoryChanges[$id]['module'] ?? null) !== $module) {
            continue;
        }
        $change = $inventoryChanges[$id];
        $entry['automation_class'] = $change['automation'];
        $entry['rollback_feasibility'] = $change['rollback'];
        $entry['implementation_status'] = $change['status'];
        $entry['commercial_status'] = $change['status'] === 'REPORT_ONLY' ? 'ADVISORY_ONLY' : 'CONTRACT_READY';
    }
    unset($entry);
    $write($inventoryPath, $json($inventory));
}

echo "Migrated seven reversible rollback-debt repairs to semantic SDK primitives and reclassified QUEUE-0005 as advisory-only.\n";
