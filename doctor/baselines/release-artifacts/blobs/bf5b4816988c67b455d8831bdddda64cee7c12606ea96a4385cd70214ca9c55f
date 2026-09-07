<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\MachineAgentInterface;

final class DemoMachineAgent implements MachineAgentInterface
{
    public function version(): string
    {
        return 'v1-demo';
    }

    public function execute(string $action, array $payload = [], array $context = []): array
    {
        $data = DemoMode::data();
        return match ($action) {
            'metrics' => $data['metrics'],
            'health' => ['checks' => [
                ['name' => 'Demo isolation', 'ok' => true, 'status' => 'ok', 'message' => 'No host operations'],
                ['name' => 'Apache', 'ok' => true, 'status' => 'ok', 'message' => 'Simulated running'],
                ['name' => 'MySQL', 'ok' => true, 'status' => 'ok', 'message' => 'Simulated running'],
            ]],
            'processes' => $data['processes'],
            'services.status' => $data['services'],
            'websites.list' => $data['hosting'],
            'backups.list' => $data['backups'],
            'git.repositories' => $data['git'],
            'databases.list' => $data['databases'],
            'logs.read' => $data['logs'],
            'terminal.status' => ['enabled' => false, 'simulated' => true],
            default => [
                'simulated' => true,
                'action' => $action,
                'message' => 'No real operation was performed.',
                'output' => "$ microsysadmin-demo {$action}\nSimulated successfully.",
            ],
        };
    }
}
