<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class CommandResult extends ArrayValue
{
    public function successful(): bool { return (bool) $this->get('successful', false); }
    public function exitCode(): ?int { $value = $this->get('exit_code'); return $value === null ? null : (int) $value; }
}
