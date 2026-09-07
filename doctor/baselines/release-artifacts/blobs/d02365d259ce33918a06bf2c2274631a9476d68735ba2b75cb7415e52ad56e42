<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

use SysAdmin\HealthCheckService;
use SysAdmin\LicenseManager;
use SysAdmin\SecurityProfile;
use SysAdmin\Models\User;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert((bool) ($GLOBALS['sysadmin_config_exists'] ?? false), 'Production configuration is missing.');
$assert((bool) config_value('app.debug', true) === false, 'Production debug mode is enabled.');
$assert((bool) config_value('licensing.development_mode', true) === false, 'Development licensing is enabled.');
$assert((bool) config_value('updates.allow_unsigned_development', true) === false, 'Unsigned updates are enabled.');
$assert(trim((string) config_value('updates.public_key', '')) !== '', 'Release verification key is missing.');
$assert((bool) config_value('security.terminal_enabled', true) === false, 'Public terminal is enabled.');
$assert((bool) config_value('agent.allow_terminal', true) === false, 'Agent terminal access is enabled.');
$assert(SecurityProfile::current() === (string) config_value('installation.security_profile', 'safe'), 'Security profile is inconsistent.');

$administratorCount = (int) SysAdmin\Database::pdo()->query(
    "SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND is_disabled = 0"
)->fetchColumn();
$assert($administratorCount > 0, 'No active Administrator exists.');

$username = trim((string) getenv('MICROSYSADMIN_ADMIN_USERNAME'));
$password = (string) getenv('MICROSYSADMIN_ADMIN_PASSWORD');
$authenticated = null;
if ($username !== '' && $password !== '') {
    $user = User::findByUsername($username);
    $authenticated = is_array($user)
        && (string) ($user['role'] ?? '') === 'Administrator'
        && password_verify($password, (string) ($user['password_hash'] ?? ''));
    $assert($authenticated, 'Administrator authentication verification failed.');
}

$license = LicenseManager::service()->status();
$assert((string) ($license['plan'] ?? '') !== 'development', 'Development license is active.');
$assert((bool) ($license['essential_local_access'] ?? false), 'Essential local access is unavailable.');
$assert((bool) ($license['remote_kill_switch'] ?? true) === false, 'A remote kill switch is enabled.');

$healthService = new HealthCheckService();
$health = $healthService->all();
$releaseGate = $healthService->releaseGate($health);
$assert($releaseGate['ok'], 'Release-critical health gate failed.');

$result = [
    'ok' => $failures === [],
    'administrator_count' => $administratorCount,
    'administrator_authentication_checked' => $authenticated !== null,
    'administrator_authentication_ok' => $authenticated,
    'security_profile' => SecurityProfile::current(),
    'terminal_enabled' => false,
    'license_plan' => $license['plan'] ?? 'unavailable',
    'release_gate' => $releaseGate,
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
