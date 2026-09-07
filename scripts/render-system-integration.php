<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$options = getopt('', ['output:', 'user:', 'group:', 'install']);
$output = (string) ($options['output'] ?? STORAGE_PATH . '/generated/system-integration');
$user = (string) ($options['user'] ?? 'daemon');
$group = (string) ($options['group'] ?? $user);

$manager = new SysAdmin\SystemIntegrationManager();
$files = $manager->render($output, $user, $group);
$installed = isset($options['install']) ? $manager->install($files) : [];

echo json_encode([
    'mode' => isset($options['install']) ? 'installed' : 'preview',
    'rendered' => $files,
    'installed' => $installed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
