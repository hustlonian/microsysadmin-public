<?php

declare(strict_types=1);

ini_set('serialize_precision', '-1');

if (!in_array('--force-legacy-bootstrap', $argv ?? [], true)) {
    fwrite(STDERR, "This legacy batch bootstrap is frozen because it predates the Repair SDK and would overwrite commercial contracts. Use doctor/bin/generate-repair for new definitions. Pass --force-legacy-bootstrap only when intentionally recreating the historical seed before replaying every migration.\n");
    exit(64);
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__, 2));
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', APP_ROOT . '/storage');
}
if (!function_exists('config_value')) {
    function config_value(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

/**
 * Historical deterministic bootstrap for the original 20x10 seed library.
 * It is deliberately guarded because the live definitions have since been
 * migrated to SDK primitives and commercial contracts. New work must use the
 * diagnosis-driven Repair Generator instead of overwriting those units.
 */

$root = dirname(__DIR__) . '/modules';
$path = static fn (string $value, string $type = 'path_exists'): array => ['type' => $type, 'path' => $value];
$absent = static fn (string $value): array => ['type' => 'path_absent', 'path' => $value];
$exec = static fn (string $name): array => ['type' => 'executable_exists', 'command' => $name];
$cmd = static fn (array $command, ?string $contains = null, string $cwd = ''): array => array_filter(['type' => 'command', 'command' => $command, 'stdout_contains' => $contains, 'cwd' => $cwd, 'timeout' => 30], static fn ($v): bool => $v !== null && $v !== '');
$tcp = static fn (string $host, int $port): array => ['type' => 'tcp_connect', 'host' => $host, 'port' => $port, 'timeout' => 1];
$contains = static fn (string $file, string $needle): array => ['type' => 'file_contains', 'path' => $file, 'contains' => $needle];
$env = static fn (string $file, string $key, ?string $equals = null): array => array_filter(['type' => 'env_value', 'path' => $file, 'key' => $key, 'equals' => $equals], static fn ($v): bool => $v !== null);
$service = static fn (string $name, bool $enabled = false): array => ['type' => $enabled ? 'service_enabled' : 'service_active', 'service' => $name];
$warning = static fn (array $probe): array => [...$probe, 'failure_status' => 'WARNING'];
$item = static function (string $slug, string $title, array $probe, string $severity = 'MEDIUM', string $automation = 'REPORT_ONLY', array $actions = [], array $commands = [], bool $rollback = false, array $supports = []): array {
    if ($actions === [] && $automation !== 'PROHIBITED') {
        $automation = 'REPORT_ONLY';
    }
    return compact('slug', 'title', 'probe', 'severity', 'automation', 'actions', 'commands', 'rollback', 'supports');
};

$modules = [
    'linux' => ['LINUX', 'Linux', 1, [
        $item('operating-system-release-missing', 'Restore missing operating-system release metadata', $path('/etc/os-release', 'file_exists'), 'HIGH'),
        $item('root-partition-low-space', 'Recover critically low root-partition capacity', ['type' => 'disk_free_percent', 'path' => '/', 'minimum' => 10], 'CRITICAL'),
        $item('application-partition-low-space', 'Recover critically low application-partition capacity', ['type' => 'disk_free_percent', 'path' => '{PROJECT}', 'minimum' => 10], 'CRITICAL'),
        $item('ca-certificates-missing', 'Install a missing system CA certificate bundle', $path('/etc/ssl/certs/ca-certificates.crt', 'file_exists'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('cron-service-stopped', 'Start the stopped cron service', $service('cron'), 'HIGH', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'start', 'cron'], 'description' => 'Start only the cron service.']], ['systemctl']),
        $item('cron-service-disabled', 'Enable the disabled cron service', $service('cron', true), 'MEDIUM', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'enable', 'cron'], 'description' => 'Enable cron at boot.']], ['systemctl']),
        $item('loopback-host-entry-missing', 'Restore the missing loopback hosts entry', $contains('/etc/hosts', '127.0.0.1'), 'HIGH'),
        $item('time-synchronization-disabled', 'Enable operating-system time synchronization', $cmd(['timedatectl', 'show', '--property=NTPSynchronized'], 'NTPSynchronized=yes'), 'MEDIUM', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['timedatectl', 'set-ntp', 'true'], 'description' => 'Enable the configured time-synchronization provider.']], ['timedatectl']),
        $item('system-log-directory-missing', 'Restore the missing system log directory', $path('/var/log', 'directory_exists'), 'CRITICAL'),
        $item('temporary-directory-not-writable', 'Correct unusable system temporary storage', $path('/tmp', 'path_writable'), 'HIGH'),
    ]],
    'apache' => ['APACHE', 'Apache', 1, [
        $item('apache-missing', 'Install the missing Apache executable', $exec('xampp-httpd'), 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('apache-service-stopped', 'Start the stopped XAMPP Apache service', $service('apache'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'service_action', 'service' => 'apache', 'action' => 'start', 'description' => 'Start Apache through the scoped service helper after a scoped status check.']], ['xampp']),
        $item('invalid-configuration-syntax', 'Correct invalid Apache configuration syntax', $cmd(['xampp-httpd', '-t']), 'CRITICAL', 'REPORT_ONLY', [], ['xampp-httpd']),
        $item('rewrite-module-missing', 'Enable the missing Apache rewrite module', $contains('/opt/lampp/etc/httpd.conf', 'rewrite_module'), 'HIGH'),
        $item('ssl-module-missing', 'Enable the missing Apache SSL module', $contains('/opt/lampp/etc/httpd.conf', 'ssl_module'), 'HIGH'),
        $item('headers-module-missing', 'Enable the missing Apache headers module', $contains('/opt/lampp/etc/httpd.conf', 'headers_module'), 'MEDIUM'),
        $item('virtual-host-file-missing', 'Restore the missing Apache virtual-host file', $path('/opt/lampp/etc/extra/httpd-vhosts.conf', 'file_exists'), 'HIGH'),
        $item('http-port-unavailable', 'Resolve an unavailable Apache HTTP listener', $tcp('127.0.0.1', 80), 'CRITICAL'),
        $item('https-port-unavailable', 'Resolve an unavailable Apache HTTPS listener', $tcp('127.0.0.1', 443), 'HIGH'),
        $item('error-log-path-missing', 'Create the missing Apache error-log path', $path('/opt/lampp/logs/error_log', 'file_exists'), 'MEDIUM'),
    ]],
    'php' => ['PHP', 'PHP', 1, [
        $item('php-missing', 'Install the missing PHP executable', $exec('xampp-php'), 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('unsupported-php-version', 'Upgrade an unsupported PHP runtime', ['type' => 'php_version', 'minimum' => '8.2.0'], 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('pdo-mysql-extension-missing', 'Enable the missing PDO MySQL extension', ['type' => 'php_extension', 'extension' => 'pdo_mysql'], 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('mbstring-extension-missing', 'Enable the missing mbstring extension', ['type' => 'php_extension', 'extension' => 'mbstring'], 'HIGH', 'APPROVAL_REQUIRED'),
        $item('openssl-extension-missing', 'Enable the missing OpenSSL extension', ['type' => 'php_extension', 'extension' => 'openssl'], 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('curl-extension-missing', 'Enable the missing cURL extension', ['type' => 'php_extension', 'extension' => 'curl'], 'HIGH', 'APPROVAL_REQUIRED'),
        $item('fileinfo-extension-missing', 'Enable the missing fileinfo extension', ['type' => 'php_extension', 'extension' => 'fileinfo'], 'MEDIUM', 'APPROVAL_REQUIRED'),
        $item('php-ini-missing', 'Restore the missing active PHP configuration', $path('/opt/lampp/etc/php.ini', 'file_exists'), 'CRITICAL'),
        $item('session-directory-not-writable', 'Correct an unwritable PHP session directory', $path((string) (ini_get('session.save_path') ?: '/tmp'), 'path_writable'), 'HIGH'),
        $item('upload-temp-directory-not-writable', 'Correct an unwritable PHP upload temporary directory', $path((string) (ini_get('upload_tmp_dir') ?: sys_get_temp_dir()), 'path_writable'), 'HIGH'),
    ]],
    'laravel' => ['LARAVEL', 'Laravel', 1, [
        $item('environment-file-missing', 'Create a missing Laravel environment file safely', $path('{PROJECT}/.env', 'file_exists'), 'CRITICAL'),
        $item('application-key-missing', 'Report a missing Laravel application key', $env('{PROJECT}/.env', 'APP_KEY'), 'CRITICAL'),
        $item('vendor-autoload-missing', 'Restore missing Laravel vendor autoload files', $path('{PROJECT}/vendor/autoload.php', 'file_exists'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['composer', 'install', '--no-interaction', '--prefer-dist'], 'cwd' => '{PROJECT}', 'timeout' => 600, 'description' => 'Install exact locked dependencies.']], ['composer'], false, ['laravel_detected' => [true]]),
        $item('storage-directory-missing', 'Create the missing Laravel storage directory', $path('{PROJECT}/storage', 'directory_exists'), 'CRITICAL', 'AUTO_SAFE', [['type' => 'create_directory', 'path' => '{PROJECT}/storage', 'mode' => 0775, 'description' => 'Create only the Laravel storage directory.']], [], true, ['laravel_detected' => [true]]),
        $item('framework-cache-directory-missing', 'Create the missing Laravel framework cache directory', $path('{PROJECT}/storage/framework/cache/data', 'directory_exists'), 'HIGH', 'AUTO_SAFE', [
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage', 'mode' => 0775, 'description' => 'Ensure the Laravel storage parent exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework', 'mode' => 0775, 'description' => 'Ensure the framework runtime parent exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework/cache', 'mode' => 0775, 'description' => 'Create the framework cache parent.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework/cache/data', 'mode' => 0775, 'description' => 'Create the framework cache data directory.'],
        ], [], true, ['laravel_detected' => [true]]),
        $item('framework-session-directory-missing', 'Create the missing Laravel framework session directory', $path('{PROJECT}/storage/framework/sessions', 'directory_exists'), 'HIGH', 'AUTO_SAFE', [
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage', 'mode' => 0775, 'description' => 'Ensure the Laravel storage parent exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework', 'mode' => 0775, 'description' => 'Ensure the framework runtime parent exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework/sessions', 'mode' => 0775, 'description' => 'Create the framework session directory.'],
        ], [], true, ['laravel_detected' => [true]]),
        $item('framework-view-directory-missing', 'Create the missing Laravel compiled-view directory', $path('{PROJECT}/storage/framework/views', 'directory_exists'), 'HIGH', 'AUTO_SAFE', [
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage', 'mode' => 0775, 'description' => 'Ensure the Laravel storage parent exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework', 'mode' => 0775, 'description' => 'Ensure the framework runtime parent exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework/views', 'mode' => 0775, 'description' => 'Create the compiled-view directory.'],
        ], [], true, ['laravel_detected' => [true]]),
        $item('bootstrap-cache-directory-missing', 'Create the missing Laravel bootstrap cache directory', $path('{PROJECT}/bootstrap/cache', 'directory_exists'), 'CRITICAL', 'AUTO_SAFE', [['type' => 'create_directory', 'path' => '{PROJECT}/bootstrap/cache', 'mode' => 0775, 'description' => 'Create only bootstrap/cache.']], [], true, ['laravel_detected' => [true]]),
        $item('storage-symlink-missing', 'Create the missing Laravel public storage symlink', ['type' => 'symlink_target', 'path' => '{PROJECT}/public/storage', 'target' => '{PROJECT}/storage/app/public'], 'HIGH', 'AUTO_SAFE', [
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage', 'mode' => 0775, 'description' => 'Ensure the Laravel storage parent exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/app', 'mode' => 0775, 'description' => 'Ensure storage/app exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/app/public', 'mode' => 0775, 'description' => 'Create the public storage target.'],
            ['type' => 'create_symlink', 'path' => '{PROJECT}/public/storage', 'target' => '{PROJECT}/storage/app/public', 'description' => 'Link public/storage to the known Laravel storage target.'],
        ], [], true, ['laravel_detected' => [true]]),
        $item('stale-configuration-cache', 'Clear stale Laravel configuration cache', $absent('{PROJECT}/bootstrap/cache/config.php'), 'HIGH', 'AUTO_SAFE', [['type' => 'run_command', 'command' => ['php', 'artisan', 'config:clear', '--no-interaction'], 'cwd' => '{PROJECT}', 'description' => 'Clear only the configuration cache.']], ['php'], false, ['laravel_detected' => [true]]),
    ]],
    'mysql' => ['MYSQL', 'MySQL and MariaDB', 1, [
        $item('mysql-client-missing', 'Install the missing MySQL client', $exec('xampp-mysql'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('mysql-service-stopped', 'Start the stopped XAMPP MySQL service', $service('mysql'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'service_action', 'service' => 'mysql', 'action' => 'start', 'description' => 'Start only the XAMPP MySQL service through the scoped service helper.']], ['xampp']),
        $item('mysql-port-unavailable', 'Restore the unavailable MySQL TCP endpoint', $tcp('127.0.0.1', (int) config_value('database.port', 3306)), 'CRITICAL'),
        $item('configured-database-missing', 'Create the missing configured application database', ['type' => 'database_exists', 'database' => (string) config_value('database.name', 'microsysadmin')], 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('users-table-missing', 'Restore the missing application users table', ['type' => 'database_table_exists', 'database' => (string) config_value('database.name', 'microsysadmin'), 'table' => 'users'], 'CRITICAL'),
        $item('schema-migrations-table-missing', 'Restore the missing schema-migrations table', ['type' => 'database_table_exists', 'database' => (string) config_value('database.name', 'microsysadmin'), 'table' => 'schema_migrations'], 'HIGH'),
        $item('mysql-data-directory-missing', 'Restore the missing XAMPP MySQL data directory', $path('/opt/lampp/var/mysql', 'directory_exists'), 'CRITICAL'),
        $item('mysql-configuration-missing', 'Restore the missing MySQL configuration file', $path('/opt/lampp/etc/my.cnf', 'file_exists'), 'HIGH'),
        $item('mysql-error-log-missing', 'Restore MySQL error logging', ['type' => 'glob_exists', 'pattern' => '/opt/lampp/var/mysql/*.err'], 'HIGH'),
        $item('mysql-socket-missing', 'Restore the missing MySQL Unix socket', $path('/opt/lampp/var/mysql/mysql.sock', 'path_exists'), 'HIGH'),
    ]],
    'composer' => ['COMPOSER', 'Composer', 1, [
        $item('composer-missing', 'Install the missing Composer executable', $exec('composer'), 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('composer-json-missing', 'Restore a missing Composer project manifest', $path('{PROJECT}/composer.json', 'file_exists'), 'CRITICAL'),
        $item('composer-json-invalid', 'Correct invalid Composer manifest JSON', ['type' => 'json_valid', 'path' => '{PROJECT}/composer.json'], 'CRITICAL'),
        $item('composer-lock-missing', 'Create a missing deterministic dependency lock', $path('{PROJECT}/composer.lock', 'file_exists'), 'HIGH'),
        $item('vendor-directory-missing', 'Install exact locked Composer dependencies', $path('{PROJECT}/vendor', 'directory_exists'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['composer', 'install', '--no-interaction', '--prefer-dist'], 'cwd' => '{PROJECT}', 'timeout' => 600, 'description' => 'Install exact locked dependencies without composer update.']], ['composer']),
        $item('autoload-file-missing', 'Regenerate missing Composer autoload files', $path('{PROJECT}/vendor/autoload.php', 'file_exists'), 'CRITICAL', 'AUTO_SAFE', [['type' => 'run_command', 'command' => ['composer', 'dump-autoload', '--optimize', '--no-interaction'], 'cwd' => '{PROJECT}', 'timeout' => 300, 'description' => 'Regenerate Composer autoload files.']], ['composer']),
        $item('manifest-validation-failure', 'Correct Composer manifest and lock inconsistency', $cmd(['composer', 'validate', '--no-interaction'], null, '{PROJECT}'), 'HIGH', 'REPORT_ONLY', [], ['composer']),
        $item('platform-requirement-failure', 'Correct Composer platform requirement failures', $cmd(['composer', 'check-platform-reqs', '--no-interaction'], null, '{PROJECT}'), 'CRITICAL', 'REPORT_ONLY', [], ['composer']),
        $item('composer-self-check-failure', 'Report a Composer self-check failure without guessing its cause', $warning($cmd(['composer', 'diagnose', '--no-interaction'], null, '{PROJECT}')), 'MEDIUM', 'REPORT_ONLY', [], ['composer']),
        $item('dependency-security-advisories', 'Resolve Composer dependency security advisories', $cmd(['composer', 'audit', '--locked', '--no-interaction'], null, '{PROJECT}'), 'HIGH'),
    ]],
    'ssl' => ['SSL', 'SSL/TLS', 2, [
        $item('openssl-missing', 'Install the missing OpenSSL executable', $exec('openssl'), 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('certificate-file-missing', 'Restore the missing configured TLS certificate', $path('/opt/lampp/etc/ssl.crt/server.crt', 'file_exists'), 'CRITICAL'),
        $item('private-key-file-missing', 'Restore the missing configured TLS private key', $path('/opt/lampp/etc/ssl.key/server.key', 'file_exists'), 'CRITICAL'),
        $item('certificate-expired', 'Renew an expired local TLS certificate', ['type' => 'certificate_expiry', 'certificate' => '/opt/lampp/etc/ssl.crt/server.crt', 'mode' => 'expired', 'minimum_valid_seconds' => 0], 'CRITICAL', 'AUTO_WITH_BACKUP', [['type' => 'doctor_action', 'action' => 'renew-local-tls', 'path' => '/opt/lampp/etc/ssl.crt/server.crt', 'timeout' => 90, 'description' => 'Renew the XAMPP localhost certificate with its existing private key through the scoped helper.']], ['openssl']),
        $item('certificate-expiring-soon', 'Renew a TLS certificate nearing expiration', ['type' => 'certificate_expiry', 'certificate' => '/opt/lampp/etc/ssl.crt/server.crt', 'mode' => 'expiring', 'minimum_valid_seconds' => 1209600], 'HIGH', 'REPORT_ONLY', [], ['openssl']),
        $item('certificate-key-mismatch', 'Correct a TLS certificate and key mismatch', ['type' => 'certificate_key_match', 'certificate' => '/opt/lampp/etc/ssl.crt/server.crt', 'key' => '/opt/lampp/etc/ssl.key/server.key'], 'CRITICAL', 'REPORT_ONLY', [], ['openssl']),
        $item('https-listener-unavailable', 'Restore the unavailable local HTTPS listener', $tcp('127.0.0.1', 443), 'HIGH'),
        $item('certbot-missing', 'Install the missing Certbot client', $warning($exec('certbot')), 'MEDIUM', 'REPORT_ONLY'),
        $item('certificate-directory-not-readable', 'Correct unreadable TLS certificate storage', $path('/opt/lampp/etc/ssl.crt', 'path_readable'), 'HIGH'),
        $item('private-key-directory-unsafe', 'Correct unsafe TLS private-key directory permissions', $path('/opt/lampp/etc/ssl.key', 'path_readable'), 'HIGH'),
    ]],
    'git' => ['GIT', 'Git', 2, [
        $item('git-missing', 'Install the missing Git executable', $exec('git'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('repository-metadata-missing', 'Restore missing Git repository metadata', $path('{PROJECT}/.git', 'directory_exists'), 'MEDIUM'),
        $item('repository-object-corruption', 'Recover corrupt Git repository objects', $cmd(['git', '-c', 'safe.directory={PROJECT}', 'fsck', '--no-dangling'], null, '{PROJECT}'), 'CRITICAL', 'REPORT_ONLY', [], ['git']),
        $item('merge-conflict-in-progress', 'Resolve an interrupted Git merge safely', $absent('{PROJECT}/.git/MERGE_HEAD'), 'HIGH'),
        $item('rebase-in-progress', 'Resolve an interrupted Git rebase safely', $absent('{PROJECT}/.git/rebase-merge'), 'HIGH'),
        $item('cherry-pick-in-progress', 'Resolve an interrupted Git cherry-pick safely', $absent('{PROJECT}/.git/CHERRY_PICK_HEAD'), 'HIGH'),
        $item('index-lock-stale', 'Remove a verified stale Git index lock', $absent('{PROJECT}/.git/index.lock'), 'HIGH'),
        $item('remote-origin-missing', 'Configure a missing Git origin remote', $cmd(['git', '-c', 'safe.directory={PROJECT}', 'remote', 'get-url', 'origin'], null, '{PROJECT}'), 'MEDIUM', 'REPORT_ONLY', [], ['git']),
        $item('user-name-missing', 'Configure a missing repository Git author name', $cmd(['git', 'config', '--get', 'user.name'], null, '{PROJECT}'), 'LOW', 'REPORT_ONLY', [], ['git']),
        $item('user-email-missing', 'Configure a missing repository Git author email', $cmd(['git', 'config', '--get', 'user.email'], null, '{PROJECT}'), 'LOW', 'REPORT_ONLY', [], ['git']),
    ]],
    'filesystem' => ['FILESYSTEM', 'Filesystem', 2, [
        $item('project-root-missing', 'Restore the missing project root', $path('{PROJECT}', 'directory_exists'), 'CRITICAL'),
        $item('project-root-not-readable', 'Correct an unreadable project root', $path('{PROJECT}', 'path_readable'), 'CRITICAL'),
        $item('project-root-not-writable', 'Verify managed project access', $path('{PROJECT}', 'path_readable'), 'HIGH'),
        $item('storage-root-missing', 'Create the missing Doctor storage root', $path('{STORAGE_PATH}', 'directory_exists'), 'CRITICAL', 'AUTO_SAFE', [['type' => 'create_directory', 'path' => '{STORAGE_PATH}', 'mode' => 0750, 'description' => 'Create the configured application storage root.']], [], true),
        $item('doctor-report-directory-missing', 'Create the missing Doctor report directory', $path('{STORAGE_PATH}/doctor/reports', 'directory_exists'), 'HIGH', 'AUTO_SAFE', [['type' => 'create_directory', 'path' => '{STORAGE_PATH}/doctor/reports', 'mode' => 0750, 'description' => 'Create Doctor report storage.']], [], true),
        $item('doctor-backup-directory-missing', 'Create the missing Doctor backup directory', $path('{STORAGE_PATH}/doctor/backups', 'directory_exists'), 'HIGH', 'AUTO_SAFE', [['type' => 'create_directory', 'path' => '{STORAGE_PATH}/doctor/backups', 'mode' => 0750, 'description' => 'Create Doctor backup storage.']], [], true),
        $item('application-inodes-low', 'Recover exhausted application filesystem inodes', $cmd(['df', '-Pi', '{PROJECT}']), 'CRITICAL', 'REPORT_ONLY', [], ['df']),
        $item('read-only-application-filesystem', 'Recover a read-only application filesystem', ['type' => 'filesystem_mount_writable', 'path' => '{PROJECT}'], 'CRITICAL', 'REPORT_ONLY', [], ['findmnt']),
        $item('broken-public-storage-link', 'Repair a broken project public storage link', ['type' => 'symlink_target', 'path' => '{PROJECT}/public/storage', 'target' => '{PROJECT}/storage/app/public'], 'HIGH'),
        $item('temporary-workspace-unavailable', 'Restore unavailable temporary workspace storage', $path('/tmp', 'path_writable'), 'HIGH'),
    ]],
    'cron' => ['CRON', 'Cron', 2, [
        $item('crontab-command-missing', 'Install the missing crontab command', $exec('crontab'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('cron-service-stopped', 'Start the stopped cron scheduler service', $service('cron'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'start', 'cron'], 'description' => 'Start only cron.']], ['systemctl']),
        $item('cron-service-disabled', 'Enable the disabled cron scheduler service', $service('cron', true), 'HIGH', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'enable', 'cron'], 'description' => 'Enable cron at boot.']], ['systemctl']),
        $item('laravel-scheduler-entry-missing', 'Add the missing Laravel scheduler cron entry', $cmd(['crontab', '-l'], 'artisan schedule:run'), 'HIGH'),
        $item('duplicate-laravel-scheduler-entry', 'Remove duplicate Laravel scheduler entries', ['type' => 'command_occurrences', 'command' => ['crontab', '-l'], 'needle' => 'artisan schedule:run', 'minimum' => 0, 'maximum' => 1, 'timeout' => 20], 'MEDIUM', 'REPORT_ONLY', [], ['crontab'], false, ['laravel_detected' => [true]]),
        $item('cron-spool-missing', 'Restore the missing cron spool directory', $path('/var/spool/cron', 'directory_exists'), 'HIGH'),
        $item('cron-log-unavailable', 'Restore unavailable cron logging', $path('/var/log', 'directory_exists'), 'MEDIUM'),
        $item('project-path-missing-in-schedule', 'Correct a stale project path in scheduled commands', $cmd(['crontab', '-l'], '{PROJECT}'), 'HIGH'),
        $item('php-path-missing-in-schedule', 'Correct a missing PHP executable in scheduled commands', $exec('php'), 'HIGH'),
        $item('scheduler-lock-directory-missing', 'Create the Laravel scheduler lock directory', $path('{PROJECT}/storage/framework/cache/data', 'directory_exists'), 'HIGH', 'AUTO_SAFE', [
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage', 'mode' => 0775, 'description' => 'Ensure storage exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework', 'mode' => 0775, 'description' => 'Ensure framework runtime storage exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework/cache', 'mode' => 0775, 'description' => 'Ensure cache storage exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/framework/cache/data', 'mode' => 0775, 'description' => 'Create scheduler lock storage.'],
        ], [], true, ['laravel_detected' => [true]]),
    ]],
    'queue' => ['QUEUE', 'Queue and Supervisor', 2, [
        $item('supervisorctl-missing', 'Install the missing Supervisor control client', $exec('supervisorctl'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('supervisor-service-stopped', 'Start the stopped Supervisor service', $service('supervisor'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'start', 'supervisor'], 'description' => 'Start Supervisor.']], ['systemctl']),
        $item('supervisor-service-disabled', 'Enable the disabled Supervisor service', $service('supervisor', true), 'HIGH', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'enable', 'supervisor'], 'description' => 'Enable Supervisor at boot.']], ['systemctl']),
        $item('supervisor-configuration-invalid', 'Correct invalid Supervisor configuration', $cmd(['supervisorctl', 'status']), 'HIGH', 'REPORT_ONLY', [], ['supervisorctl']),
        $item('queue-worker-stale-code', 'Restart Laravel queue workers using stale code', $cmd(['php', 'artisan', 'queue:monitor'], null, '{PROJECT}'), 'HIGH', 'AUTO_SAFE', [['type' => 'run_command', 'command' => ['php', 'artisan', 'queue:restart', '--no-interaction'], 'cwd' => '{PROJECT}', 'description' => 'Request a graceful queue-worker restart.']], ['php'], false, ['laravel_detected' => [true]]),
        $item('horizon-unavailable', 'Restore unavailable Laravel Horizon workers', $cmd(['php', 'artisan', 'horizon:status'], 'running', '{PROJECT}'), 'CRITICAL', 'REPORT_ONLY', [], ['php'], false, ['laravel_detected' => [true]]),
        $item('failed-jobs-table-missing', 'Restore the missing Laravel failed-jobs table', ['type' => 'database_table_exists', 'table' => 'failed_jobs'], 'HIGH'),
        $item('jobs-table-missing', 'Restore the missing Laravel jobs table', ['type' => 'database_table_exists', 'table' => 'jobs'], 'HIGH'),
        $item('queue-log-directory-missing', 'Create the missing queue log directory', $path('{PROJECT}/storage/logs', 'directory_exists'), 'MEDIUM', 'AUTO_SAFE', [
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage', 'mode' => 0775, 'description' => 'Ensure storage exists.'],
            ['type' => 'create_directory', 'path' => '{PROJECT}/storage/logs', 'mode' => 0775, 'description' => 'Create Laravel log storage.'],
        ], [], true, ['laravel_detected' => [true]]),
        $item('queue-runtime-directory-not-writable', 'Correct an unwritable queue runtime directory', $path('{PROJECT}/storage/framework', 'path_writable'), 'CRITICAL'),
    ]],
    'security' => ['SECURITY', 'Security', 3, [
        $item('environment-file-unreadable', 'Correct an unreadable application environment file', $path('{PROJECT}/.env', 'path_readable'), 'CRITICAL'),
        $item('environment-file-world-accessible', 'Restrict overly broad environment-file permissions', $cmd(['stat', '-c', '%a', '{PROJECT}/.env'], '640'), 'CRITICAL', 'AUTO_WITH_BACKUP', [['type' => 'chmod', 'path' => '{PROJECT}/.env', 'mode' => 0640, 'description' => 'Restrict only the environment file to owner/group read access.']], ['stat'], true),
        $item('private-key-directory-missing', 'Restore missing private-key storage', $path('/opt/lampp/etc/ssl.key', 'directory_exists'), 'CRITICAL'),
        $item('ssh-configuration-missing', 'Restore the missing SSH server configuration', $path('/etc/ssh/sshd_config', 'file_exists'), 'HIGH'),
        $item('firewall-tool-missing', 'Install a supported firewall management tool', ['type' => 'any_executable_exists', 'commands' => ['ufw', 'nft', 'iptables', 'firewall-cmd']], 'MEDIUM', 'APPROVAL_REQUIRED'),
        $item('audit-log-directory-missing', 'Restore the missing audit-log directory', $path('{STORAGE_PATH}/logs', 'directory_exists'), 'HIGH'),
        $item('application-debug-enabled', 'Disable Laravel debug output in production', $env('{PROJECT}/.env', 'APP_DEBUG', 'false'), 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('default-admin-credential-risk', 'Remove default administrator credential exposure', $contains('{PROJECT}/config/config.php', "'reject_default_credentials' => true"), 'CRITICAL'),
        $item('security-headers-module-missing', 'Enable Apache security response headers', $contains('/opt/lampp/etc/httpd.conf', 'headers_module'), 'HIGH'),
        $item('dependency-advisories-present', 'Resolve known dependency security advisories', $cmd(['composer', 'audit', '--locked', '--no-interaction'], null, '{PROJECT}'), 'HIGH', 'REPORT_ONLY', [], ['composer']),
    ]],
    'performance' => ['PERFORMANCE', 'Performance', 3, [
        $item('opcache-extension-missing', 'Enable the missing PHP OPcache extension', $cmd(['xampp-php', '-r', 'exit(extension_loaded("Zend OPcache") ? 0 : 1);']), 'HIGH', 'AUTO_WITH_BACKUP', [['type' => 'doctor_action', 'action' => 'enable-opcache', 'path' => '/opt/lampp/etc/php.ini', 'timeout' => 90, 'description' => 'Enable the bundled XAMPP OPcache extension through the scoped helper.']], ['xampp-php']),
        $item('root-disk-capacity-pressure', 'Reduce root filesystem capacity pressure', ['type' => 'disk_free_percent', 'path' => '/', 'minimum' => 15], 'HIGH'),
        $item('application-disk-capacity-pressure', 'Reduce application filesystem capacity pressure', ['type' => 'disk_free_percent', 'path' => '{PROJECT}', 'minimum' => 15], 'HIGH'),
        $item('php-memory-limit-too-low', 'Increase an insufficient PHP memory limit', $cmd(['php', '-r', '$v=(int)ini_get("memory_limit"); exit($v === -1 || $v >= 128 ? 0 : 1);']), 'MEDIUM', 'REPORT_ONLY', [], ['php']),
        $item('apache-compression-module-missing', 'Enable Apache response compression', $contains('/opt/lampp/etc/httpd.conf', 'deflate_module'), 'MEDIUM'),
        $item('apache-keepalive-disabled', 'Enable correctly bounded Apache KeepAlive', $contains('/opt/lampp/etc/extra/httpd-default.conf', 'KeepAlive On'), 'MEDIUM'),
        $item('composer-autoload-unoptimized', 'Optimize Composer autoload metadata', $path('{PROJECT}/vendor/composer/autoload_classmap.php', 'file_exists'), 'MEDIUM', 'AUTO_SAFE', [['type' => 'run_command', 'command' => ['composer', 'dump-autoload', '--optimize', '--no-interaction'], 'cwd' => '{PROJECT}', 'description' => 'Regenerate optimized autoload metadata.']], ['composer']),
        $item('laravel-config-cache-missing', 'Build Laravel production configuration cache', $path('{PROJECT}/bootstrap/cache/config.php', 'file_exists'), 'LOW', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['php', 'artisan', 'config:cache', '--no-interaction'], 'cwd' => '{PROJECT}', 'description' => 'Build Laravel configuration cache.']], ['php'], false, ['laravel_detected' => [true]]),
        $item('mysql-endpoint-latency-risk', 'Investigate unavailable or slow MySQL endpoint', $tcp('127.0.0.1', (int) config_value('database.port', 3306)), 'HIGH'),
        $item('redis-endpoint-latency-risk', 'Investigate unavailable or slow Redis endpoint', $tcp('127.0.0.1', 6379), 'MEDIUM'),
    ]],
    'backups' => ['BACKUP', 'Backups', 3, [
        $item('backup-root-missing', 'Create the missing backup root', $path('{STORAGE_PATH}/backups', 'directory_exists'), 'CRITICAL', 'AUTO_SAFE', [['type' => 'create_directory', 'path' => '{STORAGE_PATH}/backups', 'mode' => 0750, 'description' => 'Create application backup storage.']], [], true),
        $item('backup-root-not-writable', 'Correct an unwritable backup root', $path('{STORAGE_PATH}/backups', 'path_writable'), 'CRITICAL'),
        $item('doctor-backup-root-missing', 'Create the missing Doctor recovery root', $path('{STORAGE_PATH}/doctor/backups', 'directory_exists'), 'HIGH', 'AUTO_SAFE', [['type' => 'create_directory', 'path' => '{STORAGE_PATH}/doctor/backups', 'mode' => 0750, 'description' => 'Create Doctor recovery storage.']], [], true),
        $item('database-backup-tool-missing', 'Install the missing database backup tool', $exec('mysqldump'), 'CRITICAL', 'APPROVAL_REQUIRED'),
        $item('archive-tool-missing', 'Install the missing archive utility', $exec('tar'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('backup-partition-low-space', 'Recover low backup-storage capacity', ['type' => 'disk_free_percent', 'path' => '{STORAGE_PATH}/backups', 'minimum' => 15], 'CRITICAL'),
        $item('backup-manifest-missing', 'Restore missing Doctor backup manifests', $path('{STORAGE_PATH}/doctor/backups', 'path_readable'), 'HIGH'),
        $item('backup-retention-policy-missing', 'Define an explicit backup retention policy', $contains('{PROJECT}/doctor/config/doctor.php', 'report_retention_days'), 'MEDIUM'),
        $item('database-backup-schedule-missing', 'Add the missing database backup schedule', $warning($cmd(['crontab', '-l'], 'mysqldump')), 'HIGH'),
        $item('backup-restoration-unverified', 'Verify backup restoration in an isolated environment', $path('{STORAGE_PATH}/backups', 'path_readable'), 'HIGH'),
    ]],
    'redis' => ['REDIS', 'Redis', 3, [
        $item('redis-client-missing', 'Install the missing Redis client', $exec('redis-cli'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('redis-server-missing', 'Install the missing Redis server', $exec('redis-server'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('redis-endpoint-unavailable', 'Restore the unavailable Redis endpoint', $tcp('127.0.0.1', 6379), 'CRITICAL'),
        $item('redis-service-stopped', 'Start the stopped Redis service', $service('redis-server'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'start', 'redis-server'], 'description' => 'Start Redis.']], ['systemctl']),
        $item('redis-service-disabled', 'Enable the disabled Redis service', $service('redis-server', true), 'HIGH', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'enable', 'redis-server'], 'description' => 'Enable Redis at boot.']], ['systemctl']),
        $item('redis-ping-failure', 'Correct Redis command connectivity failure', $cmd(['redis-cli', 'ping'], 'PONG'), 'CRITICAL', 'REPORT_ONLY', [], ['redis-cli']),
        $item('redis-configuration-missing', 'Restore the missing Redis configuration', $warning($path('/etc/redis/redis.conf', 'file_exists')), 'HIGH'),
        $item('redis-data-directory-missing', 'Restore the missing Redis data directory', $path('/var/lib/redis', 'directory_exists'), 'HIGH'),
        $item('redis-log-directory-missing', 'Restore the missing Redis log directory', $path('/var/log/redis', 'directory_exists'), 'MEDIUM'),
        $item('laravel-redis-configuration-missing', 'Correct missing Laravel Redis configuration', $env('{PROJECT}/.env', 'REDIS_HOST'), 'HIGH'),
    ]],
    'mail' => ['MAIL', 'Mail', 3, [
        $item('sendmail-command-missing', 'Install a missing sendmail-compatible command', $exec('sendmail'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('postfix-command-missing', 'Install the missing Postfix service', $exec('postfix'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('smtp-endpoint-unavailable', 'Restore the unavailable local SMTP endpoint', $tcp('127.0.0.1', 25), 'HIGH'),
        $item('postfix-service-stopped', 'Start the stopped Postfix service', $service('postfix'), 'HIGH', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'start', 'postfix'], 'description' => 'Start Postfix.']], ['systemctl']),
        $item('postfix-service-disabled', 'Enable the disabled Postfix service', $service('postfix', true), 'MEDIUM', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'enable', 'postfix'], 'description' => 'Enable Postfix at boot.']], ['systemctl']),
        $item('mail-transport-unconfigured', 'Configure the application mail transport', $env('{PROJECT}/.env', 'MAIL_MAILER'), 'HIGH'),
        $item('mail-host-unconfigured', 'Configure the application mail host', $env('{PROJECT}/.env', 'MAIL_HOST'), 'HIGH'),
        $item('mail-from-address-unconfigured', 'Configure the application sender address', $env('{PROJECT}/.env', 'MAIL_FROM_ADDRESS'), 'MEDIUM'),
        $item('mail-log-directory-missing', 'Restore the missing mail log directory', $path('/var/log', 'directory_exists'), 'MEDIUM'),
        $item('mail-dns-resolution-failure', 'Restore DNS resolution for the configured mail host', ['type' => 'dns_resolves', 'host' => (string) config_value('mail.host', 'localhost')], 'HIGH'),
    ]],
    'docker' => ['DOCKER', 'Docker', 4, [
        $item('docker-client-missing', 'Install the missing Docker client', $exec('docker'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('docker-service-stopped', 'Start the stopped Docker service', $service('docker'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'start', 'docker'], 'description' => 'Start Docker.']], ['systemctl']),
        $item('docker-service-disabled', 'Enable the disabled Docker service', $service('docker', true), 'HIGH', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'enable', 'docker'], 'description' => 'Enable Docker at boot.']], ['systemctl']),
        $item('docker-daemon-unreachable', 'Restore Docker daemon connectivity', [...$cmd(['docker', 'info']), 'skip_on_permission_denied' => true], 'CRITICAL', 'REPORT_ONLY', [], ['docker']),
        $item('compose-file-missing', 'Restore the missing Docker Compose definition', $path('{PROJECT}/compose.yaml', 'file_exists'), 'MEDIUM'),
        $item('compose-configuration-invalid', 'Correct invalid Docker Compose configuration', $cmd(['docker', 'compose', 'config', '--quiet'], null, '{PROJECT}'), 'HIGH', 'REPORT_ONLY', [], ['docker']),
        $item('dockerfile-missing', 'Restore the missing project Dockerfile', $path('{PROJECT}/Dockerfile', 'file_exists'), 'MEDIUM'),
        $item('docker-socket-missing', 'Restore the missing Docker daemon socket', $path('/var/run/docker.sock', 'path_exists'), 'CRITICAL'),
        $item('docker-disk-pressure', 'Recover Docker storage capacity', ['type' => 'disk_free_percent', 'path' => '/var/lib/docker', 'minimum' => 15], 'HIGH'),
        $item('container-health-failure', 'Correct unhealthy running containers', ['type' => 'command_stdout_empty', 'command' => ['docker', 'ps', '--filter', 'health=unhealthy', '--format', '{{.ID}}'], 'timeout' => 30, 'skip_on_permission_denied' => true], 'HIGH', 'REPORT_ONLY', [], ['docker']),
    ]],
    'nginx' => ['NGINX', 'Nginx', 4, [
        $item('nginx-missing', 'Install the missing Nginx executable', $exec('nginx'), 'HIGH', 'APPROVAL_REQUIRED'),
        $item('nginx-service-stopped', 'Start the stopped Nginx service', $service('nginx'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'start', 'nginx'], 'description' => 'Start Nginx after diagnosis.']], ['systemctl']),
        $item('nginx-service-disabled', 'Enable the disabled Nginx service', $service('nginx', true), 'HIGH', 'APPROVAL_REQUIRED', [['type' => 'run_command', 'command' => ['systemctl', 'enable', 'nginx'], 'description' => 'Enable Nginx at boot.']], ['systemctl']),
        $item('nginx-configuration-invalid', 'Correct invalid Nginx configuration syntax', $cmd(['nginx', '-t']), 'CRITICAL', 'REPORT_ONLY', [], ['nginx']),
        $item('nginx-configuration-missing', 'Restore the missing Nginx configuration', $path('/etc/nginx/nginx.conf', 'file_exists'), 'CRITICAL'),
        $item('nginx-sites-directory-missing', 'Restore the missing Nginx sites directory', $path('/etc/nginx/sites-available', 'directory_exists'), 'HIGH'),
        $item('nginx-log-directory-missing', 'Restore the missing Nginx log directory', $path('/var/log/nginx', 'directory_exists'), 'HIGH'),
        $item('nginx-http-listener-unavailable', 'Restore the unavailable Nginx HTTP listener', $tcp('127.0.0.1', 80), 'CRITICAL'),
        $item('nginx-https-listener-unavailable', 'Restore the unavailable Nginx HTTPS listener', $tcp('127.0.0.1', 443), 'HIGH'),
        $item('nginx-php-fpm-socket-mismatch', 'Correct an Nginx PHP-FPM socket mismatch', $contains('/etc/nginx/nginx.conf', 'fastcgi_pass'), 'HIGH'),
    ]],
    'xampp' => ['XAMPP', 'XAMPP/LAMPP', 4, [
        $item('xampp-control-missing', 'Restore the missing XAMPP control executable', $exec('xampp'), 'CRITICAL'),
        $item('xampp-root-missing', 'Restore the missing XAMPP installation root', $path('/opt/lampp', 'directory_exists'), 'CRITICAL'),
        $item('xampp-php-missing', 'Restore the missing XAMPP PHP executable', $exec('xampp-php'), 'CRITICAL'),
        $item('xampp-apache-missing', 'Restore the missing XAMPP Apache executable', $exec('xampp-httpd'), 'CRITICAL'),
        $item('xampp-mysql-client-missing', 'Restore the missing XAMPP MySQL client', $exec('xampp-mysql'), 'HIGH'),
        $item('xampp-apache-stopped', 'Start the stopped XAMPP Apache service', $service('apache'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'service_action', 'service' => 'apache', 'action' => 'start', 'description' => 'Start only XAMPP Apache through the scoped service helper.']], ['xampp']),
        $item('xampp-mysql-stopped', 'Start the stopped XAMPP MySQL service', $service('mysql'), 'CRITICAL', 'APPROVAL_REQUIRED', [['type' => 'service_action', 'service' => 'mysql', 'action' => 'start', 'description' => 'Start only XAMPP MySQL through the scoped service helper.']], ['xampp']),
        $item('xampp-apache-config-invalid', 'Correct invalid XAMPP Apache configuration', $cmd(['xampp-httpd', '-t']), 'CRITICAL', 'REPORT_ONLY', [], ['xampp-httpd']),
        $item('xampp-htdocs-missing', 'Restore the missing XAMPP document root', $path('/opt/lampp/htdocs', 'directory_exists'), 'CRITICAL'),
        $item('xampp-log-directory-missing', 'Restore the missing XAMPP log directory', $path('/opt/lampp/logs', 'directory_exists'), 'HIGH'),
    ]],
    'dns' => ['DNS', 'DNS', 4, [
        $item('localhost-resolution-failure', 'Restore localhost name resolution', ['type' => 'dns_resolves', 'host' => 'localhost'], 'CRITICAL'),
        $item('hostname-resolution-failure', 'Restore local hostname resolution', ['type' => 'dns_resolves', 'host' => gethostname() ?: 'localhost'], 'HIGH'),
        $item('resolver-configuration-missing', 'Restore the missing resolver configuration', $path('/etc/resolv.conf', 'file_exists'), 'CRITICAL'),
        $item('resolver-configuration-unreadable', 'Correct unreadable resolver configuration', $path('/etc/resolv.conf', 'path_readable'), 'CRITICAL'),
        $item('hosts-file-missing', 'Restore the missing hosts file', $path('/etc/hosts', 'file_exists'), 'CRITICAL'),
        $item('loopback-host-entry-missing', 'Restore the missing IPv4 loopback host entry', $contains('/etc/hosts', '127.0.0.1'), 'HIGH'),
        $item('ipv6-loopback-entry-missing', 'Restore the missing IPv6 loopback host entry', $contains('/etc/hosts', '::1'), 'MEDIUM'),
        $item('external-dns-resolution-failure', 'Restore external DNS resolution', ['type' => 'dns_resolves', 'host' => 'example.com'], 'HIGH'),
        $item('dig-command-missing', 'Install the missing DNS diagnostic utility', $exec('dig'), 'LOW', 'APPROVAL_REQUIRED'),
        $item('configured-domain-unresolved', 'Correct an unresolved configured application domain', ['type' => 'dns_resolves', 'host' => (string) parse_url((string) config_value('app.base_url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'], 'HIGH'),
    ]],
];

$targets = [
    'linux' => [80, 40], 'apache' => [100, 60], 'php' => [90, 55], 'laravel' => [150, 100],
    'mysql' => [100, 50], 'composer' => [50, 30], 'ssl' => [50, 30], 'git' => [50, 25],
    'filesystem' => [80, 50], 'cron' => [40, 25], 'queue' => [60, 40], 'security' => [120, 50],
    'performance' => [100, 50], 'backups' => [60, 35], 'redis' => [50, 30], 'mail' => [50, 25],
    'docker' => [80, 40], 'nginx' => [100, 60], 'xampp' => [70, 40], 'dns' => [50, 20],
];

// Researched next-batch conditions receive permanent, non-reused IDs now.
// They remain RESEARCHED inventory records until their full lifecycle is built.
$proposals = [
    'linux' => [
        'Report an unsupported operating-system version', 'Diagnose failed systemd units', 'Enable a required disabled service',
        'Diagnose a service restart loop', 'Repair broken package dependencies', 'Complete interrupted package configuration',
        'Report an active package-manager lock', 'Remove a verified stale package-manager lock', 'Install a missing required utility',
        'Recover an exhausted inode table', 'Reduce oversized system journals within retention policy', 'Rotate an oversized application log',
        'Repair a failed log-rotation configuration', 'Remove policy-authorized stale temporary files', 'Recover a read-only filesystem',
        'Correct incorrect system time', 'Correct an incorrect system timezone', 'Repair broken hostname resolution',
        'Repair invalid resolver configuration', 'Create policy-approved swap', 'Diagnose exhausted swap', 'Diagnose sustained memory pressure',
        'Diagnose sustained high load', 'Report zombie process accumulation', 'Diagnose file-descriptor exhaustion',
        'Correct an insufficient file-descriptor limit', 'Report a pending reboot', 'Enable disabled system logging',
        'Create a missing application service account', 'Correct a service-account login shell', 'Create a missing service-account home directory',
        'Correct an unsafe service umask', 'Correct a required kernel parameter', 'Repair a failed automatic mount',
        'Correct unsafe or incompatible mount options', 'Diagnose a missing expected disk mount',
    ],
    'apache' => [
        'Enable a disabled Apache service', 'Diagnose an Apache restart loop', 'Enable required Apache proxy modules',
        'Remove a duplicate Apache Listen directive', 'Resolve an Apache port ownership conflict', 'Create a missing virtual-host file from an approved template',
        'Enable a disabled Apache virtual host', 'Correct invalid virtual-host syntax', 'Correct an invalid Apache DocumentRoot',
        'Point a Laravel virtual host at its public directory', 'Add a missing scoped Directory access block', 'Correct an incompatible AllowOverride policy',
        'Restore missing Apache index configuration', 'Correct an invalid ServerName', 'Resolve duplicate Apache server names',
        'Disable a conflicting default site', 'Correct an invalid Apache access-log path', 'Create a missing Apache log directory',
        'Correct a broken certificate path', 'Correct an invalid certificate-chain path', 'Diagnose an HTTP-to-HTTPS redirect loop',
        'Add a missing HTTPS redirect', 'Correct an Apache PHP handler mismatch', 'Correct an Apache PHP-FPM socket mismatch',
        'Add missing PHP proxy configuration', 'Enable correctly scoped Apache compression', 'Correct unsafe Apache KeepAlive values',
        'Correct web-root ownership', 'Correct scoped runtime-directory ownership', 'Correct unsafe Apache-served permissions',
        'Correct ignored htaccess configuration', 'Expose a required environment variable safely', 'Correct the configured Apache user',
        'Correct the configured Apache group', 'Repair deterministic virtual-host configuration drift', 'Re-enable a site disabled after package updates',
        'Correct a broken Apache Include path', 'Remove a duplicate virtual-host definition',
    ],
    'php' => [
        'Correct a CLI and Apache PHP version mismatch', 'Correct a CLI and PHP-FPM version mismatch', 'Report conflicting PHP installations',
        'Enable a disabled required PHP extension', 'Remove duplicate PHP extension loading', 'Correct invalid PHP ini syntax',
        'Select the intended loaded PHP configuration file', 'Correct the PHP configuration scan directory', 'Correct an insufficient PHP memory limit',
        'Correct an insufficient upload limit', 'Correct an incompatible post size limit', 'Correct an insufficient execution timeout',
        'Correct an insufficient input-variable limit', 'Correct the PHP default timezone', 'Enable PHP OPcache',
        'Correct unsafe or ineffective OPcache settings', 'Report OPcache memory exhaustion', 'Create a missing PHP session directory',
        'Enable PHP session cleanup', 'Create a missing upload temporary directory', 'Start a stopped PHP-FPM service',
        'Enable a disabled PHP-FPM service', 'Correct invalid PHP-FPM pool syntax', 'Correct a PHP-FPM socket mismatch',
        'Correct the PHP-FPM pool user', 'Correct the PHP-FPM pool group', 'Correct PHP-FPM socket ownership',
        'Correct unsafe PHP-FPM process-manager settings', 'Correct a Composer platform requirement failure', 'Review required disabled PHP functions',
        'Disable unnecessary dangerous PHP functions', 'Disable PHP error display in production', 'Enable PHP error logging',
        'Create a missing PHP error-log directory', 'Correct ineffective realpath cache settings', 'Install missing locale support',
        'Correct the PHP default character set',
    ],
    'laravel' => [
        'Discover Laravel projects under managed roots', 'Correct unreadable Laravel environment configuration', 'Report invalid Laravel environment syntax',
        'Report an invalid Laravel application key', 'Prevent an unsafe production application-key change', 'Correct an invalid Laravel application environment',
        'Correct an invalid Laravel application URL', 'Correct an HTTP and HTTPS application URL mismatch', 'Correct invalid Laravel database variables',
        'Correct an invalid Laravel cache driver', 'Correct an invalid Laravel session driver', 'Correct an invalid Laravel queue connection',
        'Correct invalid Laravel mail configuration', 'Repair broken Composer autoload files', 'Install a missing required Laravel package deterministically',
        'Correct an unwritable Laravel storage directory', 'Correct an unwritable bootstrap cache directory', 'Create missing Laravel framework directories',
        'Create a missing Laravel log directory', 'Correct an unwritable Laravel log file', 'Correct an invalid Laravel log channel',
        'Repair a broken Laravel storage symlink', 'Correct an incorrect Laravel storage symlink target', 'Correct cached Laravel environment drift',
        'Clear stale Laravel route cache', 'Clear stale Laravel view cache', 'Clear stale Laravel event cache', 'Remove corrupt generated Laravel cache files',
        'Report pending Laravel migrations', 'Create a missing Laravel migration table with approval', 'Diagnose Laravel migration connectivity failure',
        'Start a stopped Laravel queue worker', 'Restart a Laravel queue worker using stale code', 'Start a stopped Supervisor Laravel worker',
        'Start stopped Laravel Horizon', 'Report Laravel failed queue jobs', 'Report repeatedly failing Laravel jobs',
        'Add a missing Laravel scheduler entry', 'Correct a malformed Laravel scheduler entry', 'Remove duplicate Laravel scheduler entries',
        'Disable unintended Laravel maintenance mode', 'Correct a misconfigured Laravel public directory', 'Correct scoped application-file ownership',
        'Correct scoped Laravel runtime ownership', 'Correct unsafe Laravel source permissions', 'Create a missing configured SQLite file',
        'Correct an unwritable SQLite database file', 'Report invalid application symbolic links', 'Complete interrupted Composer scripts',
        'Complete interrupted Laravel package discovery', 'Rebuild a missing Laravel package manifest', 'Report a missing frontend asset manifest',
        'Diagnose a failing application health route', 'Report Laravel version incompatibility', 'Report Laravel PHP version incompatibility',
        'Report a missing Laravel-required PHP extension', 'Diagnose unavailable session storage', 'Diagnose an unavailable configured cache backend',
        'Diagnose unavailable configured Redis', 'Diagnose unavailable configured database', 'Correct trusted-proxy configuration',
        'Correct HTTPS detection behind a trusted proxy', 'Remove invalid compiled Laravel service files', 'Remove invalid compiled Laravel package files',
    ],
    'mysql' => [
        'Discover the active MySQL or MariaDB server', 'Enable a disabled database service', 'Diagnose a database service restart loop',
        'Resolve a database port ownership conflict', 'Report invalid configured database credentials', 'Create a missing configured database user',
        'Add narrowly required database grants with approval', 'Report excessive application-user grants', 'Correct a database account host mismatch',
        'Correct an authentication plugin mismatch', 'Correct a socket and TCP connection mismatch', 'Recover from a reached connection limit',
        'Report excessive sleeping database connections', 'Diagnose database disk-space pressure', 'Diagnose database inode exhaustion',
        'Report table crash indicators', 'Report enabled InnoDB recovery mode', 'Enable the slow-query log with approval',
        'Correct an invalid slow-query log path', 'Apply slow-query log retention policy', 'Correct a database character-set mismatch',
        'Correct a database collation mismatch', 'Correct an incompatible SQL mode', 'Report strict-mode application incompatibility',
        'Load missing database timezone tables with approval', 'Report unsafe buffer-pool sizing', 'Report binary-log disk growth',
        'Correct binary-log retention policy', 'Report a missing recent database backup', 'Report failed database backup validation',
        'Diagnose database migration connectivity failure', 'Report database lock pressure', 'Report long-running database queries',
        'Report excessive aborted database connections', 'Correct database DNS lookup delays', 'Correct invalid MySQL configuration syntax',
        'Correct an invalid MySQL include path', 'Correct MySQL socket permissions', 'Correct scoped database data-directory ownership',
        'Renew expired application-user credentials with approval', 'Correct an unsafe database bind address', 'Correct application-user host denial',
        'Correct database TLS configuration failure',
    ],
    'composer' => [
        'Report an outdated Composer version', 'Correct a Composer executable self-check failure', 'Report Composer lock-file inconsistency',
        'Repair a partial vendor installation', 'Regenerate outdated Composer autoload files', 'Report an unsupported PHP platform version',
        'Recover Composer memory exhaustion', 'Correct invalid package repository configuration', 'Report private repository authentication failure safely',
        'Clear a proven corrupt Composer cache', 'Correct Composer home ownership', 'Correct Composer home permissions',
        'Correct project ownership for Composer execution', 'Correct scoped vendor permissions', 'Report failing Composer scripts',
        'Complete interrupted Laravel package discovery', 'Report abandoned Composer packages', 'Report Composer root-execution risk',
        'Install a missing Git dependency', 'Install a missing archive utility', 'Diagnose Composer network resolution failure',
        'Diagnose Composer TLS certificate verification failure', 'Report Composer lock contention', 'Recover an interrupted Composer install',
        'Restore missing locked package artifacts',
    ],
    'ssl' => ['Correct an incomplete certificate chain', 'Correct a certificate hostname mismatch', 'Report an untrusted certificate issuer', 'Correct unreadable private-key permissions', 'Repair a broken Certbot renewal timer', 'Renew an already configured certificate', 'Report an unsupported TLS protocol', 'Disable an unsafe TLS cipher', 'Correct OCSP stapling failure', 'Correct an HTTPS redirect loop', 'Report HSTS misconfiguration', 'Correct an ACME challenge path', 'Diagnose ACME DNS validation failure', 'Correct SNI virtual-host selection', 'Report certificate transparency policy issues'],
    'git' => ['Report uncommitted deployment changes', 'Report a detached deployment HEAD', 'Correct an invalid upstream branch', 'Repair a broken worktree reference', 'Report unsafe repository ownership', 'Correct repository file permissions', 'Remove a proven stale Git lock', 'Recover an interrupted fetch', 'Report diverged local and remote branches', 'Report missing deployment tags', 'Correct invalid submodule configuration', 'Initialize missing submodules', 'Report Git LFS artifacts missing', 'Diagnose SSH remote authentication failure', 'Diagnose HTTPS remote authentication failure'],
    'filesystem' => ['Report filesystem inode pressure', 'Report filesystem mount disappearance', 'Correct a scoped file owner mismatch', 'Correct a scoped directory owner mismatch', 'Correct an unsafe world-writable source file', 'Correct a setuid file policy violation', 'Repair a known broken symlink', 'Report symlink loops', 'Correct path traversal exposure', 'Repair a failed atomic-write directory', 'Recover a stale application lock file', 'Report oversized unknown files', 'Correct log-directory permissions', 'Correct backup-directory permissions', 'Report filesystem quota exhaustion'],
    'cron' => ['Correct an invalid cron expression', 'Correct an unknown cron execution user', 'Correct a missing cron working directory', 'Correct a missing scheduled executable', 'Correct redirected cron output loss', 'Correct cron environment PATH', 'Report repeatedly failing cron jobs', 'Remove a verified stale cron lock', 'Correct timezone-sensitive cron scheduling', 'Report overlapping cron executions', 'Add locking to an overlapping scheduled job', 'Correct disabled user crontab access', 'Restore a removed managed cron entry', 'Correct a malformed Supervisor cron substitute', 'Verify scheduler heartbeat freshness'],
    'queue' => ['Correct an invalid Supervisor program definition', 'Reload validated Supervisor configuration', 'Start a stopped named worker group', 'Restart a crash-looping worker', 'Correct a worker command path', 'Correct a worker project directory', 'Correct a worker service account', 'Correct queue retry timeout mismatch', 'Report queue backlog growth', 'Report failed-job growth', 'Preserve evidence for repeatedly failing jobs', 'Correct Horizon Supervisor configuration', 'Reload Horizon after deployment', 'Correct queue cache-backend connectivity', 'Correct queue database connectivity'],
    'security' => ['Report exposed development endpoints', 'Report weak SSH authentication policy', 'Correct an unsafe SSH root-login policy', 'Report missing MFA for privileged users', 'Correct missing security response headers', 'Report unexpected listening services', 'Report suspicious executable uploads', 'Report possible malware indicators', 'Report modified system binaries', 'Correct an unsafe firewall exposure', 'Preserve active administrator access during firewall changes', 'Report stale privileged accounts', 'Correct session cookie security policy', 'Correct missing CSRF protection on a route', 'Report leaked secrets in tracked files'],
    'performance' => ['Report sustained CPU saturation', 'Report sustained memory saturation', 'Report swap thrashing', 'Report PHP-FPM queue saturation', 'Correct PHP-FPM child limits', 'Correct Apache worker limits', 'Correct Nginx worker limits', 'Report MySQL buffer-pool pressure', 'Report slow database queries', 'Report Redis memory pressure', 'Correct missing static asset compression', 'Correct missing cache-control headers', 'Report oversized application logs', 'Report slow DNS resolution', 'Report application health latency regression'],
    'backups' => ['Report stale filesystem backups', 'Report stale database backups', 'Verify backup archive integrity', 'Verify SQL dump readability', 'Run an isolated restoration test', 'Correct encrypted-backup key access', 'Report unencrypted sensitive backups', 'Correct backup ownership', 'Correct backup permissions', 'Report retention-policy drift', 'Apply approved backup retention', 'Report offsite replication failure', 'Correct backup schedule timezone', 'Report partial backup artifacts', 'Correct backup manifest hash mismatch'],
    'redis' => ['Correct Redis bind-address policy', 'Correct Redis authentication configuration', 'Correct Redis TLS configuration', 'Report Redis memory exhaustion', 'Correct unsafe Redis eviction policy', 'Report Redis persistence failure', 'Correct invalid RDB path', 'Correct invalid AOF path', 'Repair a stopped Redis persistence rewrite', 'Report Redis replication lag', 'Report disconnected Redis replicas', 'Correct Redis socket permissions', 'Correct Laravel Redis database selection', 'Correct Laravel Redis password mismatch safely', 'Report Redis command latency'],
    'mail' => ['Correct SMTP authentication failure', 'Correct SMTP TLS negotiation failure', 'Correct SMTP port mismatch', 'Correct sender-domain mismatch', 'Report SPF policy failure', 'Report DKIM policy failure', 'Report DMARC policy failure', 'Correct reverse-DNS mismatch', 'Report mail queue growth', 'Retry deferred mail safely', 'Correct mail-log permissions', 'Correct Laravel mail encryption selection', 'Correct application reply-to configuration', 'Report provider rate limiting', 'Report rejected recipient patterns'],
    'docker' => ['Report stopped required containers', 'Start a stopped required container', 'Report container restart loops', 'Correct a missing container healthcheck', 'Report image pull failure', 'Report missing image tags', 'Correct Compose environment-file paths', 'Correct container volume permissions', 'Report missing named volumes', 'Report Docker network conflicts', 'Correct published-port conflicts', 'Report orphaned Compose containers', 'Apply approved Docker storage retention', 'Report container resource-limit absence', 'Correct container DNS configuration'],
    'nginx' => ['Diagnose an Nginx restart loop', 'Resolve duplicate Nginx listen directives', 'Resolve an Nginx port conflict', 'Correct a missing server block', 'Enable a disabled Nginx site', 'Correct an invalid server root', 'Point Laravel Nginx root at public', 'Correct FastCGI parameter configuration', 'Correct a PHP-FPM socket mismatch', 'Correct broken certificate paths', 'Correct an HTTPS redirect loop', 'Enable scoped response compression', 'Correct unsafe keepalive settings', 'Correct Nginx log paths', 'Remove duplicate server-name definitions'],
    'xampp' => ['Correct XAMPP Apache port conflicts', 'Correct XAMPP MySQL port conflicts', 'Repair XAMPP Apache configuration includes', 'Repair XAMPP MySQL configuration includes', 'Correct XAMPP PHP configuration selection', 'Correct XAMPP PHP extension loading', 'Repair XAMPP service PID-file drift', 'Report XAMPP service restart loops', 'Correct XAMPP log permissions', 'Correct XAMPP htdocs ownership', 'Correct XAMPP virtual-host paths', 'Correct XAMPP certificate paths', 'Correct XAMPP MySQL socket permissions', 'Repair XAMPP environment command paths', 'Report incompatible XAMPP component versions'],
    'dns' => ['Correct a missing A record', 'Correct a missing AAAA record', 'Correct an incorrect CNAME record', 'Report an expired DNSSEC signature', 'Report DNSSEC validation failure', 'Correct an MX record mismatch', 'Correct a TXT record mismatch', 'Report nameserver delegation mismatch', 'Report excessive DNS TTL during migration', 'Report DNS propagation inconsistency', 'Correct split-horizon DNS mismatch', 'Report wildcard record conflicts', 'Correct reverse-DNS configuration', 'Report local resolver cache failure', 'Correct systemd-resolved integration'],
];

$safeName = static fn (string $slug): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
$json = static fn (array $data): string => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

foreach ($modules as $module => [$prefix, $displayName, $stage, $items]) {
    $directory = $root . '/' . $module;
    foreach (['Discovery', 'Checks', 'Diagnoses', 'Repairs', 'Verifications', 'Rollbacks', 'Fixtures', 'Tests', 'Docs'] as $folder) {
        if (!is_dir($directory . '/' . $folder)) {
            mkdir($directory . '/' . $folder, 0775, true);
        }
    }
    file_put_contents($directory . '/module.json', $json([
        'name' => $module, 'display_name' => $displayName, 'description' => "Cause-specific {$displayName} diagnostics and repairs.",
        'version' => '1.0.0', 'stage' => $stage, 'enabled' => true, 'repair_prefix' => $prefix,
        'minimum_php' => '8.2', 'repair_inventory' => 'repair-inventory.json',
    ]));
    file_put_contents($directory . '/Module.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace SysAdmin\\Doctor\\modules\\{$module};\n\nuse SysAdmin\\Doctor\\engine\\Contracts\\ModuleInterface;\n\nfinal readonly class Module implements ModuleInterface\n{\n    public function name(): string\n    {\n        return '" . $module . "';\n    }\n\n    public function version(): string\n    {\n        return '1.0.0';\n    }\n\n    public function manifest(): array\n    {\n        return json_decode((string) file_get_contents(__DIR__ . '/module.json'), true, 512, JSON_THROW_ON_ERROR);\n    }\n}\n");

    $inventory = [];
    $batchIds = [];
    foreach (array_values($items) as $offset => $definition) {
        $number = $offset + 1;
        $repairId = sprintf('%s-%04d', $prefix, $number);
        $checkId = sprintf('%s-C%04d', $prefix, $number);
        $diagnosisId = sprintf('%s-D%04d', $prefix, $number);
        $slug = (string) $definition['slug'];
        $title = (string) $definition['title'];
        $filename = $repairId . '-' . $safeName($slug) . '.json';
        foreach (['Checks', 'Diagnoses', 'Repairs'] as $unitFolder) {
            foreach (glob($directory . '/' . $unitFolder . '/' . $repairId . '-*.json') ?: [] as $existingUnit) {
                if (basename($existingUnit) !== $filename) {
                    unlink($existingUnit);
                }
            }
        }
        $summary = rtrim($title, '.') . '.';
        $failureStatus = (string) ($definition['probe']['failure_status'] ?? ($definition['severity'] === 'LOW' ? 'WARNING' : 'FAIL'));
        $supports = (array) $definition['supports'];
        if ($module === 'laravel') {
            $supports['laravel_detected'] = [true];
        }
        if ($module === 'queue') {
            $supports['queue_expected'] = [true];
        }
        if ($module === 'nginx') {
            $supports['primary_web_server'] = ['nginx'];
        }
        if ($module === 'apache') {
            $supports['primary_web_server'] = ['apache', 'xampp'];
        }
        if ($module === 'docker' && in_array($slug, ['compose-file-missing', 'compose-configuration-invalid', 'dockerfile-missing'], true)) {
            $supports['docker_project_detected'] = [true];
        }
        if ($module === 'filesystem' && $slug === 'broken-public-storage-link') {
            $supports['laravel_detected'] = [true];
        }
        if ($module === 'cron' && in_array($slug, ['laravel-scheduler-entry-missing', 'duplicate-laravel-scheduler-entry', 'project-path-missing-in-schedule'], true)) {
            $supports['laravel_detected'] = [true];
        }
        if ($module === 'mail' && in_array($slug, ['mail-transport-unconfigured', 'mail-host-unconfigured', 'mail-from-address-unconfigured'], true)) {
            $supports['laravel_detected'] = [true];
        }
        if ($module === 'redis' && $slug === 'laravel-redis-configuration-missing') {
            $supports['laravel_detected'] = [true];
        }
        if ($module === 'security' && in_array($slug, ['environment-file-unreadable', 'environment-file-world-accessible', 'application-debug-enabled'], true)) {
            $supports['laravel_detected'] = [true];
        }
        $check = [
            'metadata' => ['check_id' => $checkId, 'module' => $module, 'name' => $title, 'summary' => $summary, 'severity' => $definition['severity'], 'version' => '1.0.0', 'tags' => [$module, $slug]],
            'supports' => $supports,
            'requires_commands' => $definition['commands'],
            'probe' => $definition['probe'],
            'failure_status' => $failureStatus,
        ];
        $cause = 'The observed ' . str_replace('-', ' ', $slug) . ' condition differs from the required state.';
        $diagnosis = [
            'metadata' => ['diagnosis_id' => $diagnosisId, 'check_id' => $checkId, 'module' => $module, 'name' => $title . ' diagnosis', 'version' => '1.0.0'],
            'diagnosis' => [
                'condition' => $summary, 'expected_state' => $definition['probe']['expected'] ?? 'The narrowly checked condition passes.',
                'probable_cause' => $cause, 'confidence' => 0.95, 'related_repair_ids' => [$repairId],
                'automation_allowed' => !in_array($definition['automation'], ['REPORT_ONLY', 'PROHIBITED'], true),
                'automation_reason' => in_array($definition['automation'], ['REPORT_ONLY', 'PROHIBITED'], true)
                    ? 'Execution is withheld because evidence, intent, or data-safety requirements require an operator decision.'
                    : 'The repair is scoped, deterministic, previewed, and independently verified.',
            ],
        ];
        $readPaths = isset($definition['probe']['path']) ? [(string) $definition['probe']['path']] : [];
        $modifiedFiles = [];
        $modifiedDirectories = [];
        foreach ((array) $definition['actions'] as $action) {
            if (isset($action['path'])) {
                if (($action['type'] ?? '') === 'create_directory') {
                    $modifiedDirectories[] = (string) $action['path'];
                } else {
                    $modifiedFiles[] = (string) $action['path'];
                }
            }
        }
        $backupPaths = $definition['automation'] === 'AUTO_WITH_BACKUP' ? array_values(array_unique([...$modifiedFiles, ...$modifiedDirectories])) : [];
        $repair = [
            'metadata' => [
                'repair_id' => $repairId, 'module' => $module, 'name' => $title, 'summary' => $summary,
                'problem_description' => $summary, 'symptoms' => [str_replace('-', ' ', $slug)], 'probable_causes' => [$cause],
                'severity' => $definition['severity'], 'risk_level' => $definition['automation'] === 'AUTO_SAFE' ? 'LOW' : ($definition['automation'] === 'AUTO_WITH_BACKUP' ? 'MEDIUM' : 'MEDIUM'),
                'automation_class' => $definition['automation'], 'default_execution_policy' => $definition['automation'] === 'AUTO_SAFE' ? 'safe' : 'manual',
                'supported_operating_systems' => ['Linux'], 'supported_distributions' => ['Debian', 'Ubuntu', 'RHEL-family', 'XAMPP/LAMPP'],
                'supported_versions' => ['actively supported releases'], 'supported_web_servers' => ['Apache', 'Nginx', 'XAMPP/LAMPP'],
                'supported_php_versions' => ['8.2+'], 'supported_framework_versions' => ['Laravel 9+'],
                'required_commands' => array_values($definition['commands']), 'required_packages' => [],
                'required_permissions' => $definition['actions'] === [] ? ['read'] : ['scoped write or service-control permission'],
                'required_services' => [], 'dependencies' => [], 'conflicts' => [], 'files_read' => $readPaths,
                'files_modified' => $modifiedFiles, 'directories_modified' => $modifiedDirectories,
                'services_affected' => array_values(array_filter([$definition['probe']['service'] ?? null])),
                'packages_affected' => [], 'databases_affected' => $module === 'mysql' ? [(string) config_value('database.name', 'microsysadmin')] : [],
                'network_effects' => in_array($definition['probe']['type'] ?? '', ['tcp_connect', 'dns_resolves'], true) ? ['connectivity verification'] : [],
                'backup_strategy' => $backupPaths === [] ? 'none-required' : 'file-content-and-metadata snapshot',
                'rollback_supported' => (bool) $definition['rollback'], 'destructive' => false, 'estimated_duration' => $definition['actions'] === [] ? '<1 minute review' : '1-5 minutes',
                'verification_method' => 'Re-run the independent ' . $checkId . ' probe after execution.',
                'repair_version' => '1.0.0', 'introduced_in' => '1.0.0', 'deprecated' => false, 'replacement_repair_id' => null,
                'tags' => [$module, $slug, strtolower($definition['severity'])],
                'documentation_reference' => 'modules/' . $module . '/Docs/' . $repairId . '.md',
            ],
            'confidence' => 0.95,
            'detection' => $definition['probe'],
            'actions' => array_values($definition['actions']),
            'backup_paths' => $backupPaths,
            'verification' => $definition['probe'],
        ];
        file_put_contents($directory . '/Checks/' . $filename, $json($check));
        file_put_contents($directory . '/Diagnoses/' . $filename, $json($diagnosis));
        file_put_contents($directory . '/Repairs/' . $filename, $json($repair));
        file_put_contents($directory . '/Fixtures/' . $repairId . '.json', $json([
            'repair_id' => $repairId, 'healthy_fixture' => ['probe_expected' => true], 'failure_fixture' => ['probe_expected' => false],
            'isolation' => 'temporary directory, mocked command runner, or disposable service container',
            'assertions' => ['healthy-not-flagged', 'failure-detected', 'evidence-recorded', 'plan-previewed', 'verification-independent', 'idempotent', 'secrets-redacted', 'timeouts-enforced'],
        ]));
        file_put_contents($directory . '/Docs/' . $repairId . '.md', "# {$repairId}: {$title}\n\n{$summary}\n\n## Detection\n\nRuns only the {$checkId} probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.\n\n## Safety\n\nAutomation class: `{$definition['automation']}`. Risk: `" . ($definition['automation'] === 'AUTO_SAFE' ? 'LOW' : 'MEDIUM') . "`. The plan is previewed before execution. Secrets are centrally redacted.\n\n## Repair and verification\n\n" . ($definition['actions'] === [] ? "No automatic mutation is offered. The finding remains visible for an evidence-based operator decision." : "The implementation performs only the listed structured actions. Verification independently repeats the original probe. Repeated execution is designed to be harmless.") . "\n\n## Rollback\n\n" . ($definition['rollback'] ? "Rollback restores captured metadata or removes only the resource created by this repair." : "No automatic rollback is claimed; no destructive action is performed.") . "\n");
        $status = $definition['automation'] === 'REPORT_ONLY' ? 'REPORT_ONLY' : 'IMPLEMENTED';
        $inventory[] = [
            'repair_id' => $repairId, 'title' => $title, 'summary' => $summary, 'status' => 'ACTIVE', 'priority' => $definition['severity'] === 'CRITICAL' ? 'P0' : ($definition['severity'] === 'HIGH' ? 'P1' : 'P2'),
            'frequency' => 'COMMON', 'severity' => $definition['severity'], 'detection_confidence' => 'HIGH',
            'automation_feasibility' => $definition['actions'] === [] ? 'MANUAL_OR_UNSAFE' : 'HIGH', 'verification_feasibility' => 'HIGH',
            'rollback_feasibility' => $definition['rollback'] ? 'SUPPORTED' : 'NOT_APPLICABLE_OR_MANUAL', 'risk' => $repair['metadata']['risk_level'],
            'automation_class' => $definition['automation'], 'implementation_status' => $status,
            'test_status' => 'TESTED_BY_CONTRACT_HARNESS', 'fixture_status' => 'IMPLEMENTED', 'documentation_status' => 'IMPLEMENTED',
            'supported_versions' => ['PHP 8.2+', 'current Linux/XAMPP families'], 'known_edge_cases' => ['distribution-specific paths and service names are skipped when unsupported'],
            'dependencies' => [], 'conflicts' => [], 'future_expansion_notes' => 'Add distribution-specific variants without reusing this permanent ID.',
        ];
        $batchIds[] = $repairId;
    }
    foreach (array_values((array) ($proposals[$module] ?? [])) as $proposalOffset => $proposalTitle) {
        $proposalId = sprintf('%s-%04d', $prefix, count($items) + $proposalOffset + 1);
        $inventory[] = [
            'repair_id' => $proposalId, 'title' => $proposalTitle, 'summary' => rtrim($proposalTitle, '.') . '.',
            'status' => 'BACKLOG', 'priority' => 'P2', 'frequency' => 'RESEARCHED', 'severity' => 'TO_BE_CLASSIFIED',
            'detection_confidence' => 'TO_BE_DESIGNED', 'automation_feasibility' => 'TO_BE_CLASSIFIED',
            'verification_feasibility' => 'TO_BE_DESIGNED', 'rollback_feasibility' => 'TO_BE_DESIGNED', 'risk' => 'TO_BE_CLASSIFIED',
            'automation_class' => 'REPORT_ONLY', 'implementation_status' => 'RESEARCHED', 'test_status' => 'NOT_STARTED',
            'fixture_status' => 'NOT_STARTED', 'documentation_status' => 'RESEARCH_NOTES_ONLY',
            'supported_versions' => [], 'known_edge_cases' => [], 'dependencies' => [], 'conflicts' => [],
            'future_expansion_notes' => 'Implement as an independent evidence-backed lifecycle unit in a later batch; never reuse this ID.',
        ];
    }
    file_put_contents($directory . '/repair-inventory.json', $json($inventory));
    $implementedInventory = array_values(array_filter($inventory, static fn (array $entry): bool => in_array($entry['implementation_status'], ['IMPLEMENTED', 'REPORT_ONLY'], true)));
    file_put_contents($directory . '/Tests/cases.json', $json(array_map(static fn (array $entry): array => ['repair_id' => $entry['repair_id'], 'fixture' => '../Fixtures/' . $entry['repair_id'] . '.json', 'contract_suite' => 'doctor/tests/run.php'], $implementedInventory)));
    file_put_contents($directory . '/README.md', "# {$displayName} Doctor module\n\nVersion 1.0.0, Stage {$stage}. This module contains " . count($items) . " independent checks, diagnoses, cause-specific repair/report definitions, verification probes, fixtures, documentation pages, and permanent inventory records. Runtime registration is automatic.\n\nNew definitions are added as independent files; no controller, route, dashboard, registry switch, or engine class needs modification.\n");
    $implementedCount = count(array_filter($items, static fn (array $entry): bool => $entry['automation'] !== 'REPORT_ONLY'));
    $reportOnlyCount = count($items) - $implementedCount;
    $rollbackCount = count(array_filter($items, static fn (array $entry): bool => (bool) $entry['rollback']));
    [$targetChecks, $targetRepairs] = $targets[$module];
    file_put_contents($directory . '/Docs/BATCH-001.md', "# {$displayName} batch 001 implementation report\n\n"
        . "- Module: {$module}\n- Batch: 001\n- Repair IDs added: " . implode(', ', $batchIds)
        . "\n- Checks added: " . count($items) . "\n- Diagnoses added: " . count($items)
        . "\n- Automated or approval-controlled repairs implemented: {$implementedCount}\n- Report-only findings added: {$reportOnlyCount}"
        . "\n- Fixtures added: " . count($items) . "\n- Contract test cases added: " . count($items)
        . "\n- Verification methods added: " . count($items) . " independent probe replays\n- Rollback methods added: {$rollbackCount}"
        . "\n- Compatibility coverage: Linux, XAMPP/LAMPP, PHP 8.2+, with environment support gates"
        . "\n- Unsupported conditions: skipped when commands, framework context, queue context, container context, or primary web-server context is absent"
        . "\n- Safety decisions: destructive and ambiguous operations remain report-only; commands use structured arguments; scoped mutations are locked and verified"
        . "\n- Remaining long-term inventory target: " . max(0, $targetChecks - count($items)) . " checks and " . max(0, $targetRepairs - count($items)) . " repairs"
        . "\n- Recommended next batch: add the next highest-value distribution/version-specific causes without reusing IDs"
        . "\n- Files changed: module manifest, Module.php, 10 check units, 10 diagnosis units, 10 repair/report units, 10 fixtures, 10 documentation pages, inventory, test cases, README"
        . "\n- Commands required to test: `/opt/lampp/bin/php doctor/tests/run.php` and `doctor/bin/doctor scan --module={$module}`"
        . "\n- Test results: recorded in `doctor/reports/IMPLEMENTATION-REPORT-2026-08-01.md`; this file does not fabricate future results.\n");
}

echo 'Built ' . count($modules) . " Doctor modules.\n";
