<?php

declare(strict_types=1);

namespace SysAdmin;

final class EnvironmentDetector
{
    /**
     * @return array<string, mixed>
     */
    public function detect(): array
    {
        $xamppRoot = $this->firstDirectory([
            (string) getenv('XAMPP_ROOT'),
            '/opt/lampp',
            '/opt/xampp',
        ]) ?? '/opt/lampp';

        $documentRoots = array_values(array_filter(array_unique([
            is_dir($xamppRoot . '/htdocs') ? $xamppRoot . '/htdocs' : null,
            is_dir('/var/www/html') ? '/var/www/html' : null,
            is_dir('/srv/http') ? '/srv/http' : null,
        ])));
        $serviceAccount = $this->serviceAccount($xamppRoot);

        return [
            'detected_at' => gmdate(DATE_ATOM),
            'operating_system' => $this->operatingSystem(),
            'architecture' => php_uname('m'),
            'xampp_root' => $xamppRoot,
            'php_binary' => $this->binary([$xamppRoot . '/bin/php', PHP_BINARY], 'php'),
            'composer_binary' => $this->binary(['/usr/local/bin/composer', '/usr/bin/composer'], 'composer'),
            'mysql_binary' => $this->binary([$xamppRoot . '/bin/mysql', '/usr/bin/mysql', '/usr/bin/mariadb'], 'mysql'),
            'mysqldump_binary' => $this->binary([$xamppRoot . '/bin/mysqldump', '/usr/bin/mysqldump', '/usr/bin/mariadb-dump'], 'mysqldump'),
            'git_binary' => $this->binary(['/usr/bin/git', '/usr/local/bin/git'], 'git'),
            'bash_binary' => $this->binary(['/bin/bash', '/usr/bin/bash'], 'bash'),
            'ffmpeg_binary' => $this->binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', $xamppRoot . '/bin/ffmpeg'], 'ffmpeg'),
            'apache_binary' => $this->binary([$xamppRoot . '/bin/httpd', '/usr/sbin/apache2', '/usr/sbin/httpd'], 'apache2'),
            'document_roots' => $documentRoots ?: [dirname(APP_ROOT)],
            'allowed_filesystem_roots' => $documentRoots ?: [dirname(APP_ROOT)],
            'mysql_socket' => $this->firstFile([
                $xamppRoot . '/var/mysql/mysql.sock',
                '/run/mysqld/mysqld.sock',
                '/var/run/mysqld/mysqld.sock',
            ]) ?? '',
            'service_control_driver' => is_executable($xamppRoot . '/lampp') ? 'xampp' : (is_executable('/usr/bin/systemctl') ? 'systemd' : 'inspection'),
            'service_user' => $serviceAccount['user'],
            'service_group' => $serviceAccount['group'],
            'php_extensions' => get_loaded_extensions(),
        ];
    }

    /**
     * @return array{user:string,group:string}
     */
    private function serviceAccount(string $xamppRoot): array
    {
        $candidates = str_starts_with($xamppRoot, '/opt/lampp')
            ? ['daemon', 'www-data', 'apache', 'httpd']
            : ['www-data', 'apache', 'httpd', 'daemon'];
        foreach ($candidates as $candidate) {
            if (!function_exists('posix_getpwnam')) {
                continue;
            }
            $account = posix_getpwnam($candidate);
            if (!is_array($account)) {
                continue;
            }
            $group = function_exists('posix_getgrgid')
                ? posix_getgrgid((int) ($account['gid'] ?? -1))
                : false;
            return [
                'user' => $candidate,
                'group' => is_array($group) ? (string) $group['name'] : $candidate,
            ];
        }
        $user = get_current_user() ?: 'www-data';
        return ['user' => $user, 'group' => $user];
    }

    private function binary(array $candidates, string $fallback): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fallback;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private function firstDirectory(array $paths): ?string
    {
        foreach ($paths as $path) {
            if ($path !== '' && is_dir($path)) {
                return rtrim($path, DIRECTORY_SEPARATOR);
            }
        }
        return null;
    }

    private function firstFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path) || (file_exists($path) && @filetype($path) === 'socket')) {
                return $path;
            }
        }
        return null;
    }

    private function operatingSystem(): string
    {
        if (is_readable('/etc/os-release')) {
            $values = parse_ini_file('/etc/os-release') ?: [];
            return (string) ($values['PRETTY_NAME'] ?? $values['NAME'] ?? php_uname('s'));
        }

        return php_uname('s') . ' ' . php_uname('r');
    }
}
