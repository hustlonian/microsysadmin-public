<?php

declare(strict_types=1);

$appRoot = rtrim((string) getenv('MICROSYSADMIN_ISOLATED_APP_ROOT'), DIRECTORY_SEPARATOR);
$projectsRoot = rtrim((string) getenv('MICROSYSADMIN_ISOLATED_PROJECTS_ROOT'), DIRECTORY_SEPARATOR);
$storageRoot = rtrim((string) getenv('MICROSYSADMIN_ISOLATED_STORAGE_ROOT'), DIRECTORY_SEPARATOR);
$privateRoot = rtrim((string) getenv('MICROSYSADMIN_ISOLATED_PRIVATE_ROOT'), DIRECTORY_SEPARATOR);
$releaseRoot = rtrim((string) getenv('MICROSYSADMIN_ISOLATED_RELEASE_ROOT'), DIRECTORY_SEPARATOR);
$adminPassword = (string) getenv('MICROSYSADMIN_ISOLATED_ADMIN_PASSWORD');

foreach ([
    'application root' => $appRoot,
    'projects root' => $projectsRoot,
    'storage root' => $storageRoot,
    'private root' => $privateRoot,
    'release root' => $releaseRoot,
] as $label => $path) {
    if ($path === '' || !str_starts_with($path, '/tmp/microsysadmin-isolated.')) {
        fwrite(STDERR, "Unsafe or missing isolated {$label}.\n");
        exit(64);
    }
}
if (!is_file($appRoot . '/config/config.php') || strlen($adminPassword) < 12) {
    fwrite(STDERR, "The isolated installation or test password is missing.\n");
    exit(64);
}

$configPath = $appRoot . '/config/config.php';
$config = require $configPath;
$customerDefaults = $config;
$config['licensing']['development_mode'] = true;
$config['updates']['channel'] = 'development';
$config['updates']['allow_unsigned_development'] = true;
$config['updates']['release_root'] = $releaseRoot;
$temporaryConfig = $configPath . '.test-' . bin2hex(random_bytes(4));
$configuration = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($temporaryConfig, $configuration, LOCK_EX) === false) {
    throw new RuntimeException('Unable to prepare isolated update configuration.');
}
chmod($temporaryConfig, 0640);
if (!rename($temporaryConfig, $configPath)) {
    throw new RuntimeException('Unable to install isolated update configuration.');
}

define('APP_ROOT', $appRoot);
require APP_ROOT . '/classes/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
});

use SysAdmin\Auth;
use SysAdmin\BackupService;
use SysAdmin\Csrf;
use SysAdmin\Database;
use SysAdmin\EnvironmentConfig;
use SysAdmin\HealthCheckService;
use SysAdmin\JobQueue;
use SysAdmin\JobWorker;
use SysAdmin\LicenseManager;
use SysAdmin\MachineIdentity;
use SysAdmin\MfaRecoveryService;
use SysAdmin\RateLimitException;
use SysAdmin\RateLimiter;
use SysAdmin\SecurityProfile;
use SysAdmin\SystemIntegrationManager;
use SysAdmin\TotpService;
use SysAdmin\Models\Setting;
use SysAdmin\Models\User;

final class IsolatedAssertions
{
    private array $passed = [];

    public function same(mixed $expected, mixed $actual, string $name): void
    {
        $this->true($expected === $actual, $name . ' (expected ' . var_export($expected, true) . ', received ' . var_export($actual, true) . ')');
    }

    public function true(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new RuntimeException($name);
        }
        $this->passed[] = $name;
    }

    public function result(): array
    {
        return $this->passed;
    }
}

$assert = new IsolatedAssertions();
Database::migrate();
Database::seed();

