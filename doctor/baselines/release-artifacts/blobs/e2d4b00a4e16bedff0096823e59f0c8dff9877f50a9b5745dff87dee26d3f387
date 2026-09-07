<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\UpdateProviderInterface;

final class HttpUpdateProvider implements UpdateProviderInterface
{
    public function name(): string
    {
        return 'http';
    }

    public function manifest(string $channel): array
    {
        $base = rtrim((string) config_value('updates.manifest_url', ''), '/');
        if ($base === '') {
            return ['available' => false, 'message' => 'No update manifest URL is configured.'];
        }
        $url = str_ends_with($base, '.json') ? $base : $base . '/' . rawurlencode($channel) . '.json';
        OutboundUrlPolicy::validate($url);
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL PHP extension is required for HTTP updates.');
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status !== 200) {
            throw new \RuntimeException($error !== '' ? $error : "Update provider returned HTTP {$status}.");
        }
        $manifest = json_decode((string) $body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Update provider returned an invalid manifest.');
        }
        $manifest['manifest_source'] = $url;
        return $manifest;
    }
}
