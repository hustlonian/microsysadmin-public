<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\modules\queue;

use SysAdmin\Doctor\engine\Contracts\ModuleInterface;

final readonly class Module implements ModuleInterface
{
    public function name(): string
    {
        return 'queue';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function manifest(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
