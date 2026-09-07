<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\Auth;
use SysAdmin\Csrf;
use SysAdmin\Models\User;
use SysAdmin\View;

final class AuthController
{
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $remember = !empty($_POST['remember']);

            if (isset($_POST['mfa_code'])) {
                $result = Auth::completePendingMfa((string) $_POST['mfa_code']);
            } else {
                $result = Auth::loginResult($username, $password, $remember);
            }

            if (($result['status'] ?? '') === 'success') {
                redirect_to('dashboard');
            }

            $error = match ($result['status'] ?? 'failed') {
                'mfa_required' => null,
                'locked' => 'This account is temporarily locked. Try again later.',
                'default_blocked' => 'Default production credentials are blocked. Reset this administrator password from the installer or database console.',
                default => isset($_POST['mfa_code']) ? 'Invalid authentication code.' : 'Invalid username or password.',
            };
            View::render('login', ['error' => $error, 'mfaRequired' => ($result['status'] ?? '') === 'mfa_required' || isset($_POST['mfa_code'])], null);
            return;
        }

        if (Auth::check()) {
            redirect_to('dashboard');
        }

        View::render('login', ['error' => null], null);
    }

    public function changePassword(): void
    {
        Auth::requireLogin();
        $error = null;
        $success = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify();
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirmation'] ?? '');
            if ($password !== $confirm) {
                $error = 'Password confirmation does not match.';
            } else {
                try {
                    User::updatePassword((int) Auth::id(), $password, true);
                    $user = User::find((int) Auth::id());
                    if ($user) {
                        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
                    }
                    \SysAdmin\AuditLogger::warning('password.changed', ['user_id' => Auth::id()]);
                    $success = 'Password changed.';
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        View::render('change-password', compact('error', 'success'));
    }
}
