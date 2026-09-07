<?php

declare(strict_types=1);

namespace SysAdmin\Models;

use SysAdmin\Database;
use SysAdmin\Crypto;
use SysAdmin\Validator;
use SysAdmin\CommercialContext;
use SysAdmin\LicenseManager;

final class User
{
    public static function all(): array
    {
        $organizationId = CommercialContext::organizationId();
        $stmt = Database::pdo()->prepare('SELECT id, username, email, role, is_disabled, force_password_change, mfa_enabled, locked_until, last_login_at, created_at FROM users WHERE organization_id = ? ORDER BY username');
        $stmt->execute([$organizationId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['role'] = Validator::role((string) $row['role']);
        }
        unset($row);

        return $rows;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function create(string $username, string $email, string $password, string $role): int
    {
        $organizationId = CommercialContext::organizationId();
        if ($organizationId === null) {
            throw new \RuntimeException('No organization context is available.');
        }
        $maximum = LicenseManager::service()->entitlement('users.max', 1);
        if (is_int($maximum)) {
            $countStatement = Database::pdo()->prepare('SELECT COUNT(*) FROM users WHERE organization_id = ? AND is_disabled = 0');
            $countStatement->execute([$organizationId]);
            if ((int) $countStatement->fetchColumn() >= $maximum) {
                throw new \SysAdmin\HttpException(403, 'The current license user allowance has been reached.');
            }
        }
        $username = Validator::username($username);
        $email = Validator::email($email);
        Validator::password($password);
        $role = Validator::role($role);

        $stmt = Database::pdo()->prepare('INSERT INTO users (organization_id, username, email, password_hash, role, force_password_change, password_changed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$organizationId, $username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $role, $now, $now, $now]);
        $userId = (int) Database::pdo()->lastInsertId();
        Database::pdo()->prepare(
            'INSERT INTO organization_members (organization_id, user_id, organization_role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$organizationId, $userId, $role === 'Administrator' ? 'admin' : ($role === 'Viewer' ? 'viewer' : 'member'), 'active', $now, $now]);
        return $userId;
    }

    public static function setDisabled(int $id, bool $disabled): void
    {
        if ($id === \SysAdmin\Auth::id() && $disabled) {
            throw new \InvalidArgumentException('You cannot disable your own active account.');
        }
        $target = self::find($id);
        if (!$target || (int) ($target['organization_id'] ?? 0) !== CommercialContext::organizationId()) {
            throw new \InvalidArgumentException('User not found in this organization.');
        }
        if ($disabled && (string) $target['role'] === 'Administrator' && self::activeAdministratorCount() <= 1) {
            throw new \InvalidArgumentException('The last active Administrator cannot be disabled.');
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE users SET is_disabled = ?, session_version = session_version + 1, updated_at = ?
             WHERE id = ? AND organization_id = ?'
        );
        $stmt->execute([$disabled ? 1 : 0, date('Y-m-d H:i:s'), $id, CommercialContext::organizationId()]);
    }

    public static function setRole(int $id, string $role): void
    {
        $role = Validator::role($role);
        $target = self::find($id);
        if (!$target || (int) ($target['organization_id'] ?? 0) !== CommercialContext::organizationId()) {
            throw new \InvalidArgumentException('User not found in this organization.');
        }
        if ((string) $target['role'] === 'Administrator' && $role !== 'Administrator' && self::activeAdministratorCount() <= 1) {
            throw new \InvalidArgumentException('The last active Administrator cannot be demoted.');
        }
        Database::pdo()->prepare(
            'UPDATE users SET role = ?, session_version = session_version + 1, updated_at = ?
             WHERE id = ? AND organization_id = ?'
        )->execute([$role, date('Y-m-d H:i:s'), $id, CommercialContext::organizationId()]);
    }

    private static function activeAdministratorCount(): int
    {
        $statement = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM users
             WHERE organization_id = ? AND role = 'Administrator' AND is_disabled = 0"
        );
        $statement->execute([CommercialContext::organizationId()]);
        return (int) $statement->fetchColumn();
    }

    public static function updatePassword(int $id, string $password, bool $clearForce = true): void
    {
        Validator::password($password);
        $stmt = Database::pdo()->prepare('UPDATE users SET password_hash = ?, force_password_change = ?, password_changed_at = ?, session_version = session_version + 1, failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $clearForce ? 0 : 1, $now, $now, $id]);
    }

    public static function markLogin(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$now, $now, $id]);
    }

    public static function recordFailedLogin(int $id, int $lockoutSeconds): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE users SET failed_login_count = failed_login_count + 1, updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
        $user = self::find($id);
        if ($user && (int) ($user['failed_login_count'] ?? 0) >= (int) config_value('security.login_rate_limit_attempts', 5)) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutSeconds);
            $pdo->prepare('UPDATE users SET locked_until = ?, updated_at = ? WHERE id = ?')->execute([$lockedUntil, date('Y-m-d H:i:s'), $id]);
        }
    }

    public static function resetLoginFailures(int $id): void
    {
        Database::pdo()->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
    }

    public static function incrementSessionVersion(int $id): int
    {
        Database::pdo()->prepare('UPDATE users SET session_version = session_version + 1, updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
        return (int) (self::find($id)['session_version'] ?? 1);
    }

    public static function setMfaSecret(int $id, string $secret, bool $enabled): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET mfa_secret = ?, mfa_enabled = ?, mfa_confirmed_at = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            Crypto::encrypt($secret),
            $enabled ? 1 : 0,
            $enabled ? $now : null,
            $now,
            $id,
        ]);
    }

    public static function disableMfa(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE users SET mfa_secret = NULL, mfa_enabled = 0, mfa_confirmed_at = NULL, session_version = session_version + 1, updated_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
    }

    public static function mfaSecret(array $user): string
    {
        $payload = (string) ($user['mfa_secret'] ?? '');
        return $payload !== '' ? Crypto::decrypt($payload) : '';
    }
}
