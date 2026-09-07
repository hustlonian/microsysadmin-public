<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$arguments = getopt('', [
    'check',
    'admin-username:',
    'admin-email:',
    'admin-password:',
    'security-profile:',
    'base-url:',
    'db-host:',
    'db-port:',
    'db-socket:',
    'db-name:',
    'db-user:',
    'db-password:',
    'xampp-root:',
    'php-binary:',
    'composer-binary:',
    'git-binary:',
    'bash-binary:',
    'mysql-binary:',
    'mysqldump-binary:',
    'document-root:',
    'allowed-root:',
    'storage-root:',
    'private-root:',
    'backup-root:',
    'trash-root:',
    'scripts-root:',
    'workspace-root:',
    'service-driver:',
    'privileged-helper:',
    'terminal-available:',
    'service-user:',
    'service-group:',
    'system-integration:',
    'hub-url:',
    'hub-organization:',
    'hub-token:',
]);

$configPath = APP_ROOT . '/config/config.php';
if (!is_file($configPath)) {
    $prebootstrap = require APP_ROOT . '/config/config.sample.php';
    foreach ([
        'storage-root' => 'storage_root',
        'private-root' => 'private_root',
        'backup-root' => 'backup_root',
        'trash-root' => 'trash_root',
        'scripts-root' => 'script_root',
        'workspace-root' => 'temporary_workspace_root',
    ] as $option => $key) {
        $environmentName = 'MICROSYSADMIN_' . strtoupper(str_replace('-', '_', $option));
        $environmentValue = getenv($environmentName);
        $value = (string) ($arguments[$option] ?? ($environmentValue !== false ? $environmentValue : ''));
        if ($value !== '') {
            $prebootstrap['environment'][$key] = $value;
        }
    }
    $GLOBALS['sysadmin_prebootstrap_config'] = $prebootstrap;
}

require APP_ROOT . '/classes/bootstrap.php';

if (getenv('MICROSYSADMIN_INSTALL_CONTEXT') === '1' && PHP_SAPI === 'cli') {
    set_exception_handler(static function (Throwable $exception): never {
        $message = $exception->getMessage();
        foreach (['MICROSYSADMIN_ADMIN_PASSWORD', 'MICROSYSADMIN_DB_PASSWORD', 'MICROSYSADMIN_DB_ADMIN_PASSWORD'] as $name) {
            $secret = (string) getenv($name);
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }
        fwrite(STDERR, 'Installer detail: ' . $message . PHP_EOL);
        exit(1);
    });
}

$detected = (new SysAdmin\EnvironmentDetector())->detect();
$requiredExtensions = ['pdo', 'pdo_mysql', 'openssl', 'json', 'mbstring', 'zip', 'curl'];
$missingExtensions = array_values(array_filter(
    $requiredExtensions,
    static fn (string $extension): bool => !extension_loaded($extension)
));

