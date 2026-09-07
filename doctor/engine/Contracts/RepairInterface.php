<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Contracts;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Support\Value\BackupResult;
use SysAdmin\Doctor\engine\Support\Value\DetectionResult;
use SysAdmin\Doctor\engine\Support\Value\RepairMetadata;
use SysAdmin\Doctor\engine\Support\Value\RepairPlan;
use SysAdmin\Doctor\engine\Support\Value\RepairResult;
use SysAdmin\Doctor\engine\Support\Value\RollbackResult;
use SysAdmin\Doctor\engine\Support\Value\VerificationResult;

interface RepairInterface
{
    public function metadata(): RepairMetadata;
    public function supports(EnvironmentContext $environment): bool;
    public function detect(DoctorContext $context): DetectionResult;
    public function plan(DoctorContext $context, DetectionResult $detection): RepairPlan;
    public function backup(DoctorContext $context, RepairPlan $plan): BackupResult;
    public function repair(DoctorContext $context, RepairPlan $plan): RepairResult;
    public function verify(DoctorContext $context, RepairResult $result): VerificationResult;
    public function rollback(DoctorContext $context, RepairResult $result): RollbackResult;
}
