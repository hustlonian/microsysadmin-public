<?php

declare(strict_types=1);

namespace SysAdmin;

final class WebhookService
{
    public function list(): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT id, url, event_types, enabled, timeout_seconds, created_at, updated_at
             FROM webhook_endpoints WHERE organization_id = ? ORDER BY id DESC'
        );
        $statement->execute([CommercialContext::organizationId()]);
        return $statement->fetchAll();
    }

    public function create(string $url, array $eventTypes): array
    {
        $url = OutboundUrlPolicy::validate($url, (bool) config_value('webhooks.allow_private_targets', false));
        $eventTypes = array_values(array_unique(array_filter(array_map('strval', $eventTypes))));
        if ($eventTypes === []) {
            throw new \InvalidArgumentException('At least one event type is required.');
        }
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = date('Y-m-d H:i:s');
        $statement = Database::pdo()->prepare(
            'INSERT INTO webhook_endpoints (
                organization_id, url, secret_encrypted, event_types, enabled,
                timeout_seconds, created_at, updated_at
             ) VALUES (?, ?, ?, ?, 0, 10, ?, ?)'
        );
        $statement->execute([
            CommercialContext::organizationId(),
            $url,
            Crypto::encrypt($secret),
            json_encode($eventTypes, JSON_UNESCAPED_SLASHES),
            $now,
            $now,
        ]);
        return ['id' => (int) Database::pdo()->lastInsertId(), 'secret' => $secret, 'enabled' => false];
    }

    public function queueForEvent(int $eventId, string $eventType): void
    {
        $statement = Database::pdo()->prepare(
            'SELECT * FROM webhook_endpoints WHERE organization_id = ? AND enabled = 1'
        );
        $statement->execute([CommercialContext::organizationId()]);
        $now = date('Y-m-d H:i:s');
        foreach ($statement->fetchAll() as $endpoint) {
            $types = json_decode((string) $endpoint['event_types'], true) ?: [];
            if (!in_array('*', $types, true) && !in_array($eventType, $types, true)) {
                continue;
            }
            $insert = Database::pdo()->prepare(
                'INSERT INTO webhook_deliveries (
                    webhook_endpoint_id, event_id, delivery_uuid, status, attempts,
                    next_attempt_at, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, 0, ?, ?, ?)'
            );
            $insert->execute([(int) $endpoint['id'], $eventId, MachineIdentity::uuid(), 'queued', $now, $now, $now]);
            (new JobQueue())->dispatch('webhook.deliver', ['delivery_id' => (int) Database::pdo()->lastInsertId()]);
        }
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        Database::pdo()->prepare(
            'UPDATE webhook_endpoints SET enabled = ?, updated_at = ?
             WHERE id = ? AND organization_id = ?'
        )->execute([$enabled ? 1 : 0, date('Y-m-d H:i:s'), $id, CommercialContext::organizationId()]);
        AuditLogger::warning('webhook.toggled', ['webhook_id' => $id, 'enabled' => $enabled]);
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare(
            'DELETE FROM webhook_endpoints WHERE id = ? AND organization_id = ?'
        )->execute([$id, CommercialContext::organizationId()]);
        AuditLogger::warning('webhook.deleted', ['webhook_id' => $id]);
    }

    public function deliver(int $deliveryId): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT webhook_deliveries.*, webhook_endpoints.url, webhook_endpoints.secret_encrypted,
                    webhook_endpoints.timeout_seconds, events.uuid AS event_uuid,
                    events.event_type, events.payload, events.occurred_at
             FROM webhook_deliveries
             JOIN webhook_endpoints ON webhook_endpoints.id = webhook_deliveries.webhook_endpoint_id
             JOIN events ON events.id = webhook_deliveries.event_id
             WHERE webhook_deliveries.id = ? AND webhook_endpoints.enabled = 1
             LIMIT 1'
        );
        $statement->execute([$deliveryId]);
        $delivery = $statement->fetch();
        if (!$delivery) {
            throw new \InvalidArgumentException('Webhook delivery is unavailable.');
        }

        $body = json_encode([
            'id' => $delivery['event_uuid'],
            'type' => $delivery['event_type'],
            'occurred_at' => $delivery['occurred_at'],
            'delivery_id' => $delivery['delivery_uuid'],
            'data' => json_decode((string) $delivery['payload'], true),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = WebhookSigner::sign(
            Crypto::decrypt((string) $delivery['secret_encrypted']),
            $timestamp,
            $body
        );

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL PHP extension is required for webhooks.');
        }
        $handle = curl_init((string) $delivery['url']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: MicroSysAdmin-Webhook/' . MachineIdentity::version(),
                'X-MicroSysAdmin-Delivery: ' . $delivery['delivery_uuid'],
                'X-MicroSysAdmin-Timestamp: ' . $timestamp,
                'X-MicroSysAdmin-Signature: ' . $signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => min(30, max(2, (int) $delivery['timeout_seconds'])),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        $success = $response !== false && $status >= 200 && $status < 300;
        Database::pdo()->prepare(
            'UPDATE webhook_deliveries SET status = ?, attempts = attempts + 1,
             response_code = ?, response_summary = ?, delivered_at = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            $success ? 'completed' : 'failed',
            $status ?: null,
            substr($error !== '' ? $error : (string) $response, 0, 1000),
            $success ? date('Y-m-d H:i:s') : null,
            date('Y-m-d H:i:s'),
            $deliveryId,
        ]);
        if (!$success) {
            throw new \RuntimeException($error !== '' ? $error : "Webhook returned HTTP {$status}.");
        }
        return ['status' => $status, 'delivery_id' => $delivery['delivery_uuid']];
    }
}
