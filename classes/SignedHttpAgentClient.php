<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\AgentClientInterface;

final class SignedHttpAgentClient implements AgentClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $machineUuid,
        private readonly string $keyId,
        private readonly string $secret
    ) {
    }

    public function request(string $action, array $payload = []): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The cURL extension is required for HTTP agent transport.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]+$/', $action)) {
            throw new \InvalidArgumentException('Agent action is invalid.');
        }
        $path = '/agent/v1/' . str_replace('.', '/', $action);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $requestId = RequestContext::newId('agent');
        $signature = AgentSigner::sign($this->secret, 'POST', $path, $timestamp, $requestId, $body);
        $handle = curl_init(rtrim($this->baseUrl, '/') . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-MicroSysAdmin-Machine: ' . $this->machineUuid,
                'X-MicroSysAdmin-Key-ID: ' . $this->keyId,
                'X-MicroSysAdmin-Request-ID: ' . $requestId,
                'X-MicroSysAdmin-Timestamp: ' . $timestamp,
                'X-MicroSysAdmin-Signature: ' . $signature,
            ],
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($error !== '' ? $error : "Agent returned HTTP {$status}.");
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            throw new \RuntimeException('Agent returned an invalid or failed response.');
        }
        return (array) ($decoded['result'] ?? []);
    }
}
