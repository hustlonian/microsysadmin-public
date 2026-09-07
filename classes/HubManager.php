<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\HubClientInterface;

final class HubManager
{
    private static ?HubClientInterface $client = null;

    public static function client(): HubClientInterface
    {
        if (self::$client) {
            return self::$client;
        }
        if (!(bool) config_value('hub.available', false)) {
            return self::$client = new DisabledHubClient();
        }
        try {
            $settingEnabled = (\SysAdmin\Models\Setting::all()['hub_enabled'] ?? null) === '1';
        } catch (\Throwable) {
            $settingEnabled = false;
        }
        return self::$client = ((bool) config_value('hub.enabled', false) || $settingEnabled)
            ? new HttpHubClient()
            : new DisabledHubClient();
    }

    public static function useClient(HubClientInterface $client): void
    {
        self::$client = $client;
    }
}