if (isset($arguments['check'])) {
    echo json_encode([
        'application_root' => APP_ROOT,
        'config_exists' => (bool) ($GLOBALS['sysadmin_config_exists'] ?? false),
        'detected' => $detected,
        'required_extensions' => $requiredExtensions,
        'missing_extensions' => $missingExtensions,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($missingExtensions === [] ? 0 : 1);
}

if ($missingExtensions !== []) {
    throw new RuntimeException('Missing required PHP extensions: ' . implode(', ', $missingExtensions));
}
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    throw new RuntimeException('MicroSysAdmin requires PHP 8.2 or newer.');
}

if (!($GLOBALS['sysadmin_config_exists'] ?? false)) {
    $password = (string) ($arguments['admin-password'] ?? getenv('MICROSYSADMIN_ADMIN_PASSWORD'));
    SysAdmin\Validator::password($password);
    $profile = strtolower((string) (
        $arguments['security-profile']
        ?? getenv('MICROSYSADMIN_SECURITY_PROFILE')
        ?: 'safe'
    ));
    if (!in_array($profile, ['safe', 'developer', 'advanced'], true)) {
        throw new InvalidArgumentException('Security profile must be safe, developer, or advanced.');
    }

    $sample = require APP_ROOT . '/config/config.sample.php';
    if (!empty($arguments['hub-url']) && !(bool) ($sample['hub']['available'] ?? false)) {
        throw new RuntimeException('Hub enrollment is unavailable in this MicroSysAdmin release.');
    }
    $appKey = bin2hex(random_bytes(32));
    $sample['app']['base_url'] = (string) (
        $arguments['base-url']
        ?? getenv('MICROSYSADMIN_BASE_URL')
        ?: '/microsysadmin'
    );
    $sample['security']['app_key'] = $appKey;
    $sample['security']['allow_default_admin_seed'] = false;
    $sample['database'] = [
        ...$sample['database'],
        'host' => (string) ($arguments['db-host'] ?? getenv('MICROSYSADMIN_DB_HOST') ?: '127.0.0.1'),
        'port' => (int) ($arguments['db-port'] ?? getenv('MICROSYSADMIN_DB_PORT') ?: 3306),
        'socket' => (string) ($arguments['db-socket'] ?? getenv('MICROSYSADMIN_DB_SOCKET') ?: $detected['mysql_socket']),
        'name' => (string) ($arguments['db-name'] ?? getenv('MICROSYSADMIN_DB_NAME') ?: 'microsysadmin'),
        'user' => (string) ($arguments['db-user'] ?? getenv('MICROSYSADMIN_DB_USER') ?: 'root'),
        'password' => (string) ($arguments['db-password'] ?? getenv('MICROSYSADMIN_DB_PASSWORD') ?: ''),
    ];
    $optionValue = static function (string $name, mixed $fallback = '') use ($arguments): mixed {
        $environmentName = 'MICROSYSADMIN_' . strtoupper(str_replace('-', '_', $name));
        $environmentValue = getenv($environmentName);
        return $arguments[$name] ?? (
            $environmentValue !== false && $environmentValue !== '' ? $environmentValue : $fallback
        );
    };
    $optionList = static function (string $name, array $fallback) use ($arguments): array {
        $value = $arguments[$name] ?? null;
        if ($value === null || $value === false || $value === '') {
            return $fallback;
        }
        $values = is_array($value) ? $value : [$value];
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => rtrim(trim((string) $item), DIRECTORY_SEPARATOR),
            $values
        ))));
    };
    $xamppRoot = (string) $optionValue('xampp-root', $detected['xampp_root']);
    $sample['environment'] = [
        ...$sample['environment'],
        'xampp_root' => $xamppRoot,
        'php_binary' => (string) $optionValue('php-binary', $detected['php_binary']),
        'composer_binary' => (string) $optionValue('composer-binary', $detected['composer_binary']),
        'mysql_binary' => (string) $optionValue('mysql-binary', $detected['mysql_binary']),
        'mysqldump_binary' => (string) $optionValue('mysqldump-binary', $detected['mysqldump_binary']),
        'git_binary' => (string) $optionValue('git-binary', $detected['git_binary']),
        'bash_binary' => (string) $optionValue('bash-binary', $detected['bash_binary']),
        'ffmpeg_binary' => $detected['ffmpeg_binary'],
        'document_roots' => $optionList('document-root', $detected['document_roots']),
        'allowed_filesystem_roots' => $optionList('allowed-root', $detected['allowed_filesystem_roots']),
        'storage_root' => (string) $optionValue('storage-root', $sample['environment']['storage_root']),
        'private_root' => (string) $optionValue('private-root', $sample['environment']['private_root']),
        'backup_root' => (string) $optionValue('backup-root', $sample['environment']['backup_root']),
        'trash_root' => (string) $optionValue('trash-root', $sample['environment']['trash_root']),
        'script_root' => (string) $optionValue('scripts-root', $sample['environment']['script_root']),
        'temporary_workspace_root' => (string) $optionValue('workspace-root', $sample['environment']['temporary_workspace_root']),
        'service_control_driver' => (string) $optionValue('service-driver', $detected['service_control_driver']),
        'privileged_helper_path' => (string) $optionValue(
            'privileged-helper',
            $sample['environment']['privileged_helper_path']
        ),
        'terminal_available' => (string) $optionValue(
            'terminal-available',
            $profile !== 'safe' ? '1' : '0'
        ) === '1',
    ];
    $documentRoots = $sample['environment']['document_roots'];
    $allowedRoots = $sample['environment']['allowed_filesystem_roots'];
    $storageRoot = (string) $sample['environment']['storage_root'];
    $serviceDriver = (string) $sample['environment']['service_control_driver'];
    $sample['paths']['default_file_root'] = (string) ($documentRoots[0] ?? dirname(APP_ROOT));
    $sample['paths']['storage_scope_root'] = (string) ($allowedRoots[0] ?? dirname(APP_ROOT));
    $sample['paths']['allowed_roots'] = $allowedRoots;
    $sample['paths']['website_roots'] = $documentRoots;
    $sample['paths']['backup_root'] = (string) $sample['environment']['backup_root'];
    $sample['paths']['log_files'] = $serviceDriver === 'xampp'
        ? [
            'Apache error' => $xamppRoot . '/logs/error_log',
            'Apache access' => $xamppRoot . '/logs/access_log',
            'PHP error' => $xamppRoot . '/logs/php_error_log',
            'MySQL error' => $xamppRoot . '/var/mysql/*.err',
            'MicroSysAdmin' => $storageRoot . '/logs/*.log',
        ]
        : [
            'Apache error' => '/var/log/apache2/error.log',
            'Apache access' => '/var/log/apache2/access.log',
            'PHP error' => '/var/log/apache2/error.log',
            'MariaDB error' => '/var/log/mysql/error.log',
            'System messages' => '/var/log/syslog',
            'MicroSysAdmin' => $storageRoot . '/logs/*.log',
        ];
    $sample['hosting']['php_binary'] = (string) $sample['environment']['php_binary'];
    $sample['hosting']['composer_binary'] = (string) $sample['environment']['composer_binary'];
    $sample['installation']['security_profile'] = $profile;
    $sample['hub']['enabled'] = (bool) ($sample['hub']['available'] ?? false)
        && !empty($arguments['hub-url']);
    $sample['hub']['url'] = (string) ($arguments['hub-url'] ?? '');
    $sample['hub']['organization_id'] = (string) ($arguments['hub-organization'] ?? '');

    $configuration = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($sample, true) . ";\n";
    $temporary = APP_ROOT . '/config/config.php.installing';
    if (file_put_contents($temporary, $configuration, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write MicroSysAdmin configuration.');
    }
    chmod($temporary, 0640);
    if (!rename($temporary, APP_ROOT . '/config/config.php')) {
        throw new RuntimeException('Unable to install MicroSysAdmin configuration.');
    }

    $GLOBALS['sysadmin_config_exists'] = true;
    $GLOBALS['sysadmin_config'] = $sample;
    SysAdmin\Database::init($sample);
    $userId = SysAdmin\Models\User::create(
        (string) ($arguments['admin-username'] ?? getenv('MICROSYSADMIN_ADMIN_USERNAME') ?: 'admin'),
        (string) ($arguments['admin-email'] ?? getenv('MICROSYSADMIN_ADMIN_EMAIL') ?: 'admin@localhost'),
        $password,
        'Administrator'
    );
    $generatedAdministrator = getenv('MICROSYSADMIN_ADMIN_PASSWORD_GENERATED') === '1';
    SysAdmin\Database::pdo()->prepare(
        'UPDATE users SET force_password_change = ? WHERE id = ?'
    )->execute([$generatedAdministrator ? 1 : 0, $userId]);
    SysAdmin\CommercialBootstrap::seed();
} else {
    SysAdmin\Database::migrate();
    SysAdmin\Database::seed();
    if ((int) SysAdmin\Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        throw new RuntimeException(
            'Configuration exists but no Administrator is present. Re-run with a secure administrator password after moving the incomplete config aside.'
        );
    }
}

