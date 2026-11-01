<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Contracts;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Support\Value\{RepairPlan, RepairResult, VerificationResult};

interface RecoveryVerifiableRepair extends RepairInterface
{
    public function preflight(DoctorContext $context, RepairPlan $plan): void;
    public function verifyRollback(DoctorContext $context, RepairResult $result): VerificationResult;
}
