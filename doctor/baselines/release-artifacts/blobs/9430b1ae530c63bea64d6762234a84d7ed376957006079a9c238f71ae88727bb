<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Detection;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class ProbeResult extends ArrayValue
{
    public function healthy(): bool { return (bool) $this->get('healthy', false); }
}
