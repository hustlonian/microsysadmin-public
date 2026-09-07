<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface MachineAgentInterface
{
    public function version(): string;
    public function execute(string $action, array $payload = [], array $context = []): array;
}
