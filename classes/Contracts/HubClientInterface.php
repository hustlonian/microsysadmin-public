<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface HubClientInterface
{
    public function enabled(): bool;
    public function status(): array;
    public function enroll(string $url, string $organizationId, string $token): array;
    public function test(): array;
    public function rotateCredential(): array;
    public function heartbeat(): array;
    public function disconnect(): void;
}
