<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

$action = $argv[1] ?? 'status';
if (!in_array($action, ['enable', 'disable', 'seed', 'reset', 'status'], true)) {
    fwrite(STDERR, "Usage: php scripts/demo.php enable|disable|seed|reset|status\n");
    exit(64);
}

if ($action === 'enable') {
    $data = SysAdmin\DemoAccount::enable();
    echo "Demonstration mode enabled in Safe profile. Automatic reset: {$data['reset_at']}\n";
    echo 'Login: ' . url('login') . PHP_EOL;
    echo 'Username: ' . SysAdmin\DemoAccount::USERNAME . PHP_EOL;
    echo 'Password: ' . SysAdmin\DemoAccount::PASSWORD . PHP_EOL;
    exit;
}
if ($action === 'disable') {
    SysAdmin\DemoAccount::disable();
    echo "Demonstration mode and admindemo access disabled. Sign in with your private administrator account. The Safe profile remains active.\n";
    exit;
}
if ($action === 'status') {
    echo json_encode([
        'enabled' => SysAdmin\DemoMode::enabled(),
        'profile' => SysAdmin\SecurityProfile::current(),
        'fixture_path' => SysAdmin\DemoMode::path(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

$data = $action === 'reset' ? SysAdmin\DemoMode::reset() : SysAdmin\DemoMode::seed();
echo "Demo data {$action}ed. Automatic reset: {$data['reset_at']}\n";
