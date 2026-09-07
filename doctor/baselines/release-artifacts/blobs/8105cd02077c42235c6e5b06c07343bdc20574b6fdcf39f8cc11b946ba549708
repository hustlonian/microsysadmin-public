<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Planning;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class PolicyDecision extends ArrayValue
{
    public function allowed(): bool { return (bool) $this->get('allowed', false); }
    public function requiresApproval(): bool { return (bool) $this->get('requires_approval', true); }
}
