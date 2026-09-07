<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Contracts;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Support\Value\CheckResult;
use SysAdmin\Doctor\engine\Support\Value\DiagnosisMetadata;
use SysAdmin\Doctor\engine\Support\Value\DiagnosisResult;

interface DiagnosisInterface
{
    public function metadata(): DiagnosisMetadata;
    public function supports(EnvironmentContext $environment): bool;
    public function diagnose(DoctorContext $context, CheckResult $check): DiagnosisResult;
}
