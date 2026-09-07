<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\Setting;

final class SecurityProfile
{
    private const PROFILES = [
        'safe' => [
            'terminal' => false,
            'code_runner' => false,
            'sql_import' => false,
            'privileged_helper' => false,
            'service_mutation' => false,
        ],
        'developer' => [
            'terminal' => true,
            'code_runner' => true,
            'sql_import' => true,
            'privileged_helper' => true,
            'service_mutation' => true,
        ],
        'advanced' => [
            'terminal' => true,
            'code_runner' => true,
            'sql_import' => true,
            'privileged_helper' => true,
            'service_mutation' => true,
        ],
    ];

    public static function current(): string
    {
        if ((bool) config_value('installation.demo_mode', false)) {
            return 'safe';
        }
        try {
            $setting = Setting::all()['security_profile'] ?? null;
            if (is_string($setting) && isset(self::PROFILES[$setting])) {
                return $setting;
            }
        } catch (\Throwable) {
        }
        $configured = strtolower((string) config_value('installation.security_profile', 'safe'));
        return isset(self::PROFILES[$configured]) ? $configured : 'safe';
    }

    public static function allows(string $capability): bool
    {
        if ((bool) config_value('installation.demo_mode', false)) {
            return false;
        }
        if ($capability === 'terminal' && !(bool) EnvironmentConfig::get('terminal_available', false)) {
            return false;
        }
        if ($capability === 'terminal' && !LicenseManager::service()->allows('terminal.enabled')) {
            return false;
        }
        if ($capability === 'code_runner' && !LicenseManager::service()->allows('code_runner.enabled')) {
            return false;
        }
        return (bool) (self::PROFILES[self::current()][$capability] ?? false);
    }

    public static function assertAllowed(string $capability): void
    {
        if (!self::allows($capability)) {
            throw new HttpException(
                403,
                sprintf('This action is unavailable under the %s installation security profile.', ucfirst(self::current()))
            );
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function capabilities(): array
    {
        $capabilities = self::PROFILES[self::current()];
        if (!(bool) EnvironmentConfig::get('terminal_available', false)) {
            $capabilities['terminal'] = false;
        }
        if (!LicenseManager::service()->allows('terminal.enabled')) {
            $capabilities['terminal'] = false;
        }
        if (!LicenseManager::service()->allows('code_runner.enabled')) {
            $capabilities['code_runner'] = false;
        }
        return $capabilities;
    }

    public static function set(string $profile, bool $riskAcknowledged = false): void
    {
        $profile = strtolower(trim($profile));
        if (!isset(self::PROFILES[$profile])) {
            throw new \InvalidArgumentException('Unknown security profile.');
        }
        if ($profile === 'advanced' && !$riskAcknowledged) {
            throw new \InvalidArgumentException('Advanced mode requires explicit risk acknowledgement.');
        }
        Setting::set('security_profile', $profile);
        AuditLogger::warning('security.profile.changed', ['profile' => $profile]);
    }
}