$config = require $configPath;
$assert->same(false, (bool) ($customerDefaults['licensing']['development_mode'] ?? true), 'customer package disables Development licensing');
$assert->same(false, (bool) ($customerDefaults['updates']['allow_unsigned_development'] ?? true), 'customer package rejects unsigned updates');
$assert->same('beta', (string) ($customerDefaults['updates']['channel'] ?? ''), 'customer package selects beta updates');
$assert->true(
    openssl_pkey_get_public((string) ($customerDefaults['updates']['public_key'] ?? '')) !== false,
    'customer package embeds a valid release public key'
);
$assert->same(false, (bool) ($customerDefaults['hub']['available'] ?? true), 'customer package disables hub availability');
$assert->same(false, (bool) ($customerDefaults['hub']['enabled'] ?? true), 'customer package disables hub connectivity');
$assert->same('safe', (string) ($customerDefaults['installation']['security_profile'] ?? ''), 'customer package defaults to Safe profile');
$assert->same('', (string) ($customerDefaults['environment']['privileged_helper_path'] ?? 'enabled'), 'customer package disables privileged helper');
$assert->same(false, (bool) ($customerDefaults['environment']['terminal_available'] ?? true), 'customer package disables terminal capability');
$assert->same(false, (bool) ($customerDefaults['security']['terminal_enabled'] ?? true), 'customer package disables browser terminal');
$assert->same(false, (bool) ($customerDefaults['agent']['allow_terminal'] ?? true), 'customer package disables agent terminal');
$assert->same(false, (bool) ($customerDefaults['app']['debug'] ?? true), 'customer package disables debug mode');
$assert->true(strlen((string) $config['security']['app_key']) === 64, 'application key generated');
$assert->same(0640, fileperms($configPath) & 0777, 'configuration permissions are protected');
$assert->same($projectsRoot, EnvironmentConfig::paths('document_roots')[0] ?? null, 'document-root override applied');
$assert->same($storageRoot, EnvironmentConfig::path('storage_root'), 'storage-root override applied');
$assert->same($privateRoot, EnvironmentConfig::path('private_root'), 'private-root override applied');
$assert->same('inspection', EnvironmentConfig::get('service_control_driver'), 'service-control override applied');
$assert->same(false, (bool) EnvironmentConfig::get('terminal_available'), 'terminal availability override applied');

$identityService = new MachineIdentity();
$firstIdentity = $identityService->get();
$secondIdentity = $identityService->get();
$assert->same($firstIdentity['machine_uuid'], $secondIdentity['machine_uuid'], 'machine UUID persists');
$assert->same($firstIdentity['installation_uuid'], $secondIdentity['installation_uuid'], 'installation UUID persists');
$assert->same(0640, fileperms($identityService->path()) & 0777, 'machine identity permissions are protected');

$users = User::all();
$assert->same(1, count($users), 'first Administrator is idempotent');
$administrator = $users[0];
$assert->same('owner', (string) $administrator['username'], 'first Administrator username');
$assert->same('Administrator', (string) $administrator['role'], 'first Administrator role');
$assert->same(0, (int) $administrator['force_password_change'], 'first Administrator is onboarding-ready');

$_SERVER['REMOTE_ADDR'] = '127.0.0.77';
$_SESSION = [];
$login = Auth::loginResult('owner', $adminPassword, false);
$assert->same('success', $login['status'], 'password authentication succeeds');
$assert->same((int) $administrator['id'], Auth::id(), 'authenticated session owns the Administrator');

$secret = (new TotpService())->generateSecret();
User::setMfaSecret((int) $administrator['id'], $secret, true);
$recoveryCodes = (new MfaRecoveryService())->regenerate((int) $administrator['id']);
$_SESSION = [];
$mfaLogin = Auth::loginResult('owner', $adminPassword, false);
$assert->same('mfa_required', $mfaLogin['status'], 'MFA challenge is required after enrollment');
$mfaResult = Auth::completePendingMfa($recoveryCodes[0]);
$assert->same('success', $mfaResult['status'], 'MFA recovery code completes authentication');
$assert->same(9, (new MfaRecoveryService())->remaining((int) $administrator['id']), 'MFA recovery code is single-use');

$license = (new SysAdmin\LocalLicenseService())->status();
$assert->same('development', (string) $license['plan'], 'local development license mode is active');
foreach (['machines.max', 'users.max', 'terminal.enabled', 'code_runner.enabled', 'notifications.enabled', 'updates.until'] as $entitlement) {
    $assert->true(array_key_exists($entitlement, $license['entitlements']), "entitlement {$entitlement} exists");
}

$operatorId = User::create('operator-test', 'operator@example.test', 'Operator-Test-Password-47!', 'Operator');
$viewerId = User::create('viewer-test', 'viewer@example.test', 'Viewer-Test-Password-47!', 'Viewer');
$_SESSION['user_id'] = $operatorId;
$assert->true(Auth::can('backup.create'), 'Operator RBAC allows backup creation');
$assert->true(!Auth::can('users.manage'), 'Operator RBAC denies administration');
$_SESSION['user_id'] = $viewerId;
$assert->true(Auth::can('log.view'), 'Viewer RBAC allows read-only logs');
$assert->true(!Auth::can('file.edit'), 'Viewer RBAC denies file mutation');
User::setRole($viewerId, 'Operator');
$assert->same('Operator', (string) User::find($viewerId)['role'], 'role assignment persists');
$_SESSION['user_id'] = (int) $administrator['id'];
User::setDisabled($viewerId, true);
$assert->same(1, (int) User::find($viewerId)['is_disabled'], 'user disabling persists');
User::setDisabled($viewerId, false);
$oldVersion = (int) User::find($operatorId)['session_version'];
User::updatePassword($operatorId, 'Operator-Reset-Password-84!');
$assert->true(password_verify('Operator-Reset-Password-84!', (string) User::find($operatorId)['password_hash']), 'password reset persists');
Auth::logoutAllForUser($operatorId);
$assert->true((int) User::find($operatorId)['session_version'] > $oldVersion, 'logout-all revokes existing sessions');

