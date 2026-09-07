<?php

declare(strict_types=1);

namespace SysAdmin;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Throwable;

final class AuditLogger
{
    private static ?Logger $logger = null;

    public static function info(string $action, array $context = []): void
    {
        self::write('info', $action, $context);
    }

    public static function warning(string $action, array $context = []): void
    {
        self::write('warning', $action, $context);
    }

    public static function error(string $action, array $context = []): void
    {
        self::write('error', $action, $context);
    }

    public static function rows(int $limit = 100): array
    {
        try {
            $organizationId = CommercialContext::organizationId();
            $sql = 'SELECT audit_logs.*, users.username
                    FROM audit_logs
                    LEFT JOIN users ON users.id = audit_logs.user_id';
            if ($organizationId !== null) {
                $sql .= ' WHERE audit_logs.organization_id = ?';
            }
            $sql .= ' ORDER BY audit_logs.id DESC LIMIT ?';
            $stmt = Database::pdo()->prepare($sql);
            $position = 1;
            if ($organizationId !== null) {
                $stmt->bindValue($position++, $organizationId, \PDO::PARAM_INT);
            }
            $stmt->bindValue($position, max(1, min(1000, $limit)), \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private static function write(string $level, string $action, array $context): void
    {
        try {
            self::logger()->{$level}($action, $context);
        } catch (Throwable) {
        }

        try {
            if (!($GLOBALS['sysadmin_config_exists'] ?? false)) {
                return;
            }
            $stmt = Database::pdo()->prepare(
                'INSERT INTO audit_logs (
                    organization_id, machine_id, user_id, level, action, context,
                    ip_address, session_id_hash, request_id, user_agent, created_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                CommercialContext::organizationId(),
                CommercialContext::machineId(),
                Auth::id(),
                $level,
                $action,
                json_encode(self::sanitize($context), JSON_UNESCAPED_SLASHES),
                Security::clientIp(),
                Security::sessionHash(),
                RequestContext::requestId(),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
        }
    }

    private static function logger(): Logger
    {
        if (self::$logger instanceof Logger) {
            return self::$logger;
        }

        self::$logger = new Logger('sysadmin');
        if (class_exists(StreamHandler::class)) {
            self::$logger->pushHandler(new StreamHandler(STORAGE_PATH . '/logs/app.log', Level::Info));
        }

        return self::$logger;
    }

    private static function sanitize(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $keyString = (string) $key;
            if (preg_match('/password|passwd|token|secret|credential|private[_-]?key|license[_-]?key|mysql_pwd/i', $keyString)) {
                $sanitized[$keyString] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$keyString] = self::sanitize($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $text = (string) $value;
                $sanitized[$keyString] = strlen($text) > 1000 ? substr($text, 0, 1000) . '...[truncated]' : $value;
                continue;
            }

            $sanitized[$keyString] = '[unserializable]';
        }

        return $sanitized;
    }
}
