<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\ServiceControlDriverInterface;
use SysAdmin\Drivers\InspectionServiceControlDriver;
use SysAdmin\Drivers\SystemdServiceControlDriver;
use SysAdmin\Drivers\XamppServiceControlDriver;

final class ServiceControlManager
{
    public const LABELS = [
        'apache' => 'Apache',
        'mysql' => 'MySQL',
        'ssh' => 'SSH',
        'cron' => 'Cron',
        'php-fpm' => 'PHP-FPM',
        'docker' => 'Docker',
        'nginx' => 'Nginx',
        'postfix' => 'Postfix',
        'redis' => 'Redis',
        'supervisor' => 'Supervisor',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function states(): array
    {
        $inspection = new InspectionServiceControlDriver();
        $states = [];
        foreach (self::LABELS as $service => $label) {
            $state = $inspection->status($service);
            $driver = $this->driverFor($service);
            $state['control_driver'] = $driver->name();
            $state['actions'] = $driver->actions($service);
            $state['service_label'] = $label;
            $states[$service] = $state;
        }
        return $states;
    }

    /**
     * @return array<string, mixed>
     */
    public function perform(string $service, string $action): array
    {
        if (!isset(self::LABELS[$service])) {
            throw new \InvalidArgumentException('Unknown service.');
        }
        if (!in_array($action, ['status', 'start', 'stop', 'restart', 'reload', 'enable', 'disable'], true)) {
            throw new \InvalidArgumentException('Unknown service action.');
        }

        if ($action !== 'status') {
            SecurityProfile::assertAllowed('service_mutation');
        }

        return $this->driverFor($service)->perform($service, $action);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach (self::LABELS as $service => $label) {
            $driver = $this->driverFor($service);
            $definitions[$service] = [
                'label' => $label,
                'driver' => $driver->name(),
                'actions' => $driver->actions($service),
            ];
        }
        return $definitions;
    }

    private function driverFor(string $service): ServiceControlDriverInterface
    {
        $configured = strtolower((string) EnvironmentConfig::get('service_control_driver', 'inspection'));
        if ($configured === 'inspection') {
            return new InspectionServiceControlDriver();
        }
        if ($configured === 'systemd') {
            return new SystemdServiceControlDriver();
        }
        if ($configured === 'xampp' && in_array($service, ['apache', 'mysql'], true)) {
            return new XamppServiceControlDriver();
        }
        if ($configured === 'xampp') {
            return new SystemdServiceControlDriver();
        }

        return new InspectionServiceControlDriver();
    }
}
