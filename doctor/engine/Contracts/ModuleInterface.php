<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Contracts;

interface ModuleInterface
{
    public function name(): string;
    public function version(): string;
    public function manifest(): array;
}
