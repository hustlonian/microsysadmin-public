<?php

declare(strict_types=1);

define('APP_ROOT', rtrim((string) (getenv('MICROSYSADMIN_ISOLATED_APP_ROOT') ?: dirname(__DIR__)), '/'));
require APP_ROOT . '/classes/bootstrap.php';

use SysAdmin\Auth;
use SysAdmin\Database;
use SysAdmin\DemoAccount;
use SysAdmin\DemoMode;
use SysAdmin\Models\Setting;
use SysAdmin\Models\User;
use SysAdmin\SecurityProfile;
use SysAdmin\Validator;

if (!preg_match('/\Amicrosysadmin_(?:test_[A-Za-z0-9]+|ci)\z/', (string) config_value('database.name'))) {
    throw new RuntimeException('Demo login tests require a disposable microsysadmin_test_* or microsysadmin_ci database.');
}
if (DemoMode::enabled() || User::findByUsername(DemoAccount::USERNAME)) {
    throw new RuntimeException('Demo login tests require an installation without an existing demo account.');
}

ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$pdo = Database::pdo();
$originalSettings = Setting::all();
$originalUsers = $pdo->query('SELECT id, username, password_hash, role, is_disabled FROM users ORDER BY id')->fetchAll();
$originalFixtures = is_file(DemoMode::path()) ? file_get_contents(DemoMode::path()) : null;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$userId = null;

try {
    DemoAccount::enable();
    $demo = User::findByUsername(DemoAccount::USERNAME);
    $userId = (int) $demo['id'];
    $assert(DemoMode::enabled() && SecurityProfile::current() === 'safe', 'Demo mode must use the Safe profile.');
    $assert($demo['role'] === 'Administrator', 'Demo account must be an Administrator.');
    $assert(password_verify('AdminDemoPassword', $demo['password_hash']), 'Requested demo password does not match.');
    $assert((int) $demo['force_password_change'] === 0 && (int) $demo['mfa_enabled'] === 0, 'Demo login must be immediately usable.');
    $assert(Auth::loginResult('admindemo', 'AdminDemoPassword', false)['status'] === 'success', 'Demo login failed.');
    $assert(Auth::user()['id'] === $demo['id'], 'Demo session did not resolve to the demo account.');
    $assert(Auth::loginResult('admindemo', 'incorrect-password', false)['status'] === 'failed', 'Incorrect password was accepted.');
    DemoAccount::enable();
    $assert((int) User::findByUsername('admindemo')['id'] === $userId, 'Repeated enable duplicated the demo account.');
    $normalPolicyRejected = false;
    try {
        Validator::password('AdminDemoPassword');
    } catch (InvalidArgumentException) {
        $normalPolicyRejected = true;
    }
    $assert($normalPolicyRejected, 'The demo password weakened the ordinary password policy.');

    Setting::set('demo_mode', '0');
    $assert(Auth::user() === null, 'A demo session survived switching to ordinary operation.');
    $assert(Auth::loginResult('admindemo', 'AdminDemoPassword', false)['status'] === 'locked', 'Demo credentials worked outside demo mode.');

    DemoAccount::enable();
    $pdo->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, bin2hex(random_bytes(9)), password_hash('test-validator', PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 300), date('Y-m-d H:i:s')]);
    $previousVersion = (int) User::find($userId)['session_version'];
    DemoAccount::disable();
    $assert(!DemoMode::enabled(), 'Disable left demo mode active.');
    $disabled = User::find($userId);
    $assert((int) $disabled['is_disabled'] === 1, 'Disable left the shared account active.');
    $assert((int) $disabled['session_version'] > $previousVersion, 'Disable did not invalidate existing sessions.');
    $tokens = $pdo->prepare('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?');
    $tokens->execute([$userId]);
    $assert((int) $tokens->fetchColumn() === 0, 'Disable left remembered login tokens.');

    $conflictingHash = password_hash('Private-Owner-Password-74!', PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ?, is_disabled = 0 WHERE id = ?')->execute([$conflictingHash, $userId]);
    foreach (['enable', 'disable'] as $action) {
        $rejected = false;
        try {
            DemoAccount::$action();
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected, "Demo {$action} changed an unrelated account with the same username.");
    }
    $conflictingUser = User::find($userId);
    $assert($conflictingUser['password_hash'] === $conflictingHash && (int) $conflictingUser['is_disabled'] === 0, 'A conflicting private account was modified.');
    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, is_disabled FROM users WHERE id <> ? ORDER BY id');
    $stmt->execute([$userId]);
    $assert($stmt->fetchAll() === $originalUsers, 'Demo setup changed existing private users.');
} finally {
    $_SESSION = [];
    if ($userId !== null) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
    foreach (['demo_mode', 'security_profile'] as $key) {
        if (array_key_exists($key, $originalSettings)) {
            Setting::set($key, $originalSettings[$key]);
        } else {
            $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        }
    }
    if ($originalFixtures !== null) {
        file_put_contents(DemoMode::path(), $originalFixtures);
    } elseif (is_file(DemoMode::path())) {
        unlink(DemoMode::path());
    }
}

echo "Demo login: {$assertions} assertions passed.\n";
ob_end_flush();
