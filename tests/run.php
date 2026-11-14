<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';
SysAdmin\Database::seed();

use SysAdmin\AgentCredentialService;
use SysAdmin\AgentRequestAuthenticator;
use SysAdmin\AgentSigner;
use SysAdmin\ApiTokenService;
use SysAdmin\CommercialContext;
use SysAdmin\Csrf;
use SysAdmin\Database;
use SysAdmin\DemoMachineAgent;
use SysAdmin\DemoMode;
use SysAdmin\EnvironmentDetector;
use SysAdmin\HealthCheckService;
use SysAdmin\HttpException;
use SysAdmin\HttpHubClient;
use SysAdmin\HubManager;
use SysAdmin\JobQueue;
use SysAdmin\JobWorker;
use SysAdmin\LicenseManager;
use SysAdmin\LocalLicenseService;
use SysAdmin\MachineIdentity;
use SysAdmin\MigrationRunner;
use SysAdmin\MysqlService;
use SysAdmin\OrganizationAuthorizer;
use SysAdmin\ProcessExecutor;
use SysAdmin\SecurityProfile;
use SysAdmin\UpdateEntitlementPolicy;
use SysAdmin\UpdateManager;
use SysAdmin\WebhookSigner;
use SysAdmin\Contracts\HubClientInterface;
use SysAdmin\Contracts\LicensingServiceInterface;
use SysAdmin\Models\Setting;

final class TestLicense implements LicensingServiceInterface
{
    public function __construct(private readonly array $values, private readonly bool $available = true)
    {
    }

    public function status(): array
    {
        return [
            'available' => $this->available,
            'entitlements' => $this->values,
            'essential_local_access' => true,
            'remote_kill_switch' => false,
        ];
    }

    public function entitlement(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function allows(string $key): bool
    {
        $value = $this->entitlement($key, false);
        return $value === true || in_array((string) $value, ['1', 'true', 'unlimited'], true);
    }

    public function assert(string $key): void
    {
        if (!$this->allows($key)) {
            throw new HttpException(403, 'Entitlement denied.');
        }
    }
}

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function test(string $name, callable $callback): void
    {
        try {
            $callback();
            $this->passed++;
            echo "[PASS] {$name}\n";
        } catch (Throwable $exception) {
            $this->failed++;
            $this->failures[] = ['test' => $name, 'error' => $exception->getMessage()];
            echo "[FAIL] {$name}: {$exception->getMessage()}\n";
        }
    }

