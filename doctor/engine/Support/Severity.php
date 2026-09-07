<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support;

enum Severity: string
{
    case INFO = 'INFO';
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';
}
