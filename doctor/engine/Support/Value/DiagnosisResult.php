<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class DiagnosisResult extends ArrayValue
{
    public function confidence(): float { return (float) $this->get('confidence', 0.0); }
    public function repairIds(): array { return array_values((array) $this->get('related_repair_ids', [])); }
}
