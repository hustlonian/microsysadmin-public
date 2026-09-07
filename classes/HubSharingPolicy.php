<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\Setting;

final class HubSharingPolicy
{
    private const CATEGORIES = ['health', 'services', 'metrics', 'backups', 'updates', 'activity'];

    /**
     * @return array<string, bool>
     */
    public static function current(): array
    {
        $configured = (array) config_value('hub.share', []);
        try {
            $settings = Setting::all();
        } catch (\Throwable) {
            $settings = [];
        }
        $sharing = [];
        foreach (self::CATEGORIES as $category) {
            $settingKey = 'hub.share.' . $category;
            $sharing[$category] = array_key_exists($settingKey, $settings)
                ? (string) $settings[$settingKey] === '1'
                : (bool) ($configured[$category] ?? false);
        }
        return $sharing;
    }

    /**
     * @return array<string, bool>
     */
    public static function save(array $selected): array
    {
        $selected = array_map('strval', $selected);
        foreach (self::CATEGORIES as $category) {
            Setting::set('hub.share.' . $category, in_array($category, $selected, true) ? '1' : '0');
        }
        try {
            Database::pdo()->prepare(
                'UPDATE hub_connections SET data_sharing = ?, updated_at = ? WHERE machine_id = ?'
            )->execute([
                json_encode(self::current(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                date('Y-m-d H:i:s'),
                CommercialContext::machineId(),
            ]);
        } catch (\Throwable) {
            // Enrollment may not exist yet; the policy will be written into it.
        }
        AuditLogger::warning('hub.data_sharing.changed', ['categories' => self::current()]);
        return self::current();
    }
}
