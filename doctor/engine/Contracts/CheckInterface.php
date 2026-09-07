<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Contracts;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Support\Value\CheckMetadata;
use SysAdmin\Doctor\engine\Support\Value\CheckResult;

interface CheckInterface
{
    public function metadata(): CheckMetadata;
    public function supports(EnvironmentContext $environment): bool;
    public function run(DoctorContext $context): CheckResult;
}
