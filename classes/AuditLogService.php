<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\AuditLogInterface;

final class AuditLogService implements AuditLogInterface
{
    public function record(string $level, string $action, array $context = []): void
    {
        match ($level) {
            'error' => AuditLogger::error($action, $context),
            'warning' => AuditLogger::warning($action, $context),
            default => AuditLogger::info($action, $context),
        };
    }

    public function recent(int $limit = 100): array
    {
        return AuditLogger::rows($limit);
    }
}
