<?php

declare(strict_types=1);

namespace SysAdmin;

final class EventBus
{
    private const ALLOWED = [
        'machine.health_changed',
        'service.failed',
        'backup.completed',
        'backup.failed',
        'update.available',
        'update.completed',
        'update.failed',
        'disk.threshold_exceeded',
        'login.lockout',
        'site.provisioned',
        'site.provisioning_failed',
        'scheduled_task.failed',
        'license.entitlement_changed',
    ];

    public function dispatch(string $type, array $payload = []): string
    {
        if (!in_array($type, self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Unknown MicroSysAdmin event type.');
        }
        $uuid = MachineIdentity::uuid();
        $now = date('Y-m-d H:i:s');
        $statement = Database::pdo()->prepare(
            'INSERT INTO events (
                uuid, organization_id, machine_id, event_type, payload,
                audit_correlation_id, occurred_at, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $uuid,
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
            $type,
            json_encode($this->sanitize($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            RequestContext::auditCorrelationId(),
            $now,
            $now,
        ]);
        $eventId = (int) Database::pdo()->lastInsertId();
        (new WebhookService())->queueForEvent($eventId, $type);
        return $uuid;
    }

    private function sanitize(array $payload): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            if (preg_match('/password|secret|token|credential|private|content|sql|source/i', (string) $key)) {
                continue;
            }
            $safe[$key] = is_scalar($value) || $value === null
                ? (is_string($value) ? substr($value, 0, 1000) : $value)
                : (is_array($value) ? $this->sanitize($value) : null);
        }
        return $safe;
    }
}
