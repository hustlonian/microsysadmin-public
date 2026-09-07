<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\Setting;
use SysAdmin\Models\User;

final class DemoAccount
{
    public const USERNAME = 'admindemo';
    public const PASSWORD = 'AdminDemoPassword';

    public static function allowed(array $user): bool
    {
        return strcasecmp((string) $user['username'], self::USERNAME) !== 0 || DemoMode::enabled();
    }

    public static function enable(): array
    {
        self::requireCli();
        $organizationId = CommercialContext::organizationId();
        if ($organizationId === null) {
            throw new \RuntimeException('Install MicroSysAdmin before enabling the demonstration.');
        }
        $existing = User::findByUsername(self::USERNAME);
        if ($existing && (
            !password_verify(self::PASSWORD, (string) $existing['password_hash'])
            || (int) $existing['organization_id'] !== $organizationId
            || $existing['role'] !== 'Administrator'
            || !empty($existing['mfa_enabled'])
        )) {
            throw new \RuntimeException('The admindemo username is already in use by another account.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            if ($existing) {
                $userId = (int) $existing['id'];
                $pdo->prepare('UPDATE users SET is_disabled = 0, force_password_change = 0, failed_login_count = 0, locked_until = NULL, session_version = session_version + 1, updated_at = ? WHERE id = ?')
                    ->execute([$now, $userId]);
            } else {
                // This public password is confined to demo mode; normal password validation stays unchanged.
                $pdo->prepare('INSERT INTO users (organization_id, username, password_hash, role, force_password_change, password_changed_at, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)')
                    ->execute([$organizationId, self::USERNAME, password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'Administrator', $now, $now, $now]);
                $userId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO organization_members (organization_id, user_id, organization_role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$organizationId, $userId, 'admin', 'active', $now, $now]);
            }
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
            SecurityProfile::set('safe');
            Setting::set('demo_mode', '1');
            $data = DemoMode::seed();
            $pdo->commit();
            return $data;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function disable(): void
    {
        self::requireCli();
        if ((bool) config_value('installation.demo_mode', false)) {
            throw new \RuntimeException('Set installation.demo_mode to false in config/config.php before disabling the demonstration.');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $existing = User::findByUsername(self::USERNAME);
            if ($existing) {
                if (!password_verify(self::PASSWORD, (string) $existing['password_hash'])) {
                    throw new \RuntimeException('The admindemo username belongs to another account; it was not disabled.');
                }
                $pdo->prepare('UPDATE users SET is_disabled = 1, session_version = session_version + 1, updated_at = ? WHERE id = ?')
                    ->execute([date('Y-m-d H:i:s'), (int) $existing['id']]);
                $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([(int) $existing['id']]);
            }
            Setting::set('demo_mode', '0');
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private static function requireCli(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new HttpException(403, 'Demo account management requires the command line.');
        }
    }
}