$_SESSION = [];
$csrf = Csrf::token();
$assert->true(Csrf::valid($csrf), 'valid CSRF token is accepted');
$assert->true(!Csrf::valid('invalid-' . $csrf), 'invalid CSRF token is rejected');

$rateSubject = 'isolated-' . bin2hex(random_bytes(6));
RateLimiter::hit('isolated', $rateSubject, 1, 60, 60);
$rateLimited = false;
try {
    RateLimiter::hit('isolated', $rateSubject, 1, 60, 60);
} catch (RateLimitException) {
    $rateLimited = true;
} finally {
    RateLimiter::clear('isolated', $rateSubject);
}
$assert->true($rateLimited, 'rate limiting blocks excess attempts');

Setting::set('security_profile', 'safe');
foreach (['terminal', 'code_runner', 'sql_import', 'privileged_helper', 'service_mutation'] as $capability) {
    $assert->true(!SecurityProfile::allows($capability), "Safe profile blocks {$capability}");
}
Setting::set('theme', 'dark');
Setting::set('timezone', 'UTC');
$settings = Setting::all();
$assert->same('dark', $settings['theme'] ?? null, 'theme selection persists');
$assert->same('UTC', $settings['timezone'] ?? null, 'timezone selection persists');

$source = $projectsRoot . '/backup-source';
$target = $projectsRoot . '/restore-target';
mkdir($source, 0770, true);
mkdir($target, 0770, true);
file_put_contents($source . '/fixture.txt', 'MicroSysAdmin restore fixture', LOCK_EX);
$_SESSION['user_id'] = (int) $administrator['id'];
$backup = (new BackupService())->folder($source);
$restore = (new BackupService())->restore(basename((string) $backup['archive']), $target);
$assert->true($restore['integrity_checked'] === true, 'restore integrity is checked');
$assert->true(is_file((string) $restore['pre_restore_backup']), 'pre-restore backup is created');
$assert->same('MicroSysAdmin restore fixture', (string) file_get_contents($target . '/backup-source/fixture.txt'), 'folder restore recovers data');

$unsafeArchive = EnvironmentConfig::path('backup_root') . '/unsafe.zip';
$zip = new ZipArchive();
$zip->open($unsafeArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('../escaped.txt', 'unsafe');
$zip->close();
$unsafeRejected = false;
try {
    (new BackupService())->restore(basename($unsafeArchive), $target);
} catch (RuntimeException) {
    $unsafeRejected = true;
} finally {
    @unlink($unsafeArchive);
}
$assert->true($unsafeRejected, 'unsafe restore archive is rejected');
$assert->true(!is_file($projectsRoot . '/escaped.txt'), 'unsafe restore cannot escape the restore target');

$queue = new JobQueue();
$job = $queue->dispatch('maintenance.health-check', [], (int) $administrator['id'], 60, 1);
$assert->true((new JobWorker())->runOnce(), 'durable worker reserves a queued job');
$completed = $queue->find((string) $job['uuid']);
$assert->same('completed', (string) $completed['status'], 'durable job completes');
$assert->same(100, (int) $completed['progress'], 'durable job records progress');

$rendered = (new SystemIntegrationManager())->render(
    $storageRoot . '/generated/system-test',
    (string) getenv('MICROSYSADMIN_ISOLATED_SERVICE_USER'),
    (string) getenv('MICROSYSADMIN_ISOLATED_SERVICE_GROUP')
);
$workerUnit = (string) file_get_contents($rendered['worker']);
$assert->true(str_contains($workerUnit, $appRoot), 'system service uses installed application root');
$assert->true(str_contains($workerUnit, EnvironmentConfig::path('php_binary')), 'system service uses configured PHP binary');
$assert->true(!str_contains($workerUnit, '@MICROSYSADMIN_'), 'rendered system service contains no template tokens');

$healthService = new HealthCheckService();
$health = $healthService->all();
$releaseGate = $healthService->releaseGate($health);
$assert->true($releaseGate['ok'], 'critical post-install health gate passes');
$assert->true((int) Database::pdo()->query('SELECT COUNT(*) FROM machines')->fetchColumn() === 1, 'installer creates one machine record');
$assert->true((int) Database::pdo()->query('SELECT COUNT(*) FROM agent_credentials WHERE revoked_at IS NULL')->fetchColumn() === 1, 'installer creates one active agent credential');

echo json_encode([
    'ok' => true,
    'assertions' => count($assert->result()),
    'checks' => $assert->result(),
    'machine_uuid' => $firstIdentity['machine_uuid'],
    'health_status' => $health['status'],
    'release_gate' => $releaseGate,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
