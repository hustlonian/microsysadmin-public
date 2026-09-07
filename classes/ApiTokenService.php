<?php

declare(strict_types=1);

namespace SysAdmin;

final class ApiTokenService
{
    private const ALLOWED_SCOPES = [
        'machine.health:read',
        'services.status:read',
        'projects.inventory:read',
        'backups.status:read',
        'version:read',
        'backups:write',
        'health:run',
        'audit.summary:read',
    ];

    /**
     * Return the plaintext token once.
     *
     * @return array{id:int,token:string,prefix:string,scopes:array}
     */
    public function create(string $name, array $scopes, ?string $expiresAt = null, int $rateLimit = 60): array
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9_. -]{3,120}$/', $name)) {
            throw new \InvalidArgumentException('Token name is invalid.');
        }
        $scopes = array_values(array_unique(array_map('strval', $scopes)));
        if ($scopes === [] || array_diff($scopes, self::ALLOWED_SCOPES)) {
            throw new \InvalidArgumentException('One or more API token scopes are invalid.');
        }
        if ($expiresAt !== null && strtotime($expiresAt) === false) {
            throw new \InvalidArgumentException('Token expiration is invalid.');
        }

        $prefix = bin2hex(random_bytes(6));
        $secret = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $token = 'lpt_' . $prefix . '_' . $secret;
        $statement = Database::pdo()->prepare(
            'INSERT INTO api_tokens (
                organization_id, user_id, name, token_prefix, token_hash, scopes,
                rate_limit, expires_at, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            CommercialContext::organizationId(),
            Auth::id(),
            $name,
            $prefix,
            hash('sha256', $token),
            json_encode($scopes, JSON_UNESCAPED_SLASHES),
            max(1, min(1000, $rateLimit)),
            $expiresAt !== null ? date('Y-m-d H:i:s', (int) strtotime($expiresAt)) : null,
            date('Y-m-d H:i:s'),
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::warning('api_token.created', ['name' => $name, 'prefix' => $prefix, 'scopes' => $scopes]);
        return [
            'id' => $id,
            'token' => $token,
            'prefix' => $prefix,
            'scopes' => $scopes,
        ];
    }

    public function authenticate(string $token): array
    {
        if (!preg_match('/^lpt_([0-9a-f]{12})_[A-Za-z0-9_-]{40,}$/', $token, $matches)) {
            throw new HttpException(401, 'API token is invalid.');
        }
        $statement = Database::pdo()->prepare(
            'SELECT * FROM api_tokens
             WHERE token_prefix = ? AND revoked_at IS NULL
             LIMIT 1'
        );
        $statement->execute([$matches[1]]);
        $record = $statement->fetch();
        $expiresAt = is_array($record) ? ($record['expires_at'] ?? null) : null;
        $expiration = is_string($expiresAt) && $expiresAt !== '' ? strtotime($expiresAt) : null;
        $expired = $expiration !== null && ($expiration === false || $expiration <= time());
        if (
            !$record
            || $expired
            || !hash_equals((string) $record['token_hash'], hash('sha256', $token))
        ) {
            throw new HttpException(401, 'API token is invalid or expired.');
        }
        $record['scopes'] = json_decode((string) $record['scopes'], true) ?: [];
        Database::pdo()->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([
            date('Y-m-d H:i:s'),
            (int) $record['id'],
        ]);
        return $record;
    }

    public function requireScope(array $token, string $scope): void
    {
        if (!in_array($scope, $token['scopes'] ?? [], true)) {
            throw new HttpException(403, 'API token does not have the required scope.');
        }
    }

    public function revoke(int $id): void
    {
        Database::pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND organization_id = ?'
        )->execute([date('Y-m-d H:i:s'), $id, CommercialContext::organizationId()]);
        AuditLogger::warning('api_token.revoked', ['token_id' => $id]);
    }

    public function list(): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT id, name, token_prefix, scopes, rate_limit, expires_at, last_used_at, revoked_at, created_at
             FROM api_tokens WHERE organization_id = ? ORDER BY id DESC'
        );
        $statement->execute([CommercialContext::organizationId()]);
        return $statement->fetchAll();
    }
}
