<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface ScheduledTaskOperationsInterface
{
    public function managed(): array;
    public function create(string $name, string $schedule, string $scriptName): int;
    public function setEnabled(int $id, bool $enabled): void;
    public function delete(int $id): void;
}