$serviceUser = (string) ($arguments['service-user'] ?? $detected['service_user'] ?? 'daemon');
$serviceGroup = (string) ($arguments['service-group'] ?? $detected['service_group'] ?? $serviceUser);
$protectedDirectories = [
    SysAdmin\EnvironmentConfig::path('storage_root', STORAGE_PATH),
    SysAdmin\EnvironmentConfig::path('private_root', '/var/lib/microsysadmin'),
    SysAdmin\EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups'),
    SysAdmin\EnvironmentConfig::path('trash_root', STORAGE_PATH . '/cache/trash'),
    SysAdmin\EnvironmentConfig::path('script_root', STORAGE_PATH . '/scripts'),
    SysAdmin\EnvironmentConfig::path('temporary_workspace_root', STORAGE_PATH . '/workspace'),
];
foreach (array_unique($protectedDirectories) as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create protected directory {$directory}.");
    }
    chmod($directory, 0770);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($directory, $serviceUser);
        @chgrp($directory, $serviceGroup);
    }
}
if (is_file(APP_ROOT . '/config/config.php')) {
    chmod(APP_ROOT . '/config/config.php', 0640);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chgrp(APP_ROOT . '/config/config.php', $serviceGroup);
    }
}

$identityService = new SysAdmin\MachineIdentity();
$identity = $identityService->get();
$identityPath = $identityService->path();
if (is_file($identityPath)) {
    chmod($identityPath, 0640);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chgrp($identityPath, $serviceGroup);
    }
}
$agentCredential = null;
if (!(new SysAdmin\AgentCredentialService())->hasActive()) {
    $agentCredential = (new SysAdmin\AgentCredentialService())->rotate();
}

