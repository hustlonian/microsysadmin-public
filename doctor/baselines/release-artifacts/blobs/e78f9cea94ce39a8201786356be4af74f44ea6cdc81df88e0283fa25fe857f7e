<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface DatabaseOperationsInterface
{
    public function databases(): array;
    public function tables(string $database = ''): array;
    public function rows(string $database, string $table, int $limit = 100, int $offset = 0): array;
}
