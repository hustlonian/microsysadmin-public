<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\LicensingServiceInterface;

final class LicenseManager
{
    private static ?LicensingServiceInterface $service = null;

    public static function service(): LicensingServiceInterface
    {
        return self::$service ??= new LocalLicenseService();
    }

    public static function useService(LicensingServiceInterface $service): void
    {
        self::$service = $service;
    }
}
