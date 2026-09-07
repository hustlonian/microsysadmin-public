<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface FileOperationsInterface
{
    public function list(string $path = ''): array;
    public function preview(string $path): array;
    public function read(string $path): array;
    public function saveFile(string $path, string $content): string;
    public function delete(string $path): string;
}
