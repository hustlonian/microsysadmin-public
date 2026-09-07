<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

echo json_encode([
    'product' => 'MicroSysAdmin',
    'version' => SysAdmin\MachineIdentity::version(),
    'machine' => (new SysAdmin\MachineIdentity())->get(),
    'environment' => SysAdmin\EnvironmentConfig::all(),
    'environment_diagnostics' => SysAdmin\EnvironmentConfig::diagnostics(),
    'health' => (new SysAdmin\HealthCheckService())->all(),
    'license' => SysAdmin\LicenseManager::service()->status(),
    'hub' => SysAdmin\HubManager::client()->status(),
    'security_profile' => [
        'name' => SysAdmin\SecurityProfile::current(),
        'capabilities' => SysAdmin\SecurityProfile::capabilities(),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
