<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface BackupOperationsInterface
{
    public function database(string $database): array;
    public function folder(string $path): array;
    public function list(): array;
    public function restore(string $archive, string $target): array;
}
