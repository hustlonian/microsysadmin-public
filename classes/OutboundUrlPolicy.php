<?php

declare(strict_types=1);

namespace SysAdmin;

final class OutboundUrlPolicy
{
    public static function validate(string $url, bool $allowPrivate = false): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new \InvalidArgumentException('Outbound URL must be an absolute HTTPS URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Outbound URL must not contain credentials.');
        }

        $host = (string) $parts['host'];
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$allowPrivate) {
            foreach ($addresses as $address) {
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \InvalidArgumentException('Outbound URL resolves to a private or reserved address.');
                }
            }
        }
        return rtrim($url, '/');
    }
}
