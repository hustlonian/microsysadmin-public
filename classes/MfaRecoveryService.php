<?php

declare(strict_types=1);

namespace SysAdmin;

final class MfaRecoveryService
{
    /**
     * @return array<int, string>
     */
    public function regenerate(int $userId): array
    {
        $codes = [];
        for ($index = 0; $index < 10; $index++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$userId]);
            $insert = $pdo->prepare(
                'INSERT INTO mfa_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)'
            );
            foreach ($codes as $code) {
                $insert->execute([$userId, password_hash($this->normalize($code), PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        AuditLogger::warning('mfa.recovery_codes.regenerated', ['user_id' => $userId]);
        return $codes;
    }

    public function consume(int $userId, string $code): bool
    {
        $normalized = $this->normalize($code);
        if (!preg_match('/^[A-F0-9]{10}$/', $normalized)) {
            return false;
        }
        $statement = Database::pdo()->prepare(
            'SELECT id, code_hash FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL'
        );
        $statement->execute([$userId]);
        foreach ($statement->fetchAll() as $record) {
            if (!password_verify($normalized, (string) $record['code_hash'])) {
                continue;
            }
            $consume = Database::pdo()->prepare(
                'UPDATE mfa_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL'
            );
            $consume->execute([date('Y-m-d H:i:s'), (int) $record['id']]);
            if ($consume->rowCount() === 1) {
                AuditLogger::warning('mfa.recovery_code.used', ['user_id' => $userId]);
                return true;
            }
        }
        return false;
    }

    public function remaining(int $userId): int
    {
        $statement = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL'
        );
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }

    public function clear(int $userId): void
    {
        Database::pdo()->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$userId]);
    }

    private function normalize(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($code)));
    }
}
