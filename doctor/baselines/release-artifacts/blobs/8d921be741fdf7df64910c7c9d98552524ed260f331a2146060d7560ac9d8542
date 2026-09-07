<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;

final readonly class CommandPrimitive implements RepairPrimitive
{
    public function types(): array { return ['run_command']; }

    public function descriptor(string $type): array
    {
        return ['type' => $type, 'category' => 'command', 'local_only' => true, 'structured_arguments' => true, 'reversible' => 'definition-dependent', 'rollback' => 'backup-manager-or-compensating-action', 'safety_class' => 'structured-command', 'required_fields' => ['command']];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $command = array_map('strval', (array) ($action['command'] ?? []));
        if ($command === []) {
            throw new \RuntimeException('Repair command is empty.');
        }
        if (!str_contains($command[0], '/')) {
            $command[0] = $context->environment->command($command[0]) ?? $command[0];
        }
        $result = $context->process->run(
            $command,
            (int) ($action['timeout'] ?? 60),
            isset($action['cwd']) ? (string) $action['cwd'] : null,
            (array) ($action['environment'] ?? []),
            isset($action['service_user']) ? (string) $action['service_user'] : null,
        );
        if (!$result->successful()) {
            $detail = trim((string) $result->get('stderr')) ?: trim((string) $result->get('stdout'));
            $detail = $detail !== '' ? $detail : 'exit code ' . (string) ($result->get('exit_code') ?? 'unknown') . '; the command returned no output';
            throw new \RuntimeException('Repair command failed: ' . $detail);
        }
        return ['type' => 'command', 'command' => $result->get('command'), 'created' => false, 'result' => $result->toArray(), 'description' => $action['description'] ?? 'Executed command.'];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        return ($change['type'] ?? '') === 'command';
    }
}
