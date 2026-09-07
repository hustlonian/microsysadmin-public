<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface SystemMetricsInterface
{
    public function metrics(): array;
    public function healthChecks(): array;
    public function topProcesses(int $limit = 50): array;
}
