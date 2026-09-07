<?php

declare(strict_types=1);

namespace SysAdmin\Drivers;

use SysAdmin\Contracts\ServiceControlDriverInterface;

final class InspectionServiceControlDriver implements ServiceControlDriverInterface
{
    private const PROCESSES = [
        'apache' => ['httpd', 'apache2'],
        'mysql' => ['mysqld', 'mariadbd'],
        'ssh' => ['sshd'],
        'cron' => ['cron', 'crond'],
        'php-fpm' => ['php-fpm'],
        'docker' => ['dockerd'],
        'nginx' => ['nginx'],
        'postfix' => ['master', 'postfix'],
        'redis' => ['redis-server'],
        'supervisor' => ['supervisord'],
    ];

    public function name(): string
    {
        return 'inspection';
    }

    public function services(): array
    {
        return array_keys(self::PROCESSES);
    }

    public function actions(string $service): array
    {
        return in_array($service, $this->services(), true) ? ['status'] : [];
    }

    public function status(string $service): array
    {
        if (!isset(self::PROCESSES[$service])) {
            return $this->unsupported($service, 'status');
        }

        $running = false;
        foreach (glob('/proc/[0-9]*/comm') ?: [] as $file) {
            $process = trim((string) @file_get_contents($file));
            $commandLine = (string) @file_get_contents(dirname($file) . '/cmdline');
            $argv0 = strtok($commandLine, "\0");
            $executable = $argv0 !== false ? basename($argv0) : '';
            foreach (self::PROCESSES[$service] as $candidate) {
                if (
                    $process === $candidate
                    || str_contains($process, $candidate)
                    || $executable === $candidate
                    || str_contains($executable, $candidate)
                ) {
                    $running = true;
                    break 2;
                }
            }
        }

        return [
            'ok' => true,
            'supported' => true,
            'driver' => $this->name(),
            'service' => $service,
            'action' => 'status',
            'running' => $running,
            'enabled' => null,
            'label' => $running ? 'Running' : 'Stopped or unavailable',
            'output' => $running ? "{$service} process detected." : "{$service} process not detected.",
            'error' => '',
            'exit_code' => 0,
        ];
    }

    public function perform(string $service, string $action): array
    {
        if ($action === 'status') {
            return $this->status($service);
        }
        return $this->unsupported($service, $action);
    }

    private function unsupported(string $service, string $action): array
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
            'error' => 'The inspection-only driver does not support this service action.',
            'exit_code' => 64,
        ];
    }
}
