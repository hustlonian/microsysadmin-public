<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\HubClientInterface;

final class DisabledHubClient implements HubClientInterface
{
    public function enabled(): bool
    {
        return false;
    }

    public function status(): array
    {
        return [
            'enabled' => false,
            'status' => 'disabled',
            'message' => 'Hub connectivity is optional and not configured.',
            'last_heartbeat_at' => null,
        ];
    }

    public function enroll(string $url, string $organizationId, string $token): array
    {
        throw new HttpException(409, 'Hub connectivity is disabled.');
    }

    public function test(): array
    {
        return $this->status();
    }

    public function rotateCredential(): array
    {
        throw new HttpException(409, 'Hub connectivity is disabled.');
    }

    public function heartbeat(): array
    {
        return $this->status();
    }

    public function disconnect(): void
    {
    }
}