    public function assert(bool $condition, string $message = 'Assertion failed.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function finish(): never
    {
        echo "\n{$this->passed} passed, {$this->failed} failed\n";
        if ($this->failed > 0) {
            echo json_encode($this->failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
        exit($this->failed === 0 ? 0 : 1);
    }
}

$test = new TestRunner();

// Integration tests use a local synthetic key when an installation has none configured.
if (empty($GLOBALS['sysadmin_config']['security']['app_key'])) {
    $GLOBALS['sysadmin_config']['security']['app_key'] = bin2hex(random_bytes(32));
}

$test->test('installer detection is idempotent', function () use ($test): void {
    $executor = new ProcessExecutor();
    $first = $executor->run([PHP_BINARY, APP_ROOT . '/scripts/install-production.php', '--check'], 30, APP_ROOT);
    $second = $executor->run([PHP_BINARY, APP_ROOT . '/scripts/install-production.php', '--check'], 30, APP_ROOT);
    $test->assert($first['ok'] && $second['ok'], 'Installer check failed.');
    $one = json_decode((string) $first['output'], true);
    $two = json_decode((string) $second['output'], true);
    $test->assert(is_array($one) && is_array($two), 'Installer check did not return JSON.');
    $test->assert(
        !empty($one['detected']['detected_at']) && !empty($two['detected']['detected_at']),
        'Detection timestamp is missing.'
    );
    unset($one['detected']['detected_at'], $two['detected']['detected_at']);
    $test->assert($one['detected'] === $two['detected'], 'Repeated detection changed.');
});

$test->test('environment detection returns portable operation paths', function () use ($test): void {
    $detected = (new EnvironmentDetector())->detect();
    foreach (['operating_system', 'architecture', 'php_binary', 'mysql_binary', 'git_binary', 'document_roots', 'service_control_driver'] as $key) {
        $test->assert(array_key_exists($key, $detected), "Detection is missing {$key}.");
    }
    $test->assert(is_array($detected['document_roots']), 'Document roots must be an array.');
});

$test->test('database browser defaults to a populated table', function () use ($test): void {
    $tables = [
        ['name' => 'first_empty', 'rows_count' => 0],
        ['name' => 'first_populated', 'rows_count' => 12],
        ['name' => 'second_populated', 'rows_count' => 30],
    ];
    $test->assert(
        MysqlService::preferredTable($tables) === 'first_populated',
        'Database browser selected an empty table while populated tables were available.'
    );
    $test->assert(
        MysqlService::preferredTable([
            ['name' => 'empty_fallback', 'rows_count' => 0],
            ['name' => 'also_empty', 'rows_count' => 0],
        ]) === 'empty_fallback',
        'Database browser did not preserve a stable fallback for an empty database.'
    );
});

$test->test('beta customer defaults are fail-closed and release-key backed', function () use ($test): void {
    $version = trim((string) file_get_contents(APP_ROOT . '/VERSION'));
    $sample = require APP_ROOT . '/config/config.sample.php';
    $publicKeyFile = APP_ROOT . '/config/release-public-key.pub';
    $publicKeyPem = trim((string) file_get_contents($publicKeyFile));

    $test->assert($version === '1.1.0-beta.2', 'Beta version is inconsistent.');
    $test->assert(($sample['updates']['channel'] ?? null) === 'beta', 'Beta update channel is not the default.');
    $test->assert(($sample['updates']['public_key'] ?? null) === $publicKeyPem, 'Embedded update key differs from the tracked public key.');
    $test->assert(openssl_pkey_get_public($publicKeyPem) !== false, 'Embedded release public key is invalid.');
    $test->assert(($sample['licensing']['development_mode'] ?? true) === false, 'Customer package enables Development licensing.');
    $test->assert(($sample['updates']['allow_unsigned_development'] ?? true) === false, 'Customer package permits unsigned updates.');
    $test->assert(($sample['hub']['available'] ?? true) === false, 'Customer package makes hub connectivity available.');
    $test->assert(($sample['hub']['enabled'] ?? true) === false, 'Customer package enables hub connectivity.');
    $test->assert(($sample['installation']['security_profile'] ?? null) === 'safe', 'Safe profile is not the customer default.');
    $test->assert(($sample['environment']['privileged_helper_path'] ?? 'enabled') === '', 'Customer package configures a privileged helper.');
    $test->assert(($sample['environment']['terminal_available'] ?? true) === false, 'Customer package exposes terminal capability.');
    $test->assert(($sample['security']['terminal_enabled'] ?? true) === false, 'Customer package enables the browser terminal.');
    $test->assert(($sample['agent']['allow_terminal'] ?? true) === false, 'Customer package enables agent terminal access.');
    $test->assert(($sample['app']['debug'] ?? true) === false, 'Customer package enables debug mode.');

    $hubBlocked = false;
    try {
        (new HttpHubClient())->enroll(
            'https://hub.example.test',
            'org_beta',
            'enrollment-token-value'
        );
    } catch (HttpException $exception) {
        $hubBlocked = $exception->statusCode() === 403;
    }
    $test->assert($hubBlocked, 'Unavailable beta hub enrollment did not fail closed.');

    $signingKeyPath = trim((string) getenv('MICROSYSADMIN_RELEASE_SIGNING_KEY_FILE'));
    if ($signingKeyPath !== '') {
        $realKeyPath = realpath($signingKeyPath);
        $test->assert(
            is_string($realKeyPath) && !str_starts_with($realKeyPath, APP_ROOT . DIRECTORY_SEPARATOR),
            'Release private key is inside the source tree.'
        );
        $privateKey = openssl_pkey_get_private((string) file_get_contents($signingKeyPath));
        $details = $privateKey !== false ? openssl_pkey_get_details($privateKey) : false;
        $test->assert(
            $privateKey !== false
                && is_array($details)
                && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA,
            'Configured release private key is invalid.'
        );
        $derivedPublicKey = openssl_pkey_get_public((string) ($details['key'] ?? ''));
        $trackedPublicKey = openssl_pkey_get_public($publicKeyPem);
        $derivedDetails = $derivedPublicKey !== false ? openssl_pkey_get_details($derivedPublicKey) : false;
        $trackedDetails = $trackedPublicKey !== false ? openssl_pkey_get_details($trackedPublicKey) : false;
        $test->assert(
            is_array($derivedDetails)
                && is_array($trackedDetails)
                && hash_equals(
                    hash('sha256', (string) ($derivedDetails['key'] ?? '')),
                    hash('sha256', (string) ($trackedDetails['key'] ?? ''))
                ),
            'Configured release private key does not match the embedded public key.'
        );
        $payload = 'MicroSysAdmin 1.1.0-beta.1 embedded-key verification';
        $signature = '';
        $test->assert(
            openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256),
            'Beta signing test failed.'
        );
        $test->assert(
            openssl_verify($payload, $signature, $trackedPublicKey, OPENSSL_ALGO_SHA256) === 1,
            'Embedded public key did not verify the beta test signature.'
        );
    }
});

$test->test('machine identity persists independently of hostname', function () use ($test): void {
    $first = (new MachineIdentity())->get();
    $second = (new MachineIdentity())->get();
    $test->assert($first['machine_uuid'] === $second['machine_uuid'], 'Machine UUID changed.');
    $test->assert($first['installation_uuid'] === $second['installation_uuid'], 'Installation UUID changed.');
    $test->assert((bool) preg_match('/^[0-9a-f-]{36}$/', (string) $first['machine_uuid']), 'Machine UUID is malformed.');
});

$test->test('authorization allows current organization and rejects another', function () use ($test): void {
    $organizationId = CommercialContext::organizationId();
    $test->assert(is_int($organizationId) && $organizationId > 0, 'Organization context is missing.');
    OrganizationAuthorizer::requireOrganization($organizationId);
    try {
        OrganizationAuthorizer::requireOrganization($organizationId + 100000);
        throw new RuntimeException('Cross-organization access was accepted.');
    } catch (HttpException $exception) {
        $test->assert($exception->statusCode() === 403, 'Wrong isolation status.');
    }
});

$test->test('organization queries expose an enforceable scope clause', function () use ($test): void {
    [$clause, $bindings] = OrganizationAuthorizer::scopeClause('machines.organization_id');
    $test->assert($clause === 'machines.organization_id = ?', 'Scope clause is unsafe.');
    $test->assert($bindings === [CommercialContext::organizationId()], 'Scope binding is incorrect.');
});

$test->test('entitlements are data-driven', function () use ($test): void {
    $status = (new LocalLicenseService())->status();
    $test->assert(isset($status['entitlements']['machine.max']), 'machine.max is missing.');
    $test->assert(isset($status['entitlements']['machines.max']), 'machines.max is missing.');
    $test->assert(array_key_exists('terminal.enabled', $status['entitlements']), 'terminal.enabled is missing.');
    $test->assert(array_key_exists('code_runner.enabled', $status['entitlements']), 'code_runner.enabled is missing.');
    $test->assert(array_key_exists('notifications.enabled', $status['entitlements']), 'notifications.enabled is missing.');
    $test->assert($status['essential_local_access'] === true, 'Essential local access is not preserved.');
    $test->assert($status['remote_kill_switch'] === false, 'A remote kill switch was enabled.');
});

$test->test('expired update entitlement keeps installed access but denies newer releases', function () use ($test): void {
    $license = new TestLicense(['updates.until' => '2025-12-31']);
    $policy = new UpdateEntitlementPolicy($license);
    $test->assert($policy->allows('2025-12-01'), 'Entitled release was rejected.');
    $test->assert(!$policy->allows('2026-01-01'), 'Post-entitlement release was accepted.');
    $test->assert($license->status()['essential_local_access'] === true, 'Installed access was removed.');
});

$test->test('licensing outage fails closed for premium features and open for essential access', function () use ($test): void {
    $license = new TestLicense([
        'terminal.enabled' => false,
        'hub.enabled' => false,
        'updates.until' => false,
    ], false);
    $test->assert(!$license->allows('terminal.enabled'), 'Terminal failed open.');
    $test->assert(!$license->allows('hub.enabled'), 'Hub failed open.');
    $test->assert($license->status()['essential_local_access'] === true, 'Essential local access failed closed.');
});

$test->test('agent HMAC authentication and replay protection work', function () use ($test): void {
    $credentials = new AgentCredentialService();
    $createdCredential = false;
    if (!$credentials->hasActive()) {
        $issued = $credentials->rotate();
        $createdCredential = true;
    } else {
        $identity = (new MachineIdentity())->get();
        $row = Database::pdo()->query(
            'SELECT key_id FROM agent_credentials WHERE revoked_at IS NULL ORDER BY id DESC LIMIT 1'
        )->fetch();
        $active = $credentials->active((string) $row['key_id'], (string) $identity['machine_uuid']);
        $issued = [
            'key_id' => (string) $row['key_id'],
            'secret' => (string) $active['secret'],
            'machine_uuid' => (string) $identity['machine_uuid'],
        ];
    }
    $path = '/agent/v1/machine/metadata';
    $body = '{}';
    $timestamp = (string) time();
    $requestId = 'agt_test_' . bin2hex(random_bytes(8));
    $_SERVER['HTTP_X_MICROSYSADMIN_MACHINE'] = $issued['machine_uuid'];
    $_SERVER['HTTP_X_MICROSYSADMIN_KEY_ID'] = $issued['key_id'];
    $_SERVER['HTTP_X_MICROSYSADMIN_REQUEST_ID'] = $requestId;
    $_SERVER['HTTP_X_MICROSYSADMIN_TIMESTAMP'] = $timestamp;
    $_SERVER['HTTP_X_MICROSYSADMIN_SIGNATURE'] = AgentSigner::sign(
        $issued['secret'], 'POST', $path, $timestamp, $requestId, $body
    );
    $authenticated = (new AgentRequestAuthenticator())->authenticate('POST', $path, $body);
    $test->assert($authenticated['request_id'] === $requestId, 'Signed request was rejected.');
    try {
        (new AgentRequestAuthenticator())->authenticate('POST', $path, $body);
        throw new RuntimeException('Agent replay was accepted.');
    } catch (HttpException $exception) {
        $test->assert($exception->statusCode() === 409, 'Replay returned the wrong status.');
    }
    Database::pdo()->prepare('DELETE FROM agent_requests WHERE request_id = ?')->execute([$requestId]);
    if ($createdCredential) {
        Database::pdo()->prepare('DELETE FROM agent_credentials WHERE key_id = ?')->execute([$issued['key_id']]);
    }
});

$test->test('hub enrollment abstraction is swappable without UI changes', function () use ($test): void {
    $fake = new class implements HubClientInterface {
        private bool $connected = false;
        public function enabled(): bool { return true; }
        public function status(): array { return ['status' => $this->connected ? 'connected' : 'disconnected']; }
        public function enroll(string $url, string $organizationId, string $token): array
        {
            $this->connected = $url !== '' && $organizationId !== '' && strlen($token) >= 16;
            return $this->status();
        }
        public function test(): array { return ['ok' => $this->connected]; }
        public function rotateCredential(): array { return ['ok' => $this->connected]; }
        public function heartbeat(): array { return ['ok' => $this->connected]; }
        public function disconnect(): void { $this->connected = false; }
    };
    HubManager::useClient($fake);
    $result = HubManager::client()->enroll('https://hub.example.test', 'org_test', 'enrollment-token-value');
    $test->assert($result['status'] === 'connected', 'Enrollment contract failed.');
    HubManager::client()->disconnect();
});

$test->test('heartbeat filtering sends identifiers only when all shares are off', function () use ($test): void {
    $method = new ReflectionMethod(HttpHubClient::class, 'heartbeatPayload');
    $payload = $method->invoke(new HttpHubClient(), []);
    $test->assert(array_keys($payload) === ['machine_id', 'version', 'sent_at'], 'Unapproved heartbeat fields leaked.');
    $serialized = strtolower(json_encode($payload, JSON_UNESCAPED_SLASHES));
    foreach (['password', 'private_key', 'credential', 'source', 'terminal', 'sql'] as $forbidden) {
        $test->assert(!str_contains($serialized, $forbidden), "Heartbeat leaked {$forbidden}.");
    }
});

$test->test('update checksum, development signature policy, and rollback syntax verify', function () use ($test): void {
    $package = tempnam(sys_get_temp_dir(), 'microsysadmin-update-test-');
    file_put_contents($package, 'verified package fixture');
    $manifest = [
        'version' => '9.9.9-dev',
        'channel' => 'development',
        'released_at' => gmdate(DATE_ATOM),
        'sha256' => hash_file('sha256', $package),
        'signature' => 'development-unsigned',
    ];
    $oldUnsigned = $GLOBALS['sysadmin_config']['updates']['allow_unsigned_development'] ?? false;
    $oldDevelopmentMode = $GLOBALS['sysadmin_config']['licensing']['development_mode'] ?? false;
    try {
        $GLOBALS['sysadmin_config']['updates']['allow_unsigned_development'] = true;
        $GLOBALS['sysadmin_config']['licensing']['development_mode'] = true;
        $verified = (new UpdateManager())->verifyPackage($manifest, $package);
        $test->assert($verified['verified'] === true, 'Update package did not verify.');

        $GLOBALS['sysadmin_config']['updates']['allow_unsigned_development'] = false;
        try {
            (new UpdateManager())->verifyPackage($manifest, $package);
            throw new RuntimeException('Unsigned development update bypassed policy.');
        } catch (RuntimeException $exception) {
            $test->assert(
                $exception->getMessage() === 'Update signature is invalid.',
                'Unsigned development rejection returned the wrong error.'
            );
        }

        $syntax = (new ProcessExecutor())->run(['/bin/bash', '-n', APP_ROOT . '/rollback.sh'], 10, APP_ROOT);
        $test->assert($syntax['ok'], 'Rollback script has invalid shell syntax.');
    } finally {
        $GLOBALS['sysadmin_config']['updates']['allow_unsigned_development'] = $oldUnsigned;
        $GLOBALS['sysadmin_config']['licensing']['development_mode'] = $oldDevelopmentMode;
        @unlink($package);
    }
});

$test->test('RSA-SHA256 update signatures verify and reject tampering', function () use ($test): void {
    $package = tempnam(sys_get_temp_dir(), 'microsysadmin-signed-update-');
    file_put_contents($package, 'signed release fixture');
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($privateKey === false) {
        throw new RuntimeException('Unable to generate the RSA test key.');
    }
    $details = openssl_pkey_get_details($privateKey);
    $publicKey = (string) ($details['key'] ?? '');
    $privatePem = '';
    if (!openssl_pkey_export($privateKey, $privatePem)) {
        throw new RuntimeException('Unable to export the RSA test key.');
    }
    $keyFile = tempnam(sys_get_temp_dir(), 'microsysadmin-signing-key-');
    $manifestPath = tempnam(sys_get_temp_dir(), 'microsysadmin-signed-manifest-');
    file_put_contents($keyFile, $privatePem, LOCK_EX);
    $generated = (new ProcessExecutor())->run([
        PHP_BINARY,
        APP_ROOT . '/scripts/release-manifest.php',
        '--version=9.9.8-beta.1',
        '--channel=beta',
        '--released-at=2026-07-27T14:00:00Z',
        '--sha256=' . hash_file('sha256', $package),
        '--package-name=microsysadmin-9.9.8-beta.1.tar.gz',
        '--output=' . $manifestPath,
        '--signing-key-file=' . $keyFile,
    ], 30, APP_ROOT);
    $test->assert($generated['ok'], 'Release manifest generator could not sign with RSA.');
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    $test->assert($manifest['signature_algorithm'] === 'RSA-SHA256', 'Wrong manifest signature algorithm.');
    $signature = base64_decode((string) $manifest['signature'], true);
    $test->assert(is_string($signature) && $signature !== '', 'Generated signature is empty.');
    $oldKey = $GLOBALS['sysadmin_config']['updates']['public_key'] ?? '';
    $oldUnsigned = $GLOBALS['sysadmin_config']['updates']['allow_unsigned_development'] ?? false;
    try {
        $GLOBALS['sysadmin_config']['updates']['public_key'] = $publicKey;
        $GLOBALS['sysadmin_config']['updates']['allow_unsigned_development'] = false;
        $verified = (new UpdateManager())->verifyPackage($manifest, $package);
        $test->assert($verified['verified'] === true, 'Signed release was rejected.');
        file_put_contents($package, 'modified signed release fixture', LOCK_EX);
        try {
            (new UpdateManager())->verifyPackage($manifest, $package);
            throw new RuntimeException('Modified signed package was accepted.');
        } catch (RuntimeException $exception) {
            $test->assert(
                str_contains($exception->getMessage(), 'checksum verification failed'),
                'Modified package failed for the wrong reason.'
            );
        }
        file_put_contents($package, 'signed release fixture', LOCK_EX);
        $manifest['signature'] = base64_encode(random_bytes(strlen($signature)));
        try {
            (new UpdateManager())->verifyPackage($manifest, $package);
            throw new RuntimeException('Tampered signature was accepted.');
        } catch (RuntimeException $exception) {
            $test->assert(
                str_contains($exception->getMessage(), 'signature verification failed'),
                'Tampered signature failed for the wrong reason.'
            );
        }
    } finally {
        $GLOBALS['sysadmin_config']['updates']['public_key'] = $oldKey;
        $GLOBALS['sysadmin_config']['updates']['allow_unsigned_development'] = $oldUnsigned;
        @unlink($package);
        @unlink($keyFile);
        @unlink($manifestPath);
    }
});

$test->test('CSRF validation and critical release health gating work', function () use ($test): void {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    $test->assert(Csrf::valid($_SESSION['_csrf']), 'Valid CSRF token was rejected.');
    $test->assert(!Csrf::valid('not-the-token'), 'Invalid CSRF token was accepted.');
    $service = new HealthCheckService();
    $operationalFailure = $service->releaseGate(['checks' => [
        ['name' => 'application', 'ok' => true],
        ['name' => 'database', 'ok' => true],
        ['name' => 'storage_space', 'ok' => true],
        ['name' => 'scheduler', 'ok' => true],
        ['name' => 'agent', 'ok' => true],
        ['name' => 'service.apache', 'ok' => false],
    ]]);
    $test->assert($operationalFailure['ok'], 'An intentionally stopped service blocked rollback.');
    $criticalFailure = $service->releaseGate(['checks' => [
        ['name' => 'application', 'ok' => true],
        ['name' => 'database', 'ok' => true],
        ['name' => 'storage_space', 'ok' => true],
        ['name' => 'scheduler', 'ok' => false],
        ['name' => 'agent', 'ok' => true],
    ]]);
    $test->assert(!$criticalFailure['ok'], 'A missing scheduler passed the release gate.');
});

$test->test('database-backed job worker executes and records progress', function () use ($test): void {
    $queue = new JobQueue();
    $job = $queue->dispatch('maintenance.health-check', [], null, 60, 1);
    $test->assert((new JobWorker())->runOnce(), 'Worker did not reserve a job.');
    $completed = $queue->find((string) $job['uuid']);
    $test->assert($completed['status'] === 'completed', 'Health-check job did not complete.');
    $test->assert((int) $completed['progress'] === 100, 'Job progress was not finalized.');
    Database::pdo()->prepare('DELETE FROM jobs WHERE uuid = ?')->execute([$job['uuid']]);
});

$test->test('API tokens are hashed, scoped, expirable, and revocable', function () use ($test): void {
    $service = new ApiTokenService();
    $user = Database::pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch();
    $test->assert((int) ($user['id'] ?? 0) > 0, 'An owner is required for API token testing.');
    $previousUserId = $_SESSION['user_id'] ?? null;
    $_SESSION['user_id'] = (int) $user['id'];
    $created = $service->create('Automated test token', ['version:read'], date('c', time() + 3600), 12);
    $row = Database::pdo()->query('SELECT token_hash FROM api_tokens WHERE id = ' . (int) $created['id'])->fetch();
    $test->assert($row['token_hash'] !== $created['token'], 'API token was stored in plaintext.');
    $authenticated = $service->authenticate($created['token']);
    $service->requireScope($authenticated, 'version:read');
    try {
        $service->requireScope($authenticated, 'backups:write');
        throw new RuntimeException('Out-of-scope API access was accepted.');
    } catch (HttpException $exception) {
        $test->assert($exception->statusCode() === 403, 'Scope denial returned the wrong status.');
    }
    $service->revoke((int) $created['id']);
    try {
        $service->authenticate($created['token']);
        throw new RuntimeException('Revoked API token was accepted.');
    } catch (HttpException $exception) {
        $test->assert($exception->statusCode() === 401, 'Revocation returned the wrong status.');
    }
    Database::pdo()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([(int) $created['id']]);
    if ($previousUserId === null) {
        unset($_SESSION['user_id']);
    } else {
        $_SESSION['user_id'] = $previousUserId;
    }
});

$test->test('webhook signatures include timestamp and reject replay-age violations', function () use ($test): void {
    $secret = bin2hex(random_bytes(16));
    $timestamp = (string) time();
    $body = '{"event":"backup.completed"}';
    $signature = WebhookSigner::sign($secret, $timestamp, $body);
    $test->assert(WebhookSigner::verify($secret, $timestamp, $body, $signature), 'Valid webhook signature failed.');
    $test->assert(!WebhookSigner::verify($secret, (string) (time() - 1000), $body, $signature), 'Old webhook replay was accepted.');
});

$test->test('demo agent isolates all mutation requests from the real host', function () use ($test): void {
    DemoMode::seed();
    $result = (new DemoMachineAgent())->execute('files.delete', ['path' => '/etc/passwd']);
    $test->assert($result['simulated'] === true, 'Demo mutation reached a real driver.');
    $test->assert(is_file('/etc/passwd'), 'Host fixture unexpectedly disappeared.');
    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $oldPost = $_POST;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'delete', 'path' => '/etc/passwd'];
        $apiResult = DemoMode::api('file');
        $test->assert(($apiResult['data']['simulated'] ?? false) === true, 'Legacy demo API was not simulated.');
    } finally {
        $_POST = $oldPost;
        if ($oldMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $oldMethod;
        }
    }
    $applicationSource = (string) file_get_contents(APP_ROOT . '/classes/Application.php');
    $schedulerSource = (string) file_get_contents(APP_ROOT . '/cron/runner.php');
    $test->assert(
        str_contains($applicationSource, 'Workspace preview is disabled in demonstration mode.'),
        'Demo workspace preview guard is missing.'
    );
    $test->assert(
        str_contains($schedulerSource, 'Demo fixtures checked'),
        'Demo scheduler guard is missing.'
    );
});

