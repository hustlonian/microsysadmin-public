<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;
use SysAdmin\Doctor\engine\Support\RepairStatus;

final readonly class RepairResult extends ArrayValue
{
    public function status(): RepairStatus { return RepairStatus::from((string) $this->get('status')); }
    public function successful(): bool { return in_array($this->status(), [RepairStatus::REPAIRED, RepairStatus::VERIFIED], true); }
}
