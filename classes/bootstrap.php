<?php

declare(strict_types=1);

use SysAdmin\AuditLogger;
use SysAdmin\Auth;
use SysAdmin\Database;

define('APP_START', microtime(true));

$envPath = APP_ROOT . '/.env';
if (is_file($envPath) && is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

$configPath = APP_ROOT . '/config/config.php';
$GLOBALS['sysadmin_config_exists'] = is_file($configPath);
$GLOBALS['sysadmin_config'] = $GLOBALS['sysadmin_config_exists']
    ? require $configPath
    : (
        is_array($GLOBALS['sysadmin_prebootstrap_config'] ?? null)
            ? $GLOBALS['sysadmin_prebootstrap_config']
            : require APP_ROOT . '/config/config.sample.php'
    );

$configuredStorage = (string) ($GLOBALS['sysadmin_config']['environment']['storage_root'] ?? APP_ROOT . '/storage');
$configuredStorage = str_replace('{APP_ROOT}', APP_ROOT, $configuredStorage);
define('STORAGE_PATH', rtrim($configuredStorage, DIRECTORY_SEPARATOR));

$vendor = APP_ROOT . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
}

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'SysAdmin\\') !== 0) {
        return;
    }

    if (str_starts_with($class, 'SysAdmin\\Doctor\\')) {
        $doctorRelative = substr($class, strlen('SysAdmin\\Doctor\\'));
        $doctorPath = APP_ROOT . '/doctor/' . str_replace('\\', DIRECTORY_SEPARATOR, $doctorRelative) . '.php';
        if (is_file($doctorPath)) {
            require $doctorPath;
        }
        return;
    }

    $relative = substr($class, strlen('SysAdmin\\'));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $candidates = [
        APP_ROOT . '/controllers/' . preg_replace('~^Controllers/~', '', str_replace(DIRECTORY_SEPARATOR, '/', $relativePath)),
        APP_ROOT . '/models/' . preg_replace('~^Models/~', '', str_replace(DIRECTORY_SEPARATOR, '/', $relativePath)),
        APP_ROOT . '/classes/' . $relativePath,
        APP_ROOT . '/controllers/' . $relativePath,
        APP_ROOT . '/models/' . $relativePath,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            require $candidate;
            return;
        }
    }
});

require APP_ROOT . '/classes/helpers.php';
\SysAdmin\RequestContext::initialize();

$runtimeDirectories = [
    STORAGE_PATH,
    STORAGE_PATH . '/logs',
    (string) \SysAdmin\EnvironmentConfig::get('backup_root', STORAGE_PATH . '/backups'),
    STORAGE_PATH . '/cache',
    (string) \SysAdmin\EnvironmentConfig::get('trash_root', STORAGE_PATH . '/cache/trash'),
    (string) \SysAdmin\EnvironmentConfig::get('script_root', STORAGE_PATH . '/scripts'),
    (string) \SysAdmin\EnvironmentConfig::get('temporary_workspace_root', STORAGE_PATH . '/workspace'),
    STORAGE_PATH . '/workspace/code-runner',
    STORAGE_PATH . '/workspace/code-runner/php',
    STORAGE_PATH . '/workspace/code-runner/bash',
    STORAGE_PATH . '/workspace/code-runner/web',
];
foreach (array_unique($runtimeDirectories) as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

date_default_timezone_set($GLOBALS['sysadmin_config']['app']['timezone'] ?? 'UTC');
ini_set('serialize_precision', '-1');
\SysAdmin\Security::sendHeaders();

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$sessionSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if ($sessionSecure) {
    ini_set('session.cookie_secure', '1');
}
if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_url() . '/',
        'secure' => $sessionSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name($GLOBALS['sysadmin_config']['security']['session_name'] ?? 'SYSADMINSESSID');
}
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

set_exception_handler(static function (Throwable $exception): void {
    $status = $exception instanceof \SysAdmin\HttpException ? $exception->statusCode() : 500;
    try {
        AuditLogger::error('Unhandled exception', ['error' => $exception->getMessage()]);
    } catch (Throwable) {
        error_log($exception->getMessage());
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, \SysAdmin\Security::productionSafeMessage($exception, 'MicroSysAdmin command failed.') . PHP_EOL);
        exit(1);
    }

    http_response_code($status);
    if (is_json_request()) {
        json_response(['ok' => false, 'error' => \SysAdmin\Security::productionSafeMessage($exception, 'Server error')], $status);
    }

    $message = \SysAdmin\Security::productionSafeMessage($exception, 'A server error occurred.');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . e((string) config_value('app.name', 'MicroSysAdmin')) . ' Error</title><body><h1>' . e((string) config_value('app.name', 'MicroSysAdmin')) . ' Error</h1><p>' . e($message) . '</p></body></html>';
    exit;
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    try {
        AuditLogger::error('Fatal error', ['message' => $error['message'] ?? 'fatal error']);
    } catch (Throwable) {
    }
});

if ($GLOBALS['sysadmin_config_exists']) {
    Database::init($GLOBALS['sysadmin_config']);
    try {
        $savedTimezone = (string) (\SysAdmin\Models\Setting::all()['timezone'] ?? '');
        if ($savedTimezone !== '' && in_array($savedTimezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($savedTimezone);
        }
    } catch (\Throwable) {
    }
    Auth::restoreRememberedUser();
}
