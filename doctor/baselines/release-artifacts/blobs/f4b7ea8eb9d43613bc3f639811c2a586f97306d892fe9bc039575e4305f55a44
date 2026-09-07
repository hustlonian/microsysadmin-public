<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\User;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $user = User::find((int) $_SESSION['user_id']);
        if (!$user || !empty($user['is_disabled']) || !DemoAccount::allowed($user)) {
            self::clearSession();
            return null;
        }

        $user['role'] = Validator::role((string) $user['role']);
        return $user;
    }

    public static function id(): ?int
    {
        return !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function attempt(string $username, string $password, bool $remember): bool
    {
        return self::loginResult($username, $password, $remember)['status'] === 'success';
    }

    public static function loginResult(string $username, string $password, bool $remember): array
    {
        $username = trim($username);
        $subject = Security::clientIp() . ':' . strtolower($username);
        RateLimiter::hit(
            'login',
            $subject,
            (int) config_value('security.login_rate_limit_attempts', 5),
            (int) config_value('security.login_rate_limit_window', 900),
            (int) config_value('security.login_lockout_seconds', 900)
        );

        $user = User::findByUsername($username);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            if ($user) {
                User::recordFailedLogin((int) $user['id'], (int) config_value('security.login_lockout_seconds', 900));
            }
            AuditLogger::warning('login.failed', ['username' => $username]);
            return ['status' => 'failed'];
        }

        if (!empty($user['is_disabled']) || !DemoAccount::allowed($user)) {
            AuditLogger::warning('login.disabled', ['username' => $username]);
            return ['status' => 'locked'];
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            AuditLogger::warning('login.locked', ['username' => $username]);
            return ['status' => 'locked'];
        }

        if (
            (bool) config_value('security.reject_default_credentials', true)
            && strtolower($username) === 'admin'
            && $password === 'admin123'
            && !app_debug()
        ) {
            AuditLogger::warning('login.default_credentials_blocked', ['username' => $username]);
            return ['status' => 'default_blocked'];
        }

        if (!empty($user['mfa_enabled'])) {
            session_regenerate_id(true);
            $_SESSION['mfa_pending_user_id'] = (int) $user['id'];
            $_SESSION['mfa_pending_remember'] = $remember ? 1 : 0;
            $_SESSION['mfa_pending_started_at'] = time();
            AuditLogger::info('login.mfa_required', ['username' => $username]);
            return ['status' => 'mfa_required'];
        }

        self::completeLogin($user, $remember);
        RateLimiter::clear('login', $subject);
        return ['status' => 'success'];
    }

    public static function completePendingMfa(string $code): array
    {
        $userId = (int) ($_SESSION['mfa_pending_user_id'] ?? 0);
        $started = (int) ($_SESSION['mfa_pending_started_at'] ?? 0);
        if ($userId < 1 || $started < 1 || time() - $started > 300) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_remember'], $_SESSION['mfa_pending_started_at']);
            return ['status' => 'failed'];
        }

        RateLimiter::hit('mfa', Security::clientIp() . ':' . $userId, 5, 300, 900);
        $user = User::find($userId);
        $valid = $user
            && empty($user['is_disabled'])
            && DemoAccount::allowed($user)
            && !empty($user['mfa_enabled'])
            && (
                (new TotpService())->verify(User::mfaSecret($user), $code)
                || (new MfaRecoveryService())->consume((int) $user['id'], $code)
            );
        if (!$valid) {
            AuditLogger::warning('login.mfa_failed', ['user_id' => $userId]);
            return ['status' => 'failed'];
        }

        $remember = !empty($_SESSION['mfa_pending_remember']);
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_remember'], $_SESSION['mfa_pending_started_at']);
        self::completeLogin($user, $remember);
        RateLimiter::clear('mfa', Security::clientIp() . ':' . $userId);
        return ['status' => 'success'];
    }

    public static function requireLogin(): void
    {
        self::enforceIdleTimeout();
        if (!self::check()) {
            if (is_json_request()) {
                json_response(['ok' => false, 'error' => 'Authentication required.'], 401);
            }
            redirect_to('login');
        }
    }

    public static function requireRole(array $roles): void
    {
        self::requireLogin();
        $roles = array_map([Validator::class, 'role'], $roles);
        $user = self::user();
        if (!$user || !in_array((string) $user['role'], $roles, true)) {
            json_response(['ok' => false, 'error' => 'Permission denied.'], 403);
        }
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }

        $role = Validator::role((string) $user['role']);
        if ($role === 'Administrator') {
            return true;
        }

        $permissions = [
            'Operator' => [
                'read',
                'dashboard.view',
                'service.view',
                'service.restart',
                'file.view',
                'file.edit',
                'file.upload',
                'database.view',
                'database.modify',
                'database.delete',
                'git.operations',
                'backup.create',
                'log.view',
                'audit.view',
                'command.execute',
                'cron.manage',
                'media.convert',
                'hosting.write',
                'terminal.access',
                'doctor.view',
                'doctor.scan',
                'doctor.sensitive',
            ],
            'Viewer' => [
                'read',
                'dashboard.view',
                'service.view',
                'file.view',
                'database.view',
                'log.view',
                'doctor.view',
            ],
        ];

        return in_array($permission, $permissions[$role] ?? [], true);
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            AuditLogger::warning('access.denied', ['permission' => $permission]);
            throw new HttpException(403, 'Permission denied.');
        }
    }

    public static function logout(): void
    {
        if (!empty($_COOKIE['sysadmin_remember'])) {
            self::forgetToken((string) $_COOKIE['sysadmin_remember']);
            setcookie('sysadmin_remember', '', time() - 3600, base_url() . '/', '', !empty($_SERVER['HTTPS']), true);
        }

        AuditLogger::info('logout', ['user_id' => self::id()]);
        self::clearSession();
        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function logoutAllForUser(int $userId): void
    {
        User::incrementSessionVersion($userId);
        AuditLogger::warning('session.logout_all', ['user_id' => $userId]);
    }

    public static function enforceIdleTimeout(): void
    {
        if (PHP_SAPI === 'cli' || empty($_SESSION['user_id'])) {
            return;
        }

        $timeout = (int) config_value('app.idle_timeout', 1800);
        if ($timeout > 0 && isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $timeout) {
            self::logout();
            redirect_to('login?timeout=1');
        }

        $absolute = (int) config_value('security.absolute_session_lifetime', 28800);
        if ($absolute > 0 && isset($_SESSION['session_created_at']) && time() - (int) $_SESSION['session_created_at'] > $absolute) {
            self::logout();
            redirect_to('login?timeout=1');
        }

        $user = User::find((int) $_SESSION['user_id']);
        if (!$user || (int) ($user['session_version'] ?? 1) !== (int) ($_SESSION['session_version'] ?? 1)) {
            AuditLogger::info('session.version_mismatch', ['user_id' => $_SESSION['user_id'] ?? null]);
            self::logout();
            redirect_to('login?timeout=1');
        }

        $_SESSION['last_activity'] = time();
    }

    public static function restoreRememberedUser(): void
    {
        if (PHP_SAPI === 'cli' || !empty($_SESSION['user_id']) || empty($_COOKIE['sysadmin_remember'])) {
            return;
        }

        [$selector, $validator] = array_pad(explode(':', (string) $_COOKIE['sysadmin_remember'], 2), 2, null);
        if (!$selector || !$validator) {
            return;
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1');
        $stmt->execute([$selector]);
        $token = $stmt->fetch();

        if ($token && password_verify($validator, $token['validator_hash'])) {
            $user = User::find((int) $token['user_id']);
            if (!$user || !empty($user['is_disabled']) || !DemoAccount::allowed($user) || !empty($user['mfa_enabled'])) {
                self::forgetToken((string) $_COOKIE['sysadmin_remember']);
                return;
            }
            self::completeLogin($user, true);
        }
    }

    private static function completeLogin(array $user, bool $remember): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['session_created_at'] = time();
        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
        User::resetLoginFailures((int) $user['id']);
        User::markLogin((int) $user['id']);
        AuditLogger::info('login.success', ['username' => $user['username']]);

        if ($remember) {
            self::remember((int) $user['id']);
        }
    }

    private static function remember(int $userId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $days = (int) config_value('app.remember_days', 30);
        $expires = date('Y-m-d H:i:s', time() + ($days * 86400));

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $selector, password_hash($validator, PASSWORD_DEFAULT), $expires, date('Y-m-d H:i:s')]);

        setcookie('sysadmin_remember', "{$selector}:{$validator}", [
            'expires' => time() + ($days * 86400),
            'path' => base_url() . '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function forgetToken(string $cookie): void
    {
        [$selector] = explode(':', $cookie, 2);
        if ($selector) {
            $stmt = Database::pdo()->prepare('DELETE FROM remember_tokens WHERE selector = ?');
            $stmt->execute([$selector]);
        }
    }

    private static function clearSession(): void
    {
        $_SESSION = [];
    }
}