if (!empty($arguments['hub-url'])) {
    $hubToken = (string) ($arguments['hub-token'] ?? '');
    if ($hubToken === '') {
        throw new InvalidArgumentException('A hub enrollment token is required when a hub URL is supplied.');
    }
    (new SysAdmin\HttpHubClient())->enroll(
        (string) $arguments['hub-url'],
        (string) ($arguments['hub-organization'] ?? ''),
        $hubToken
    );
}

$systemMode = strtolower((string) ($arguments['system-integration'] ?? 'preview'));
if (!in_array($systemMode, ['preview', 'install', 'skip'], true)) {
    throw new InvalidArgumentException('System integration mode must be preview, install, or skip.');
}
$systemFiles = [];
if ($systemMode !== 'skip') {
    $integration = new SysAdmin\SystemIntegrationManager();
    $systemFiles = $integration->render(
        STORAGE_PATH . '/generated/system-integration',
        $serviceUser,
        $serviceGroup
    );
    if ($systemMode === 'install') {
        $integration->install($systemFiles);
    }
}

$health = (new SysAdmin\HealthCheckService())->all();
$loginUrl = rtrim((string) config_value('app.base_url', '/microsysadmin'), '/') . '/login';
echo "MicroSysAdmin installation is initialized.\n";
echo "Machine UUID: {$identity['machine_uuid']}\n";
echo "Login URL: {$loginUrl}\n";
echo "Security profile: " . SysAdmin\SecurityProfile::current() . "\n";
echo "System integration: {$systemMode}\n";
if ($systemFiles !== []) {
    echo "Rendered system files: " . dirname($systemFiles['worker']) . "\n";
}
if ($agentCredential) {
    echo "Agent key ID: {$agentCredential['key_id']}\n";
    echo "Agent credential generated and encrypted locally; plaintext is not written to installer output.\n";
}
echo "Health status: {$health['status']}\n";
$criticalFailures = array_filter(
    $health['checks'],
    static fn (array $check): bool => !$check['ok'] && (
        in_array($check['name'], ['application', 'database'], true)
        || str_starts_with((string) $check['name'], 'environment.')
    )
);
exit($criticalFailures === [] ? 0 : 1);
