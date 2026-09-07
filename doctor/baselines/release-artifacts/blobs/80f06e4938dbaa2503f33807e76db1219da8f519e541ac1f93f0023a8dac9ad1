<?php

declare(strict_types=1);

namespace SysAdmin;

final class AgentRequestAuthenticator
{
    private const MAX_CLOCK_SKEW = 300;

    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $method, string $path, string $body): array
    {
        $machineUuid = trim((string) ($_SERVER['HTTP_X_MICROSYSADMIN_MACHINE'] ?? ''));
        $keyId = trim((string) ($_SERVER['HTTP_X_MICROSYSADMIN_KEY_ID'] ?? ''));
        $requestId = trim((string) ($_SERVER['HTTP_X_MICROSYSADMIN_REQUEST_ID'] ?? ''));
        $timestamp = trim((string) ($_SERVER['HTTP_X_MICROSYSADMIN_TIMESTAMP'] ?? ''));
        $signature = trim((string) ($_SERVER['HTTP_X_MICROSYSADMIN_SIGNATURE'] ?? ''));

        if (
            !preg_match('/^[0-9a-f-]{36}$/i', $machineUuid)
            || !preg_match('/^agk_[0-9a-f]{24}$/', $keyId)
            || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $requestId)
            || !ctype_digit($timestamp)
            || !preg_match('/^v1=[0-9a-f]{64}$/i', $signature)
        ) {
            throw new HttpException(401, 'Agent authentication headers are invalid.');
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            throw new HttpException(401, 'Agent request timestamp is outside the allowed window.');
        }

        $credential = (new AgentCredentialService())->active($keyId, $machineUuid);
        if (!$credential) {
            throw new HttpException(401, 'Agent credential is invalid or revoked.');
        }

        $expected = AgentSigner::sign((string) $credential['secret'], $method, $path, $timestamp, $requestId, $body);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new HttpException(401, 'Agent request signature is invalid.');
        }

        try {
            $statement = Database::pdo()->prepare(
                'INSERT INTO agent_requests (
                    request_id, machine_id, key_id, requested_at, status, audit_correlation_id
                 ) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $requestId,
                (int) $credential['machine_id'],
                $keyId,
                date('Y-m-d H:i:s', (int) $timestamp),
                'accepted',
                RequestContext::auditCorrelationId(),
            ]);
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'Agent request has already been used.');
            }
            throw $exception;
        }

        Database::pdo()->prepare(
            'UPDATE agent_credentials SET last_used_at = ? WHERE id = ?'
        )->execute([date('Y-m-d H:i:s'), (int) $credential['id']]);

        return [
            'request_id' => $requestId,
            'machine_uuid' => $machineUuid,
            'machine_id' => (int) $credential['machine_id'],
            'key_id' => $keyId,
        ];
    }

    public function complete(string $requestId, string $status): void
    {
        Database::pdo()->prepare(
            'UPDATE agent_requests SET status = ?, completed_at = ? WHERE request_id = ?'
        )->execute([$status, date('Y-m-d H:i:s'), $requestId]);
    }
}
