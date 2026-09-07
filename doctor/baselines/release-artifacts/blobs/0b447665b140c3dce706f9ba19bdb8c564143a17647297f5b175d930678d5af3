<?php

declare(strict_types=1);

namespace SysAdmin;

final class RequestContext
{
    private static ?string $requestId = null;
    private static ?string $auditCorrelationId = null;

    public static function initialize(): void
    {
        $incoming = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        self::$requestId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $incoming)
            ? $incoming
            : self::newId('req');
        self::$auditCorrelationId = self::newId('aud');

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Request-ID: ' . self::$requestId);
        }
    }

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::initialize();
        }
        return (string) self::$requestId;
    }

    public static function auditCorrelationId(): string
    {
        if (self::$auditCorrelationId === null) {
            self::initialize();
        }
        return (string) self::$auditCorrelationId;
    }

    public static function newId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(16));
    }
}
