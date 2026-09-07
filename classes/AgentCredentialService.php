<?php

declare(strict_types=1);

namespace SysAdmin;

final class AgentCredentialService
{
    /**
     * Rotate the local agent credential and return the plaintext secret once.
     *
     * @return array{key_id:string,secret:string,machine_uuid:string}
     */
    public function rotate(): array
    {
        $identity = (new MachineIdentity())->get();
        $machineId = $this->machineId((string) $identity['machine_uuid']);
        $keyId = 'agk_' . bin2hex(random_bytes(12));
        $secret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $now = date('Y-m-d H:i:s');

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE agent_credentials SET revoked_at = ? WHERE machine_id = ? AND revoked_at IS NULL'
            )->execute([$now, $machineId]);
            $pdo->prepare(
                'INSERT INTO agent_credentials (
                    machine_id, key_id, secret_encrypted, created_at
                 ) VALUES (?, ?, ?, ?)'
            )->execute([$machineId, $keyId, Crypto::encrypt($secret), $now]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        AuditLogger::warning('agent.credential.rotated', [
            'machine_uuid' => $identity['machine_uuid'],
            'key_id' => $keyId,
        ]);

        return [
            'key_id' => $keyId,
            'secret' => $secret,
            'machine_uuid' => (string) $identity['machine_uuid'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function active(string $keyId, string $machineUuid): ?array
    {
        $statement = Database::pdo()->prepare(
            'SELECT agent_credentials.*, machines.machine_uuid
             FROM agent_credentials
             JOIN machines ON machines.id = agent_credentials.machine_id
             WHERE agent_credentials.key_id = ?
               AND machines.machine_uuid = ?
               AND agent_credentials.revoked_at IS NULL
               AND (agent_credentials.expires_at IS NULL OR agent_credentials.expires_at > NOW())
             LIMIT 1'
        );
        $statement->execute([$keyId, $machineUuid]);
        $credential = $statement->fetch();
        if (!$credential) {
            return null;
        }
        $credential['secret'] = Crypto::decrypt((string) $credential['secret_encrypted']);
        unset($credential['secret_encrypted']);
        return $credential;
    }

    public function hasActive(): bool
    {
        $statement = Database::pdo()->query(
            'SELECT COUNT(*) FROM agent_credentials WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())'
        );
        return (int) $statement->fetchColumn() > 0;
    }

    private function machineId(string $machineUuid): int
    {
        $statement = Database::pdo()->prepare('SELECT id FROM machines WHERE machine_uuid = ? LIMIT 1');
        $statement->execute([$machineUuid]);
        $id = (int) $statement->fetchColumn();
        if ($id < 1) {
            throw new \RuntimeException('The local machine record is missing.');
        }
        return $id;
    }
}
