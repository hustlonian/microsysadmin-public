<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface GitOperationsInterface
{
    public function repositories(): array;
    public function detail(string $path): array;
    public function action(string $path, string $action, string $message = ''): array;
}
