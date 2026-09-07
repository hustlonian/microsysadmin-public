<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Support;

use SysAdmin\Doctor\engine\Context\DoctorContext;

final readonly class PrivilegedHelperClient
{
    public function doctor(DoctorContext $context, string $operation, array $arguments = [], int $timeout = 90): array
    {
        $helper = \SysAdmin\EnvironmentConfig::path('privileged_helper_path');
        if ($helper === '' || !is_file($helper) || !is_executable($helper)) {
            throw new \RuntimeException('The configured privileged Doctor helper is unavailable.');
        }
        $sudo = $context->environment->command('sudo') ?? '/usr/bin/sudo';
        $result = $context->process->run(
            [$sudo, '-n', $helper, 'doctor', $operation, ...array_map('strval', $arguments)],
            max(15, min(180, $timeout)),
            null,
            [
                'MICROSYSADMIN_XAMPP_ROOT' => \SysAdmin\EnvironmentConfig::path('xampp_root'),
                'MICROSYSADMIN_APP_ROOT' => APP_ROOT,
                'MICROSYSADMIN_STORAGE_ROOT' => \SysAdmin\EnvironmentConfig::path('storage_root', STORAGE_PATH),
                'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
            ],
        );
        if (!$result->successful()) {
            $detail = trim((string) $result->get('stderr')) ?: trim((string) $result->get('stdout'));
            $detail = $detail !== '' ? $detail : 'exit code ' . (string) ($result->get('exit_code') ?? 'unknown') . '; the privileged helper returned no output';
            throw new \RuntimeException('Privileged Doctor action failed: ' . $detail);
        }
        return $result->toArray();
    }
}
