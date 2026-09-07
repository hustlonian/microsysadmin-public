<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class RepairPlan extends ArrayValue
{
    public function repairId(): string { return (string) $this->get('repair_id'); }
    public function actions(): array { return array_values((array) $this->get('actions', [])); }
    public function requiresBackup(): bool { return (bool) $this->get('requires_backup', false); }
}
