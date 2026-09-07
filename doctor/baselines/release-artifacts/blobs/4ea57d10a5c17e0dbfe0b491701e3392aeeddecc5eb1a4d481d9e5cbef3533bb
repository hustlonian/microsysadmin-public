<?php

declare(strict_types=1);

namespace SysAdmin;

final class Security
{
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('X-Frame-Options: DENY');

        $cdn = 'https://cdn.jsdelivr.net https://code.jquery.com https://cdn.datatables.net';
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; img-src 'self' data: blob:; font-src 'self' data: {$cdn}; style-src 'self' 'unsafe-inline' {$cdn}; script-src 'self' 'unsafe-inline' {$cdn}; connect-src 'self'; frame-src 'self'; form-action 'self'");

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
    }

    public static function sessionHash(): ?string
    {
        $id = session_id();
        return $id !== '' ? hash('sha256', $id) : null;
    }

    public static function productionSafeMessage(\Throwable $exception, string $fallback = 'Request failed.'): string
    {
        if (app_debug()) {
            return $exception->getMessage();
        }

        if ($exception instanceof HttpException && $exception->statusCode() < 500) {
            return $exception->getMessage();
        }

        return $fallback;
    }
}
