<?php

declare(strict_types=1);

namespace SysAdmin;

final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Unable to encrypt local secret.');
        }

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new \RuntimeException('Unsupported encrypted payload.');
        }

        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Encrypted payload is invalid.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt local secret.');
        }

        return $plain;
    }

    private static function key(): string
    {
        $configured = (string) config_value('security.app_key', '');
        $raw = ctype_xdigit($configured) ? hex2bin($configured) : false;
        if ($raw === false || strlen($raw) < 32) {
            throw new \RuntimeException('Application encryption key is not configured.');
        }

        return substr(hash('sha256', $raw, true), 0, 32);
    }
}
