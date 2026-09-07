<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface AgentClientInterface
{
    public function request(string $action, array $payload = []): array;
}
