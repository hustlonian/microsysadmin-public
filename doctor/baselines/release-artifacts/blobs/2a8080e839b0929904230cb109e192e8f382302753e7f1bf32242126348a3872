<?php

declare(strict_types=1);

namespace SysAdmin\Drivers;

use SysAdmin\AuditLogger;
use SysAdmin\Contracts\ServiceControlDriverInterface;
use SysAdmin\EnvironmentConfig;
use SysAdmin\ProcessExecutor;

final class XamppServiceControlDriver implements ServiceControlDriverInterface
{
    public function name(): string
    {
        return 'xampp';
    }

    public function services(): array
    {
        return ['apache', 'mysql'];
    }

    public function actions(string $service): array
    {
        return in_array($service, $this->services(), true)
            ? ['status', 'start', 'stop', 'restart', 'reload']
            : [];
    }

    public function status(string $service): array
    {
        return $this->perform($service, 'status');
    }

    public function perform(string $service, string $action): array
    {
        if (!in_array($action, $this->actions($service), true)) {
            return $this->unsupported($service, $action);
        }

        $helper = EnvironmentConfig::path('privileged_helper_path');
        if ($helper === '' || !is_file($helper)) {
            return $this->unavailable($service, $action, 'The configured privileged helper is missing.');
        }

        $command = ['/usr/bin/sudo', '-n', $helper, $service, $action];
        AuditLogger::info('service.control', [
            'driver' => $this->name(),
            'service' => $service,
            'action' => $action,
        ]);
        $result = (new ProcessExecutor())->run($command, 30, null, [
            'MICROSYSADMIN_XAMPP_ROOT' => EnvironmentConfig::path('xampp_root'),
            'MICROSYSADMIN_APP_ROOT' => APP_ROOT,
            'MICROSYSADMIN_STORAGE_ROOT' => EnvironmentConfig::path('storage_root', STORAGE_PATH),
            'PATH' => (string) getenv('PATH'),
        ]);

        return [
            ...$result,
            'supported' => true,
            'driver' => $this->name(),
            'service' => $service,
            'action' => $action,
            'running' => $action === 'status' && $result['ok'] && !str_contains(strtolower($result['output'] . $result['error']), 'not running'),
            'label' => $result['ok'] ? 'Action accepted' : 'Action failed',
            'command' => 'sudo [privileged-helper] ' . $service . ' ' . $action,
        ];
    }

    private function unsupported(string $service, string $action): array
    {
        return $this->unavailable($service, $action, 'The XAMPP driver does not support this service or action.');
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
