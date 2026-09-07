<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\HubClientInterface;

final class HttpHubClient implements HubClientInterface
{
    public function enabled(): bool
    {
        if (!(bool) config_value('hub.available', false)) {
            return false;
        }
        try {
            $setting = \SysAdmin\Models\Setting::all()['hub_enabled'] ?? null;
            if ($setting !== null) {
                return $setting === '1';
            }
        } catch (\Throwable) {
        }
        return (bool) config_value('hub.enabled', false);
    }

    public function status(): array
    {
        $connection = $this->connection();
        return [
            'enabled' => $this->enabled(),
            'configured' => $connection !== null,
            'status' => $connection['status'] ?? 'disconnected',
            'hub_url' => $connection['hub_url'] ?? (string) config_value('hub.url', ''),
            'remote_machine_id' => $connection['remote_machine_id'] ?? null,
            'last_heartbeat_at' => $connection['last_heartbeat_at'] ?? null,
            'last_error' => $connection['last_error'] ?? null,
            'data_sharing' => $connection ? json_decode((string) $connection['data_sharing'], true) : HubSharingPolicy::current(),
        ];
    }

    public function enroll(string $url, string $organizationId, string $token): array
    {
        if (!(bool) config_value('hub.available', false)) {
            throw new HttpException(403, 'Hub connectivity is unavailable in this MicroSysAdmin release.');
        }
        LicenseManager::service()->assert('hub.enabled');
        $url = $this->validateUrl($url);
        if (strlen($token) < 16) {
            throw new \InvalidArgumentException('Enrollment token is invalid.');
        }

        $identity = (new MachineIdentity())->get();
        $response = $this->request($url . '/v1/machines/enroll', [
            'organization_id' => $organizationId,
            'enrollment_token' => $token,
            'machine' => $this->machineRegistration($identity),
        ]);
        $credential = (string) ($response['credential'] ?? '');
        $remoteMachineId = (string) ($response['machine_id'] ?? '');
        if ($credential === '' || $remoteMachineId === '') {
            throw new \RuntimeException('Hub enrollment response is incomplete.');
        }

        $organizationIdLocal = CommercialContext::organizationId();
        $machineId = CommercialContext::machineId();
        if ($organizationIdLocal === null || $machineId === null) {
            throw new \RuntimeException('Local organization or machine context is unavailable.');
        }
        $now = date('Y-m-d H:i:s');
        $statement = Database::pdo()->prepare(
            'INSERT INTO hub_connections (
                organization_id, machine_id, hub_url, remote_machine_id,
                credential_encrypted, data_sharing, status, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                organization_id = VALUES(organization_id),
                hub_url = VALUES(hub_url),
                remote_machine_id = VALUES(remote_machine_id),
                credential_encrypted = VALUES(credential_encrypted),
                data_sharing = VALUES(data_sharing),
                status = VALUES(status),
                last_error = NULL,
                updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            $organizationIdLocal,
            $machineId,
            $url,
            $remoteMachineId,
            Crypto::encrypt($credential),
            json_encode(HubSharingPolicy::current(), JSON_UNESCAPED_SLASHES),
            'connected',
            $now,
            $now,
        ]);
        \SysAdmin\Models\Setting::set('hub_connection_status', 'connected');
        \SysAdmin\Models\Setting::set('hub_enabled', '1');
        AuditLogger::warning('hub.enrolled', ['hub_url' => $url, 'remote_machine_id' => $remoteMachineId]);
        return $this->status();
    }

    public function test(): array
    {
        $connection = $this->requireConnection();
        $result = $this->authenticatedRequest('/v1/ping', [
            'machine_id' => $connection['remote_machine_id'],
            'sent_at' => gmdate(DATE_ATOM),
        ]);
        return ['ok' => true, 'hub' => $result];
    }

    public function rotateCredential(): array
    {
        $connection = $this->requireConnection();
        $result = $this->authenticatedRequest('/v1/machines/credential/rotate', [
            'machine_id' => $connection['remote_machine_id'],
        ]);
        $credential = (string) ($result['credential'] ?? '');
        if ($credential === '') {
            throw new \RuntimeException('Hub credential rotation response is incomplete.');
        }
        Database::pdo()->prepare(
            'UPDATE hub_connections SET credential_encrypted = ?, updated_at = ? WHERE id = ?'
        )->execute([Crypto::encrypt($credential), date('Y-m-d H:i:s'), (int) $connection['id']]);
        AuditLogger::warning('hub.credential.rotated', ['connection_id' => $connection['id']]);
        return ['ok' => true];
    }

    public function heartbeat(): array
    {
        $connection = $this->requireConnection();
        $share = json_decode((string) $connection['data_sharing'], true);
        if (!is_array($share)) {
            $share = [];
        }
        $payload = $this->heartbeatPayload($share);
        try {
            $response = $this->authenticatedRequest('/v1/machines/heartbeat', $payload);
            Database::pdo()->prepare(
                "UPDATE hub_connections
                 SET status = 'connected', last_heartbeat_at = ?, last_error = NULL, updated_at = ?
                 WHERE id = ?"
            )->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int) $connection['id']]);
            \SysAdmin\Models\Setting::set('hub_connection_status', 'connected');
            AuditLogger::info('hub.heartbeat.completed', [
                'fields' => array_keys($payload),
                'remote_machine_id' => $connection['remote_machine_id'],
            ]);
            return ['ok' => true, 'response' => $response, 'shared_fields' => array_keys($payload)];
        } catch (\Throwable $exception) {
            Database::pdo()->prepare(
                "UPDATE hub_connections
                 SET status = 'error', last_error = ?, updated_at = ?
                 WHERE id = ?"
            )->execute([substr($exception->getMessage(), 0, 1000), date('Y-m-d H:i:s'), (int) $connection['id']]);
            \SysAdmin\Models\Setting::set('hub_connection_status', 'error');
            throw $exception;
        }
    }

    public function disconnect(): void
    {
        $connection = $this->connection();
        if (!$connection) {
            return;
        }
        Database::pdo()->prepare(
            "UPDATE hub_connections
             SET credential_encrypted = NULL, status = 'disconnected', updated_at = ?
             WHERE id = ?"
        )->execute([date('Y-m-d H:i:s'), (int) $connection['id']]);
        \SysAdmin\Models\Setting::set('hub_connection_status', 'disconnected');
        AuditLogger::warning('hub.disconnected', ['hub_url' => $connection['hub_url']]);
    }

    /**
     * Only explicitly approved, summary-level data may be added here.
     */
    private function heartbeatPayload(array $share): array
    {
        $identity = (new MachineIdentity())->get();
        $payload = [
            'machine_id' => $identity['machine_uuid'],
            'version' => $identity['microsysadmin_version'],
            'sent_at' => gmdate(DATE_ATOM),
        ];

        $probe = new SystemProbe();
        if (!empty($share['health'])) {
            $payload['health'] = $probe->healthChecks();
        }
        if (!empty($share['services'])) {
            $payload['services'] = array_map(
                static fn (array $state): array => [
                    'running' => (bool) ($state['running'] ?? false),
                    'label' => (string) ($state['label'] ?? 'Unknown'),
                ],
                (new ServiceControlManager())->states()
            );
        }
        if (!empty($share['metrics'])) {
            $metrics = $probe->metrics();
            $payload['metrics'] = [
                'cpu_percent' => $metrics['cpu'],
                'ram_percent' => $metrics['ram']['percent'] ?? 0,
                'disk_percent' => $metrics['disk']['percent'] ?? 0,
                'network_rx' => $metrics['network']['rx'] ?? 0,
                'network_tx' => $metrics['network']['tx'] ?? 0,
            ];
        }
        if (!empty($share['backups'])) {
            $latestStatement = Database::pdo()->prepare(
                'SELECT type, status, progress, created_at, finished_at
                 FROM backup_jobs
                 WHERE organization_id = ? AND machine_id = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $latestStatement->execute([CommercialContext::organizationId(), CommercialContext::machineId()]);
            $latest = $latestStatement->fetch();
            $payload['backup'] = $latest ?: null;
        }
        if (!empty($share['updates'])) {
            $payload['update'] = [
                'current_version' => MachineIdentity::version(),
                'channel' => (string) config_value('updates.channel', 'stable'),
            ];
        }
        if (!empty($share['activity'])) {
            $payload['last_activity_at'] = date('c');
        }

        return $payload;
    }

    private function machineRegistration(array $identity): array
    {
        return [
            'machine_id' => $identity['machine_uuid'],
            'installation_id' => $identity['installation_uuid'],
            'display_name' => $identity['display_name'],
            'hostname' => $identity['hostname'],
            'operating_system' => $identity['operating_system'],
            'architecture' => $identity['architecture'],
            'microsysadmin_version' => $identity['microsysadmin_version'],
            'php_version' => $identity['php_version'],
        ];
    }

    private function authenticatedRequest(string $path, array $payload): array
    {
        $connection = $this->requireConnection();
        $credential = Crypto::decrypt((string) $connection['credential_encrypted']);
        return $this->request(rtrim((string) $connection['hub_url'], '/') . $path, $payload, [
            'Authorization: Bearer ' . $credential,
            'X-MicroSysAdmin-Machine: ' . $connection['remote_machine_id'],
        ]);
    }

    private function request(string $url, array $payload, array $headers = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $headers[] = 'X-Request-ID: ' . RequestContext::newId('hub');

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL PHP extension is required for hub connectivity.');
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (app_debug() ? CURLPROTO_HTTP : 0),
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($error !== '' ? $error : "Hub returned HTTP {$status}.");
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Hub returned invalid JSON.');
        }
        return $decoded;
    }

    private function validateUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $localDebug = app_debug() && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($url === '' || ($scheme !== 'https' && !($scheme === 'http' && $localDebug))) {
            throw new \InvalidArgumentException('Hub URL must use HTTPS.');
        }
        return $url;
    }

    private function connection(): ?array
    {
        $machineId = CommercialContext::machineId();
        if ($machineId === null) {
            return null;
        }
        $statement = Database::pdo()->prepare('SELECT * FROM hub_connections WHERE machine_id = ? LIMIT 1');
        $statement->execute([$machineId]);
        $connection = $statement->fetch();
        return $connection ?: null;
    }

    private function requireConnection(): array
    {
        if (!$this->enabled()) {
            throw new HttpException(409, 'Hub connectivity is disabled.');
        }
        $connection = $this->connection();
        if (!$connection || empty($connection['credential_encrypted'])) {
            throw new HttpException(409, 'This machine is not enrolled with a hub.');
        }
        return $connection;
    }
}
