<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;

final readonly class ServicePrimitive implements RepairPrimitive
{
    public function types(): array { return ['service_action']; }

    public function descriptor(string $type): array
    {
        return ['type' => $type, 'category' => 'service', 'local_only' => true, 'structured_arguments' => true, 'reversible' => true, 'rollback' => 'captured-pre-repair-running-or-enabled-state', 'internal_backup' => true, 'safety_class' => 'reversible-service-state-transition', 'allowed_actions' => ['start', 'stop', 'enable', 'disable'], 'required_fields' => ['service', 'action']];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $service = strtolower(trim((string) ($action['service'] ?? '')));
        $operation = strtolower(trim((string) ($action['action'] ?? '')));
        if (!isset(\SysAdmin\ServiceControlManager::LABELS[$service])
            || !in_array($operation, ['start', 'stop', 'enable', 'disable'], true)) {
            throw new \RuntimeException('Repair service action is invalid or unsupported.');
        }
        $manager = new \SysAdmin\ServiceControlManager();
        $beforeState = $manager->perform($service, 'status');
        $stateField = in_array($operation, ['enable', 'disable'], true) ? 'enabled' : 'running';
        if (!array_key_exists($stateField, $beforeState) || $beforeState[$stateField] === null) {
            throw new \RuntimeException("Service repair cannot capture the pre-repair {$stateField} state.");
        }
        $partial = ['type' => 'service', 'service' => $service, 'action' => $operation, 'created' => false, 'before_state' => $beforeState];
        try {
        $result = $manager->perform($service, $operation);
        if (empty($result['ok'])) {
            $detail = trim((string) ($result['error'] ?? '')) ?: trim((string) ($result['output'] ?? ''));
            $detail = $detail !== '' ? $detail : 'exit code ' . (string) ($result['exit_code'] ?? 'unknown') . '; the service helper returned no output';
            throw new \RuntimeException('Service repair failed: ' . $detail);
        }
        $expectedValue = !in_array($operation, ['stop', 'disable'], true);
        $timeout = max(1, min(30, (int) ($action['verification_timeout'] ?? 15)));
        $deadline = microtime(true) + $timeout;
        $state = $manager->perform($service, 'status');
        while ((!array_key_exists($stateField, $state) || $state[$stateField] === null || (bool) $state[$stateField] !== $expectedValue) && microtime(true) < $deadline) {
            usleep(250000);
            $state = $manager->perform($service, 'status');
        }
        if (!array_key_exists($stateField, $state) || $state[$stateField] === null || (bool) $state[$stateField] !== $expectedValue) {
            throw new \RuntimeException('Service action was accepted, but the expected state was not reached.');
        }
        } catch (\Throwable $error) {
            throw new \SysAdmin\Doctor\sdk\PartialActionFailure($error->getMessage(), $partial, $error);
        }
        return ['type' => 'service', 'service' => $service, 'action' => $operation, 'created' => false, 'before_state' => $beforeState, 'result' => $result, 'verified_state' => $state, 'description' => $action['description'] ?? 'Executed a scoped service action.'];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'service') {
            return false;
        }
        $before = (array) ($change['before_state'] ?? []);
        $originalAction = (string) ($change['action'] ?? '');
        $stateField = in_array($originalAction, ['enable', 'disable'], true) ? 'enabled' : 'running';
        if (!array_key_exists($stateField, $before) || $before[$stateField] === null) {
            throw new \RuntimeException('Service rollback is missing its captured pre-repair state.');
        }
        $service = (string) ($change['service'] ?? '');
        $manager = new \SysAdmin\ServiceControlManager();
        $current = $manager->perform($service, 'status');
        if (!array_key_exists($stateField, $current) || $current[$stateField] === null) {
            throw new \RuntimeException('Service rollback cannot inspect current state.');
        }
        $expected = (bool) $before[$stateField];
        if ((bool) $current[$stateField] === $expected) {
            return true;
        }
        $operation = $stateField === 'enabled'
            ? ($expected ? 'enable' : 'disable')
            : ($expected ? 'start' : 'stop');
        $restored = $manager->perform($service, $operation);
        if (empty($restored['ok'])) {
            throw new \RuntimeException('Service rollback could not restore the captured running state.');
        }
        $verified = $manager->perform($service, 'status');
        if (!array_key_exists($stateField, $verified) || $verified[$stateField] === null || (bool) $verified[$stateField] !== $expected) {
            throw new \RuntimeException('Service rollback state verification failed.');
        }
        return true;
    }
}
