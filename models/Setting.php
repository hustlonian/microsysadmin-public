<?php

declare(strict_types=1);

namespace SysAdmin\Models;

use SysAdmin\Database;

final class Setting
{
    public static function all(): array
    {
        $rows = Database::pdo()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::pdo()->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
        $stmt->execute([$key, $value, date('Y-m-d H:i:s')]);
    }
}
