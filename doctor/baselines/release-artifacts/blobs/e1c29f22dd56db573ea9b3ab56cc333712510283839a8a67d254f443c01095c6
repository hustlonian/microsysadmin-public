<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Discovery;

use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Process\ProcessRunner;

final readonly class EnvironmentDiscovery
{
    public function __construct(private ProcessRunner $process)
    {
    }

    public function discover(string $projectPath): EnvironmentContext
    {
        $os = $this->osRelease();
        $commands = [];
        foreach ([
            'php', 'composer', 'git', 'systemctl', 'service', 'apache2ctl', 'apachectl',
            'httpd', 'nginx', 'mysql', 'mariadb', 'mysqld', 'redis-cli', 'redis-server',
            'docker', 'openssl', 'certbot', 'supervisorctl', 'crontab', 'dig', 'nslookup',
            'sendmail', 'postfix', 'df', 'free', 'uptime', 'ss', 'findmnt', 'timedatectl', 'lsof',
            'mysqldump', 'tar', 'stat', 'ufw', 'nft', 'iptables', 'firewall-cmd',
        ] as $name) {
            $commands[$name] = $this->locate($name);
        }
        $xamppRoot = is_executable('/opt/lampp/lampp') ? '/opt/lampp' : null;
        if ($xamppRoot !== null) {
            $commands['xampp'] = '/opt/lampp/lampp';
            $commands['xampp-php'] = is_executable('/opt/lampp/bin/php') ? '/opt/lampp/bin/php' : null;
            $commands['xampp-mysql'] = is_executable('/opt/lampp/bin/mysql') ? '/opt/lampp/bin/mysql' : null;
            $commands['xampp-httpd'] = is_executable('/opt/lampp/bin/httpd') ? '/opt/lampp/bin/httpd' : null;
        }
        $php = $commands['xampp-php'] ?? $commands['php'];
        $phpVersion = PHP_VERSION;
        if (is_string($php) && $php !== '') {
            $result = $this->process->run([$php, '-r', 'echo PHP_VERSION;'], 5);
            if ($result->successful()) {
                $phpVersion = trim((string) $result->get('stdout'));
            }
        }
        $projectReal = realpath($projectPath) ?: $projectPath;
        $laravel = is_file($projectReal . '/artisan') && is_file($projectReal . '/composer.json');
        $queueConnection = $laravel ? strtolower($this->environmentSetting($projectReal . '/.env', 'QUEUE_CONNECTION')) : '';
        $serviceDriver = strtolower((string) \SysAdmin\EnvironmentConfig::get('service_control_driver', $xamppRoot ? 'xampp' : 'systemd'));
        $primaryWebServer = $serviceDriver === 'xampp' ? 'xampp' : ((bool) $commands['nginx'] && !(bool) ($commands['apache2ctl'] ?? $commands['apachectl'] ?? $commands['httpd']) ? 'nginx' : 'apache');
        $securityProfile = strtolower((string) config_value('installation.security_profile', 'safe'));
        $managedReleaseIntegrity = $projectReal === (realpath(APP_ROOT) ?: APP_ROOT)
            && $securityProfile !== 'developer';

        return new EnvironmentContext([
            'hostname' => gethostname() ?: 'localhost',
            'kernel' => php_uname('s') . ' ' . php_uname('r'),
            'architecture' => php_uname('m'),
            'os_id' => strtolower((string) ($os['ID'] ?? 'unknown')),
            'os_name' => (string) ($os['PRETTY_NAME'] ?? php_uname('s')),
            'os_version' => (string) ($os['VERSION_ID'] ?? ''),
            'os_family' => $this->osFamily((string) ($os['ID'] ?? ''), (string) ($os['ID_LIKE'] ?? '')),
            'service_manager' => $commands['systemctl'] ? 'systemd' : ($xamppRoot ? 'xampp' : 'unknown'),
            'package_manager' => $this->packageManager(),
            'xampp_root' => $xamppRoot,
            'php_version' => $phpVersion,
            'php_sapi' => PHP_SAPI,
            'project_path' => $projectReal,
            'laravel_detected' => $laravel,
            'apache_detected' => (bool) ($commands['apache2ctl'] ?? $commands['apachectl'] ?? $commands['httpd'] ?? $commands['xampp-httpd']),
            'nginx_detected' => (bool) $commands['nginx'],
            'docker_detected' => (bool) $commands['docker'],
            'docker_project_detected' => is_file($projectReal . '/Dockerfile') || is_file($projectReal . '/compose.yaml') || is_file($projectReal . '/docker-compose.yml'),
            'primary_web_server' => $primaryWebServer,
            'queue_expected' => $laravel && $queueConnection !== '' && !in_array($queueConnection, ['sync', 'null'], true),
            'managed_release_integrity' => $managedReleaseIntegrity,
            'commands' => array_filter($commands, static fn (mixed $value): bool => is_string($value) && $value !== ''),
            'discovered_at' => gmdate(DATE_ATOM),
        ]);
    }

    private function osRelease(): array
    {
        $data = [];
        if (!is_readable('/etc/os-release')) {
            return $data;
        }
        foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            if (preg_match('/^[A-Z_]+$/', $key)) {
                $data[$key] = trim($value, " \t\n\r\0\x0B\"");
            }
        }
        return $data;
    }

    private function locate(string $name): ?string
    {
        $paths = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        foreach (array_unique([...$paths, '/usr/bin', '/usr/sbin', '/bin', '/sbin', '/usr/local/bin', '/usr/local/sbin']) as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function osFamily(string $id, string $like): string
    {
        $value = strtolower($id . ' ' . $like);
        return match (true) {
            str_contains($value, 'debian'), str_contains($value, 'ubuntu') => 'debian',
            str_contains($value, 'rhel'), str_contains($value, 'fedora'), str_contains($value, 'centos'), str_contains($value, 'rocky'), str_contains($value, 'alma') => 'rhel',
            str_contains($value, 'arch') => 'arch',
            str_contains($value, 'suse') => 'suse',
            str_contains($value, 'alpine') => 'alpine',
            default => 'unknown',
        };
    }

    private function packageManager(): string
    {
        foreach (['apt', 'dnf', 'yum', 'pacman', 'zypper', 'apk'] as $name) {
            if ($this->locate($name) !== null) {
                return $name;
            }
        }
        return 'unknown';
    }

    private function environmentSetting(string $path, string $key): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$candidate, $value] = explode('=', $line, 2);
            if (trim($candidate) === $key) {
                return trim($value, " \t\n\r\0\x0B\"'");
            }
        }
        return '';
    }
}
