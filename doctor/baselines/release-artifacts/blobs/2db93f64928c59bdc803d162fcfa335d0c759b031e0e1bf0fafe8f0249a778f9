<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Context;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class EnvironmentContext extends ArrayValue
{
    public function supportsModule(string $module): bool
    {
        return !in_array($module, (array) $this->get('unsupported_modules', []), true);
    }

    public function command(string $name): ?string
    {
        $commands = (array) $this->get('commands', []);
        $value = $commands[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
