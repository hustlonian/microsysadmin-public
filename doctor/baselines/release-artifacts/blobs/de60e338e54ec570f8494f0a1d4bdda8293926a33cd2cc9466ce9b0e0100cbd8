<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class DetectionResult extends ArrayValue
{
    public function detected(): bool { return (bool) $this->get('detected', false); }
    public function confidence(): float { return (float) $this->get('confidence', 0.0); }
}
