<?php

declare(strict_types=1);

namespace SysAdmin;

final class EnvironmentConfig
{
    /**
     * Return a normalized environment value while preserving legacy configuration.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            if (!($GLOBALS['sysadmin_config_exists'] ?? false)) {
                throw new \RuntimeException('Database environment overrides are unavailable before installation.');
            }
            $settings = \SysAdmin\Models\Setting::all();
            $overrideKey = 'environment.' . $key;
            if (array_key_exists($overrideKey, $settings) && $settings[$overrideKey] !== '') {
                $override = (string) $settings[$overrideKey];
                if (in_array($key, ['document_roots', 'allowed_filesystem_roots'], true)) {
                    $decoded = json_decode($override, true);
                    if (is_array($decoded)) {
                        return self::expand($decoded);
                    }
                }
                if (in_array($key, ['mysql_port'], true)) {
                    return (int) $override;
                }
                if (in_array($key, ['terminal_available'], true)) {
                    return $override === '1';
                }
                return self::expand($override);
            }
        } catch (\Throwable) {
        }

        $configured = config_value('environment.' . $key);
        if ($configured !== null && $configured !== '') {
            return self::expand($configured);
        }

        $legacy = match ($key) {
            'xampp_root' => self::legacyXamppRoot(),
            'php_binary' => config_value('hosting.php_binary', self::legacyXamppRoot() . '/bin/php'),
            'composer_binary' => config_value('hosting.composer_binary', '/usr/bin/composer'),
            'mysql_binary' => self::legacyXamppRoot() . '/bin/mysql',
            'mysqldump_binary' => self::legacyXamppRoot() . '/bin/mysqldump',
            'git_binary' => '/usr/bin/git',
            'bash_binary' => '/bin/bash',
            'ffmpeg_binary' => '/usr/bin/ffmpeg',
            'document_roots' => config_value('paths.website_roots', [dirname(APP_ROOT)]),
            'allowed_filesystem_roots' => config_value('paths.allowed_roots', [dirname(APP_ROOT)]),
            'storage_root' => defined('STORAGE_PATH') ? STORAGE_PATH : APP_ROOT . '/storage',
            'private_root' => '/var/lib/microsysadmin',
            'trash_root' => (defined('STORAGE_PATH') ? STORAGE_PATH : APP_ROOT . '/storage') . '/cache/trash',
            'backup_root' => config_value('paths.backup_root', (defined('STORAGE_PATH') ? STORAGE_PATH : APP_ROOT . '/storage') . '/backups'),
            'script_root' => (defined('STORAGE_PATH') ? STORAGE_PATH : APP_ROOT . '/storage') . '/scripts',
            'temporary_workspace_root' => (defined('STORAGE_PATH') ? STORAGE_PATH : APP_ROOT . '/storage') . '/workspace',
            'mysql_host' => config_value('database.host', '127.0.0.1'),
            'mysql_port' => (int) config_value('database.port', 3306),
            'mysql_socket' => config_value('database.socket', ''),
            'service_control_driver' => 'xampp',
            'privileged_helper_path' => APP_ROOT . '/scripts/service-control',
            'terminal_available' => (bool) config_value('security.terminal_enabled', false),
            default => $default,
        };

        return self::expand($legacy ?? $default);
    }

    public static function path(string $key, ?string $default = null): string
    {
        return rtrim((string) self::get($key, $default ?? ''), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<int, string>
     */
    public static function paths(string $key): array
    {
        $value = self::get($key, []);
        if (!is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $path) {
            $path = rtrim((string) self::expand($path), DIRECTORY_SEPARATOR);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $keys = [
            'xampp_root', 'php_binary', 'composer_binary', 'mysql_binary',
            'mysqldump_binary', 'git_binary', 'bash_binary', 'ffmpeg_binary',
            'document_roots', 'allowed_filesystem_roots', 'storage_root',
            'private_root', 'trash_root', 'backup_root', 'script_root',
            'temporary_workspace_root', 'mysql_host', 'mysql_port', 'mysql_socket',
            'service_control_driver', 'privileged_helper_path', 'terminal_available',
        ];

        $values = [];
        foreach ($keys as $key) {
            $values[$key] = in_array($key, ['document_roots', 'allowed_filesystem_roots'], true)
                ? self::paths($key)
                : self::get($key);
        }

        return $values;
    }

    /**
     * @return array<int, array{key:string,status:string,message:string}>
     */
    public static function diagnostics(): array
    {
        $checks = [];
        foreach ([
            'php_binary', 'composer_binary', 'mysql_binary', 'mysqldump_binary',
            'git_binary', 'bash_binary',
        ] as $key) {
            $path = self::path($key);
            $checks[] = [
                'key' => $key,
                'status' => is_file($path) && is_executable($path) ? 'ok' : 'error',
                'message' => $path !== '' ? $path : 'not configured',
            ];
        }

        foreach (['storage_root', 'private_root', 'backup_root', 'script_root', 'temporary_workspace_root'] as $key) {
            $path = self::path($key);
            $checks[] = [
                'key' => $key,
                'status' => is_dir($path) && is_writable($path) ? 'ok' : 'warning',
                'message' => $path !== '' ? $path : 'not configured',
            ];
        }

        return $checks;
    }

    public static function saveOverrides(array $input): array
    {
        $pathKeys = [
            'xampp_root', 'php_binary', 'composer_binary', 'mysql_binary',
            'mysqldump_binary', 'git_binary', 'bash_binary', 'ffmpeg_binary',
            'storage_root', 'private_root', 'trash_root', 'backup_root', 'script_root', 'temporary_workspace_root',
            'privileged_helper_path', 'mysql_socket',
        ];
        foreach ($pathKeys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = rtrim(trim((string) $input[$key]), DIRECTORY_SEPARATOR);
            if ($key === 'mysql_socket' && $value === '') {
                \SysAdmin\Models\Setting::set('environment.mysql_socket', '');
                continue;
            }
            if ($value === '' || str_contains($value, "\0") || !str_starts_with($value, '/')) {
                throw new \InvalidArgumentException("Environment path {$key} must be absolute.");
            }
            \SysAdmin\Models\Setting::set('environment.' . $key, $value);
        }

        foreach (['document_roots', 'allowed_filesystem_roots'] as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $lines = preg_split('/\R/', trim((string) $input[$key])) ?: [];
            $paths = [];
            foreach ($lines as $line) {
                $path = rtrim(trim($line), DIRECTORY_SEPARATOR);
                if ($path === '' || !str_starts_with($path, '/')) {
                    continue;
                }
                $paths[] = $path;
            }
            if ($paths === []) {
                throw new \InvalidArgumentException("Environment {$key} requires at least one absolute path.");
            }
            \SysAdmin\Models\Setting::set('environment.' . $key, json_encode(array_values(array_unique($paths)), JSON_UNESCAPED_SLASHES));
        }

        if (isset($input['service_control_driver'])) {
            $driver = strtolower((string) $input['service_control_driver']);
            if (!in_array($driver, ['xampp', 'systemd', 'inspection'], true)) {
                throw new \InvalidArgumentException('Service-control driver is invalid.');
            }
            \SysAdmin\Models\Setting::set('environment.service_control_driver', $driver);
        }
        if (array_key_exists('mysql_host', $input)) {
            $host = trim((string) $input['mysql_host']);
            if ($host === '' || strlen($host) > 255 || preg_match('/[\x00-\x20\/\\\\]/', $host)) {
                throw new \InvalidArgumentException('MySQL host is invalid.');
            }
            \SysAdmin\Models\Setting::set('environment.mysql_host', $host);
        }
        if (array_key_exists('mysql_port', $input)) {
            $port = filter_var($input['mysql_port'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            if ($port === false) {
                throw new \InvalidArgumentException('MySQL port is invalid.');
            }
            \SysAdmin\Models\Setting::set('environment.mysql_port', (string) $port);
        }
        if (array_key_exists('terminal_available_present', $input)) {
            \SysAdmin\Models\Setting::set('environment.terminal_available', !empty($input['terminal_available']) ? '1' : '0');
        }

        AuditLogger::warning('environment.overrides.updated', ['keys' => array_keys($input)]);
        return self::all();
    }

    private static function legacyXamppRoot(): string
    {
        $php = (string) config_value('hosting.php_binary', '/opt/lampp/bin/php');
        if (str_ends_with($php, '/bin/php')) {
            return dirname(dirname($php));
        }

        return '/opt/lampp';
    }

    private static function expand(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'expand'], $value);
        }
        if (!is_string($value)) {
            return $value;
        }

        $xampp = (string) config_value('environment.xampp_root', self::legacyXamppRoot());
        return str_replace(
            ['{APP_ROOT}', '{XAMPP_ROOT}'],
            [APP_ROOT, rtrim($xampp, DIRECTORY_SEPARATOR)],
            $value
        );
    }
}
