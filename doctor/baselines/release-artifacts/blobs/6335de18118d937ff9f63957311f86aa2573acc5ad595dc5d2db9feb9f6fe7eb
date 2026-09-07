<?php

declare(strict_types=1);

namespace SysAdmin;

use PDO;
use PDOException;

final class Database
{
    private const MIGRATION_VERSION = '2026-07-27.2';

    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function init(array $config): void
    {
        self::$config = $config;
        self::pdo();
        if (!self::schemaIsCurrent()) {
            self::migrate();
            self::seed();
            self::markSchemaCurrent();
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = self::$config['database'] ?? config_value('database', []);
        $name = (string) ($db['name'] ?? 'microsysadmin');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \RuntimeException('Invalid database name in config.');
        }

        $charset = (string) ($db['charset'] ?? 'utf8mb4');
        $host = (string) ($db['host'] ?? '127.0.0.1');
        $port = (int) ($db['port'] ?? 3306);
        $user = (string) ($db['user'] ?? 'root');
        $password = (string) ($db['password'] ?? '');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $socket = (string) ($db['socket'] ?? config_value('environment.mysql_socket', ''));
        $dsn = $socket !== ''
            ? "mysql:unix_socket={$socket};dbname={$name};charset={$charset}"
            : "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        try {
            self::$pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $exception) {
            if (!self::isUnknownDatabaseError($exception)) {
                throw new \RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
            }

            try {
                $serverDsn = $socket !== ''
                    ? "mysql:unix_socket={$socket};charset={$charset}"
                    : "mysql:host={$host};port={$port};charset={$charset}";
                $server = new PDO($serverDsn, $user, $password, $options);
                $server->exec("CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
                self::$pdo = new PDO($dsn, $user, $password, $options);
            } catch (PDOException $createException) {
                throw new \RuntimeException('Database connection failed: ' . $createException->getMessage(), 0, $createException);
            }
        }

        return self::$pdo;
    }

    private static function schemaIsCurrent(): bool
    {
        try {
            $stmt = self::pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute(['database_schema_version']);
            return hash_equals(self::schemaVersion(), (string) $stmt->fetchColumn());
        } catch (\Throwable) {
            return false;
        }
    }

    private static function markSchemaCurrent(): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
        );
        $stmt->execute(['database_schema_version', self::schemaVersion(), date('Y-m-d H:i:s')]);
    }

    private static function schemaVersion(): string
    {
        $schema = APP_ROOT . '/database/schema.sql';
        $hash = is_file($schema) ? (string) hash_file('sha256', $schema) : 'missing';
        $migrationHash = (new MigrationRunner(self::pdo()))->fingerprint();
        return self::MIGRATION_VERSION . ':' . $hash . ':' . $migrationHash;
    }

    private static function isUnknownDatabaseError(PDOException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        return $driverCode === 1049 || str_contains(strtolower($exception->getMessage()), 'unknown database');
    }

    public static function migrate(): void
    {
        $schema = APP_ROOT . '/database/schema.sql';
        if (!is_file($schema)) {
            throw new \RuntimeException('Database schema file is missing.');
        }

        $sql = (string) file_get_contents($schema);
        foreach (preg_split('/;\s*(?:\R|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                self::pdo()->exec($statement);
            }
        }

        try {
            self::pdo()->exec('ALTER TABLE scheduled_tasks ADD COLUMN last_run_at DATETIME NULL AFTER enabled');
        } catch (\Throwable) {
        }
        self::migrateSecurityColumns();
        (new MigrationRunner(self::pdo()))->migrate();
    }

    public static function seed(): void
    {
        $pdo = self::pdo();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $now = date('Y-m-d H:i:s');

        if ($count === 0 && (bool) config_value('security.allow_default_admin_seed', false)) {
            $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, force_password_change, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                'admin',
                'admin@localhost',
                password_hash('admin123', PASSWORD_DEFAULT),
                'Administrator',
                1,
                $now,
                $now,
            ]);
        }

        foreach ([
            'dark_mode' => '0',
            'theme' => 'default',
            'timezone' => config_value('app.timezone', 'America/New_York'),
            'refresh_interval' => (string) config_value('app.refresh_interval', 5000),
            'email_notifications' => '0',
            'license_status' => (bool) config_value('licensing.development_mode', false) ? 'development' : 'community',
            'license_edition' => (bool) config_value('licensing.development_mode', false) ? 'Development' : 'Community',
            'license_server_id' => self::serverIdentifier(),
        ] as $key => $value) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)');
            $stmt->execute([$key, $value, $now]);
        }

        CommercialBootstrap::seed();
    }

    private static function migrateSecurityColumns(): void
    {
        $pdo = self::pdo();
        foreach ([
            "ALTER TABLE users MODIFY role ENUM('Admin','Power User','Administrator','Operator','Viewer') NOT NULL DEFAULT 'Viewer'",
            "UPDATE users SET role = 'Administrator' WHERE role = 'Admin'",
            "UPDATE users SET role = 'Operator' WHERE role = 'Power User'",
            "ALTER TABLE users MODIFY role ENUM('Administrator','Operator','Viewer') NOT NULL DEFAULT 'Viewer'",
            "ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER force_password_change",
            "ALTER TABLE users ADD COLUMN failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER session_version",
            "ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_count",
            "ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER locked_until",
            "ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at",
            "ALTER TABLE users ADD COLUMN mfa_secret TEXT NULL AFTER mfa_enabled",
            "ALTER TABLE users ADD COLUMN mfa_confirmed_at DATETIME NULL AFTER mfa_secret",
            "ALTER TABLE audit_logs ADD COLUMN session_id_hash CHAR(64) NULL AFTER ip_address",
        ] as $statement) {
            try {
                $pdo->exec($statement);
            } catch (\Throwable) {
            }
        }
    }

    private static function serverIdentifier(): string
    {
        $material = implode('|', [
            gethostname() ?: 'localhost',
            APP_ROOT,
            php_uname('m'),
        ]);

        return 'srv_' . substr(hash('sha256', $material), 0, 24);
    }
}
