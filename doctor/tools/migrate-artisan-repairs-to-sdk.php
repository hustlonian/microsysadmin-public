#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

$migrations = [
    'LARAVEL-0010' => 'config:clear',
    'PERFORMANCE-0008' => 'config:cache',
];
$effect = '{PROJECT}/bootstrap/cache/config.php';
$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $temporary = dirname($path) . '/.' . basename($path) . '.artisan-sdk-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write Artisan SDK migration {$path}.");
    }
    chmod($temporary, 0644);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install Artisan SDK migration {$path}.");
    }
};

foreach ($migrations as $id => $command) {
    $matches = glob(APP_ROOT . '/doctor/modules/*/Repairs/' . $id . '-*.json') ?: [];
    if (count($matches) !== 1) {
        throw new RuntimeException("Artisan SDK migration expected one repair definition for {$id}.");
    }
    $path = $matches[0];
    $definition = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $actions = (array) ($definition['actions'] ?? []);
    if (count($actions) !== 1 || !in_array((string) ($actions[0]['type'] ?? ''), ['run_command', 'artisan_command'], true)) {
        throw new RuntimeException("Artisan SDK migration refuses unexpected action shape for {$id}.");
    }
    $definition['actions'] = [[
        'type' => 'artisan_command',
        'command' => $command,
        'effects' => [$effect],
        'timeout' => 120,
        'description' => (string) ($actions[0]['description'] ?? "Execute {$command}."),
    ]];
    $definition['backup_paths'] = [$effect];
    $definition['metadata']['files_read'] = array_values(array_unique([...(array) ($definition['metadata']['files_read'] ?? []), $effect]));
    $definition['metadata']['files_modified'] = [$effect];
    $definition['metadata']['backup_strategy'] = 'capture the exact pre-repair Laravel config-cache file and remove a hash-matching created file on rollback';
    $definition['metadata']['rollback_supported'] = true;
    $definition['metadata']['repair_version'] = '1.1.0';
    if (($definition['metadata']['automation_class'] ?? '') === 'AUTO_SAFE') {
        $definition['metadata']['automation_class'] = 'AUTO_WITH_BACKUP';
        $definition['metadata']['default_execution_policy'] = 'safe-with-backup';
    }
    $write($path, $json($definition));

    $inventoryPath = dirname(dirname($path)) . '/repair-inventory.json';
    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($inventory as &$entry) {
        if (($entry['repair_id'] ?? null) === $id) {
            $entry['automation_class'] = $definition['metadata']['automation_class'];
            $entry['rollback_feasibility'] = 'AUTOMATIC_FROM_FILE_SNAPSHOT_OR_HASH_GUARD';
            $entry['test_status'] = 'SDK_CONTRACT_TESTED';
            break;
        }
    }
    unset($entry);
    $write($inventoryPath, $json($inventory));
}

echo "Artisan SDK migrations: 2 repairs; LARAVEL-0010, PERFORMANCE-0008.\n";
