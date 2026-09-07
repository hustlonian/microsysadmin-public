<?php

declare(strict_types=1);

namespace SysAdmin;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token = null): void
    {
        $token ??= $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!self::valid($token)) {
            json_response(['ok' => false, 'error' => 'Invalid CSRF token. Refresh and try again.'], 419);
        }
    }

    public static function valid(mixed $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($token)
            && is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $token);
    }
}
