<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Support\PrivilegedHelperClient;

final readonly class TimeSyncPrimitive implements RepairPrimitive
{
    public function __construct(private PrivilegedHelperClient $helper = new PrivilegedHelperClient())
    {
    }

    public function types(): array
    {
        return ['time_sync'];
    }

    public function descriptor(string $type): array
    {
        return [
            'type' => $type,
            'category' => 'linux',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => 'restore exact prior NTP-enabled state through scoped helper',
            'internal_backup' => true,
            'safety_class' => 'boolean-time-sync-state',
            'required_fields' => ['enabled'],
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        if (($action['enabled'] ?? null) !== true) {
            throw new \RuntimeException('Time-sync repair may only request the enabled state.');
        }
        if (!$this->capable($context)) {
            throw new \RuntimeException('Time synchronization is not supported by an installed operating-system provider.');
        }
        $before = $this->enabled($context);
        $result = $this->helper->doctor($context, 'set-ntp', ['true'], (int) ($action['timeout'] ?? 60));
        if (!$this->enabled($context)) {
            try {
                $this->helper->doctor($context, 'set-ntp', [$before ? 'true' : 'false'], 60);
            } catch (\Throwable) {
                // The primary failure remains authoritative; the helper logs
                // and returns its own exact verification evidence.
            }
            throw new \RuntimeException('Time synchronization did not reach the enabled state.');
        }
        return [
            'type' => 'time_sync',
            'before_enabled' => $before,
            'after_enabled' => true,
            'result' => $result,
            'description' => $action['description'] ?? 'Enabled operating-system time synchronization.',
        ];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'time_sync') {
            return false;
        }
        $before = (bool) ($change['before_enabled'] ?? false);
        $this->helper->doctor($context, 'set-ntp', [$before ? 'true' : 'false'], 60);
        if ($this->enabled($context) !== $before) {
            throw new \RuntimeException('Time synchronization rollback failed independent state verification.');
        }
        return true;
    }

    private function enabled(DoctorContext $context): bool
    {
        $timedatectl = $context->environment->command('timedatectl');
        if ($timedatectl === null) {
            throw new \RuntimeException('Time synchronization state command is unavailable.');
        }
        $result = $context->process->run([$timedatectl, 'show', '--property=NTP', '--value'], 30);
        if (!$result->successful()) {
            throw new \RuntimeException('Unable to inspect the current time synchronization state.');
        }
        $value = strtolower(trim((string) $result->get('stdout')));
        if (!in_array($value, ['yes', 'no'], true)) {
            throw new \RuntimeException('Time synchronization state was not an exact yes/no value.');
        }
        return $value === 'yes';
    }

    private function capable(DoctorContext $context): bool
    {
        $timedatectl = $context->environment->command('timedatectl');
        if ($timedatectl === null) {
            return false;
        }
        $result = $context->process->run([$timedatectl, 'show', '--property=CanNTP', '--value'], 30);
        return $result->successful() && strtolower(trim((string) $result->get('stdout'))) === 'yes';
    }
}