$test->test('Safe profile blocks dangerous capabilities', function () use ($test): void {
    $settings = Setting::all();
    $hadSetting = array_key_exists('security_profile', $settings);
    $old = $settings['security_profile'] ?? null;
    try {
        Setting::set('security_profile', 'safe');
        foreach (['terminal', 'code_runner', 'sql_import', 'privileged_helper', 'service_mutation'] as $capability) {
            $test->assert(!SecurityProfile::allows($capability), "Safe mode allowed {$capability}.");
        }
    } finally {
        if ($hadSetting) {
            Setting::set('security_profile', (string) $old);
        } else {
            Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['security_profile']);
        }
    }
});

$test->test('current-install migration is idempotent and preserves customer records', function () use ($test): void {
    $tables = ['users', 'audit_logs', 'scheduled_tasks', 'script_runs', 'hosting_sites', 'backup_jobs', 'metric_samples'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = (int) Database::pdo()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    Database::migrate();
    Database::seed();
    foreach ($before as $table => $count) {
        $after = (int) Database::pdo()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $test->assert($after === $count, "Migration changed {$table} record count.");
    }
    $test->assert((new MigrationRunner(Database::pdo()))->pending() === [], 'Migration remains pending.');
});

$test->test('published migration source cleanup remains compatible with existing installs', function () use ($test): void {
    $migration = '202607270001_commercial_foundation.php';
    $legacyChecksum = '2a0f6c7b445477770643679ee60c4f18d11e4dbf8e610b6fca3f42cf7360d686';
    $pdo = Database::pdo();
    $select = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ? LIMIT 1');
    $select->execute([$migration]);
    $originalChecksum = $select->fetchColumn();
    $test->assert(is_string($originalChecksum), 'Published migration is not recorded as applied.');

    $update = $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE migration = ?');
    try {
        $update->execute([$legacyChecksum, $migration]);
        (new MigrationRunner($pdo))->migrate();
        $select->execute([$migration]);
        $test->assert(
            hash_equals($legacyChecksum, (string) $select->fetchColumn()),
            'Compatible migration cleanup rewrote the applied migration record.'
        );
    } finally {
        if (is_string($originalChecksum)) {
            $update->execute([$originalChecksum, $migration]);
        }
    }
});

$test->test('owner setup stores secrets encrypted and keeps service states fail-closed', function () use ($test): void {
    $key = 'owner_setup.configuration.v1';
    $old = Setting::all()[$key] ?? null;
    $service = new \SysAdmin\OwnerSetupService();
    try {
        Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        $initial = $service->overview();
        $test->assert($initial['hosting']['state'] === 'HOSTING_NOT_CONFIGURED', 'Hosting did not start unconfigured.');
        $test->assert($initial['stripe']['test_state'] === 'TEST_CREDENTIALS_REQUIRED', 'Stripe test mode did not start gated.');
        $test->assert($initial['stripe']['live_payments_enabled'] === false, 'Live payments were not disabled by default.');

        $hostingSecret = 'synthetic-scoped-host-token';
        $hosting = $service->saveHosting([
            'provider' => 'cpanel', 'environment' => 'shared', 'domain' => 'example.test',
            'application_url' => 'https://example.test/app', 'deployment_method' => 'sftp',
            'deploy_credential' => $hostingSecret,
        ]);
        $test->assert($hosting['state'] === 'HOSTING_CONFIGURED_NOT_VERIFIED', 'Saving credentials implied a verified host.');
        $test->assert(!array_key_exists('deploy_credential', $hosting), 'Hosting secret was returned to the caller.');

        $mailSecret = 'synthetic-email-provider-token';
        $email = $service->saveEmail([
            'provider' => 'smtp', 'owner_email' => 'owner@example.test', 'sender_email' => 'noreply@example.test',
            'sender_name' => 'Synthetic Test Sender', 'reply_to' => 'support@example.test',
            'additional_recipients' => 'admin@example.test', 'provider_credential' => $mailSecret,
        ]);
        $test->assert($email['state'] === 'EMAIL_CONFIGURED_NOT_TESTED', 'Saving email credentials implied successful delivery.');
        $test->assert($email['configuration']['owner_email'] === 'owner@example.test', 'Owner notification address was not retained.');
        $test->assert(!array_key_exists('provider_credential', $email), 'Email provider secret was returned to the caller.');

        $stripeSecret = 'sk_test_synthetic_key_12345678';
        $stripe = $service->saveStripeCredentials('test', $stripeSecret, 'whsec_synthetic_12345678');
        $test->assert($stripe['test_state'] === 'TEST_CREDENTIALS_SAVED', 'Stripe test credential state was not recorded.');
        $test->assert($stripe['live_payments_enabled'] === false, 'Test configuration enabled live payments.');

        $hostingReceipt = $service->verifyHostingCompatibility('host-ci-check-0001', static function (array $configuration, string $credential, string $requestId) use ($hostingSecret): array {
            if (($configuration['provider'] ?? '') !== 'cpanel' || $credential !== $hostingSecret || $requestId !== 'host-ci-check-0001') {
                throw new RuntimeException('Unexpected hosting verification inputs.');
            }
            return ['php' => true, 'database' => true, 'scheduler' => true, 'queue' => true, 'ssl' => true, 'backup' => true, 'restore' => true];
        });
        $test->assert($hostingReceipt['status'] === 'HOSTING_COMPATIBILITY_VERIFIED', 'Hosting verification receipt was not persisted.');
        $hostingReplay = $service->verifyHostingCompatibility('host-ci-check-0001', static fn (): never => throw new RuntimeException('Hosting replay invoked adapter.'));
        $test->assert($hostingReplay['replayed'] === true, 'Hosting verification replay was not deduplicated.');

        $delivery = $service->verifyEmailDelivery('mail-ci-check-0001', static function (array $configuration, string $credential, string $requestId) use ($mailSecret): array {
            if (($configuration['owner_email'] ?? '') !== 'owner@example.test' || $credential !== $mailSecret || $requestId !== 'mail-ci-check-0001') {
                throw new RuntimeException('Unexpected email verification inputs.');
            }
            return ['status' => 'DELIVERED', 'provider_message_id' => 'synthetic-ci-message-0001'];
        });
        $test->assert($delivery['status'] === 'DELIVERED', 'Email delivery receipt was not persisted.');
        try {
            $service->verifyEmailDelivery('mail-ci-unknown-0001', static fn (): never => throw new RuntimeException('Synthetic interrupted delivery.'));
            $test->assert(false, 'Interrupted email delivery was accepted.');
        } catch (RuntimeException $exception) {
            $test->assert(str_contains($exception->getMessage(), 'outcome is unknown'), 'Interrupted email was not marked unknown.');
        }
        $reconciled = $service->reconcileEmailDelivery('mail-ci-unknown-0001', 'NOT_DELIVERED');
        $test->assert($reconciled['status'] === 'NOT_DELIVERED_RECONCILED', 'Interrupted email could not be reconciled safely.');

        $eventId = 'evt_test_ci_0001';
        $payload = json_encode(['id' => $eventId, 'livemode' => false], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = \SysAdmin\WebhookSigner::sign('whsec_synthetic_12345678', $timestamp, $payload);
        $webhookReceipt = $service->verifyStripeTestWebhook($eventId, $timestamp, $payload, $signature);
        $test->assert($webhookReceipt['status'] === 'TEST_WEBHOOK_VERIFIED', 'Stripe test webhook receipt was not persisted.');
        $webhookReplay = $service->verifyStripeTestWebhook($eventId, $timestamp, $payload, $signature);
        $test->assert($webhookReplay['replayed'] === true, 'Stripe test webhook replay was not deduplicated.');
        try {
            $service->verifyStripeTestWebhook($eventId, $timestamp, $payload, 'v1=' . str_repeat('0', 64));
            $test->assert(false, 'Stripe replay with invalid signature was accepted.');
        } catch (RuntimeException $exception) {
            $test->assert(str_contains($exception->getMessage(), 'signature'), 'Stripe invalid-signature rejection was unclear.');
        }

        $service->saveStripeCredentials('live', 'sk_live_synthetic_key_12345678');
        $live = $service->overview()['stripe'];
        $test->assert($live['live_state'] === 'LIVE_CREDENTIALS_SAVED_OWNER_ACTIVATION_REQUIRED', 'Live key did not remain owner-gated.');
        $test->assert($live['live_payments_enabled'] === false, 'Saving a live key enabled charges.');

        $stored = (string) Setting::all()[$key];
        $test->assert(!str_contains($stored, $hostingSecret) && !str_contains($stored, $mailSecret) && !str_contains($stored, $stripeSecret), 'A provider secret was stored in plaintext.');
        $test->assert(str_contains($stored, 'v1:'), 'Encrypted secret payload marker was not persisted.');
        $test->assert(!str_contains(json_encode($service->overview()), $stripeSecret), 'A Stripe secret appeared in owner status.');

        $invalidModeRejected = false;
        try { $service->saveStripeCredentials('live', 'sk_test_synthetic_key_12345678'); }
        catch (InvalidArgumentException) { $invalidModeRejected = true; }
        $test->assert($invalidModeRejected, 'Stripe test key was accepted as a live key.');
    } finally {
        if ($old === null) Database::pdo()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        else Setting::set($key, (string) $old);
    }
});

LicenseManager::useService(new LocalLicenseService());
$test->finish();
