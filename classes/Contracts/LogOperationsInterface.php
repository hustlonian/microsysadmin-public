<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface LogOperationsInterface
{
    public function files(): array;
    public function read(string $label, string $search = '', int $limit = 300): array;
    public function analyze(string $label): array;
}
