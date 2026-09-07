<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface AuditLogInterface
{
    public function record(string $level, string $action, array $context = []): void;
    public function recent(int $limit = 100): array;
}
