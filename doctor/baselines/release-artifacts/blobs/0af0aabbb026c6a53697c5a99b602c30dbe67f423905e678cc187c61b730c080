<?php

declare(strict_types=1);

namespace SysAdmin;

final class WebhookSigner
{
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $body, string $signature, int $maxAge = 300): bool
    {
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $maxAge) {
            return false;
        }
        return hash_equals(self::sign($secret, $timestamp, $body), strtolower($signature));
    }
}
