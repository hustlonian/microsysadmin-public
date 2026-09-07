<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support;

enum AutomationClass: string
{
    case AUTO_SAFE = 'AUTO_SAFE';
    case AUTO_WITH_BACKUP = 'AUTO_WITH_BACKUP';
    case APPROVAL_REQUIRED = 'APPROVAL_REQUIRED';
    case REPORT_ONLY = 'REPORT_ONLY';
    case PROHIBITED = 'PROHIBITED';
}
