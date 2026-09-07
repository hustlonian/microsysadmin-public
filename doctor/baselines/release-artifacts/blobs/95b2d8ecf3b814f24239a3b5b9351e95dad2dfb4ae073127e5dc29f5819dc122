<?php

declare(strict_types=1);

namespace SysAdmin\Drivers;

use SysAdmin\AuditLogger;
use SysAdmin\Contracts\ServiceControlDriverInterface;
use SysAdmin\ProcessExecutor;

final class SystemdServiceControlDriver implements ServiceControlDriverInterface
{
    private const UNITS = [
        'apache' => ['apache2.service', 'httpd.service'],
        'mysql' => ['mysql.service', 'mariadb.service'],
        'ssh' => ['ssh.service', 'sshd.service', 'ssh.socket'],
        'cron' => ['cron.service', 'crond.service'],
        'php-fpm' => ['php8.4-fpm.service', 'php8.3-fpm.service', 'php8.2-fpm.service', 'php-fpm.service'],
        'docker' => ['docker.service'],
        'nginx' => ['nginx.service'],
        'postfix' => ['postfix.service'],
        'redis' => ['redis-server.service', 'redis.service'],
        'supervisor' => ['supervisor.service', 'supervisord.service'],
    ];

    public function name(): string
    {
        return 'systemd';
    }

    public function services(): array
    {
        return array_keys(self::UNITS);
    }

    public function actions(string $service): array
    {
        return isset(self::UNITS[$service])
            ? ['status', 'start', 'stop', 'restart', 'reload', 'enable', 'disable']
            : [];
    }

    public function status(string $service): array
    {
        return $this->perform($service, 'status');
    }

    public function perform(string $service, string $action): array
    {
        if (!in_array($action, $this->actions($service), true)) {
            return $this->unavailable($service, $action, 'The systemd driver does not support this service or action.');
        }

        $unit = $this->installedUnit($service);
        if ($unit === null) {
            return $this->unavailable($service, $action, 'No matching systemd unit is installed.');
        }

        $command = $action === 'status'
            ? ['/usr/bin/systemctl', 'is-active', $unit]
            : ['/usr/bin/sudo', '-n', '/usr/bin/systemctl', $action, $unit];
        if ($action !== 'status') {
            AuditLogger::info('service.control', [
                'driver' => $this->name(),
                'service' => $service,
                'unit' => $unit,
                'action' => $action,
            ]);
        }
        $result = (new ProcessExecutor())->run($command, 30);
        $running = $action === 'status' && trim((string) $result['output']) === 'active';
        $enabled = null;
        if ($action === 'status') {
            $enabledResult = (new ProcessExecutor())->run(['/usr/bin/systemctl', 'is-enabled', $unit], 10);
            $enabled = $enabledResult['ok'] && trim((string) $enabledResult['output']) === 'enabled';
        }

        return [
            ...$result,
            'supported' => true,
            'driver' => $this->name(),
            'service' => $service,
            'unit' => $unit,
            'action' => $action,
            'running' => $running,
            'enabled' => $enabled,
            'label' => $action === 'status' ? ($running ? 'Running' : 'Stopped') : ($result['ok'] ? 'Action completed' : 'Action failed'),
            'command' => 'systemctl ' . $action . ' ' . $unit,
        ];
    }

    private function installedUnit(string $service): ?string
    {
        foreach (self::UNITS[$service] ?? [] as $unit) {
            $result = (new ProcessExecutor())->run(
                ['/usr/bin/systemctl', 'show', '--property=LoadState', '--value', $unit],
                5
            );
            if ($result['ok'] && trim((string) $result['output']) === 'loaded') {
                return $unit;
            }
        }
        return null;
    }

    private function unavailable(string $service, string $action, string $message): array
    {
        return [
            'ok' => false,
            'supported' => false,
            'driver' => $this->name(),
            'service' => $service,
            'action' => $action,
            'running' => false,
            'label' => 'Unavailable',
            'output' => '',
            'error' => $message,
            'exit_code' => 64,
        ];
    }
}
