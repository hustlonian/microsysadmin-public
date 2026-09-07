<?php

declare(strict_types=1);

namespace SysAdmin;

final class AgentSigner
{
    public static function canonical(string $method, string $path, string $timestamp, string $requestId, string $body): string
    {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $requestId . "\n"
            . hash('sha256', $body);
    }

    public static function sign(string $secret, string $method, string $path, string $timestamp, string $requestId, string $body): string
    {
        return 'v1=' . hash_hmac(
            'sha256',
            self::canonical($method, $path, $timestamp, $requestId, $body),
            $secret
        );
    }
}
