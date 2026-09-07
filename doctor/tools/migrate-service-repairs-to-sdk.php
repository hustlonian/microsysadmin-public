#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

$migrations = [
    'APACHE-0002' => ['apache', 'start'],
    'CRON-0002' => ['cron', 'start'],
    'CRON-0003' => ['cron', 'enable'],
    'DOCKER-0002' => ['docker', 'start'],
    'DOCKER-0003' => ['docker', 'enable'],
    'MAIL-0004' => ['postfix', 'start'],
    'MAIL-0005' => ['postfix', 'enable'],
    'MYSQL-0002' => ['mysql', 'start'],
    'NGINX-0002' => ['nginx', 'start'],
    'NGINX-0003' => ['nginx', 'enable'],
    'QUEUE-0002' => ['supervisor', 'start'],
    'QUEUE-0003' => ['supervisor', 'enable'],
    'REDIS-0004' => ['redis', 'start'],
    'REDIS-0005' => ['redis', 'enable'],
    'XAMPP-0006' => ['apache', 'start'],
];
$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $temporary = dirname($path) . '/.' . basename($path) . '.service-sdk-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write service SDK migration {$path}.");
    }
    chmod($temporary, 0644);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install service SDK migration {$path}.");
    }
};
$updated = [];
foreach ($migrations as $id => [$service, $operation]) {
    $matches = glob(APP_ROOT . '/doctor/modules/*/Repairs/' . $id . '-*.json') ?: [];
    if (count($matches) !== 1) {
        throw new RuntimeException("Service SDK migration expected one repair definition for {$id}.");
    }
    $path = $matches[0];
    $definition = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $actions = (array) ($definition['actions'] ?? []);
    if (count($actions) !== 1) {
        throw new RuntimeException("Service SDK migration expected one action for {$id}.");
    }
    $currentType = (string) ($actions[0]['type'] ?? '');
    if (!in_array($currentType, ['run_command', 'service_action'], true)) {
        throw new RuntimeException("Service SDK migration refuses unexpected action type {$currentType} for {$id}.");
    }
    $description = (string) ($actions[0]['description'] ?? "Set {$service} {$operation} state.");
    $definition['actions'] = [[
        'type' => 'service_action',
        'service' => $service,
        'action' => $operation,
        'verification_timeout' => 20,
        'description' => $description,
    ]];
    $definition['metadata']['rollback_supported'] = true;
    $definition['metadata']['backup_strategy'] = 'capture the exact pre-repair service ' . (in_array($operation, ['enable', 'disable'], true) ? 'boot-enabled' : 'running') . ' state';
    $definition['metadata']['repair_version'] = '1.1.0';
    $write($path, $json($definition));

    $moduleRoot = dirname(dirname($path));
    $inventoryPath = $moduleRoot . '/repair-inventory.json';
    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($inventory as &$entry) {
        if (($entry['repair_id'] ?? null) === $id) {
            $entry['rollback_feasibility'] = 'AUTOMATIC_FROM_CAPTURED_SERVICE_STATE';
            $entry['test_status'] = 'SDK_CONTRACT_TESTED';
            break;
        }
    }
    unset($entry);
    $write($inventoryPath, $json($inventory));
    $updated[] = $id;
}

echo 'Service SDK migrations: ' . count($updated) . ' repairs; ' . implode(', ', $updated) . ".\n";
