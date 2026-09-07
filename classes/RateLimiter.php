<?php

declare(strict_types=1);

namespace SysAdmin;

final class RateLimiter
{
    public static function hit(string $bucket, string $subject, int $maxAttempts, int $windowSeconds, int $lockoutSeconds = 0): void
    {
        if ($maxAttempts <= 0 || $windowSeconds <= 0) {
            return;
        }

        $key = hash('sha256', $bucket . ':' . $subject);
        $now = time();
        $pdo = Database::pdo();
        self::cleanup($pdo);

        $stmt = $pdo->prepare('SELECT * FROM rate_limits WHERE rate_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row && !empty($row['blocked_until']) && strtotime((string) $row['blocked_until']) > $now) {
            AuditLogger::warning('rate_limit.blocked', ['bucket' => $bucket, 'subject_hash' => hash('sha256', $subject)]);
            throw new RateLimitException();
        }

        $windowStarted = $row ? strtotime((string) $row['window_started_at']) : 0;
        $attempts = $row ? (int) $row['attempts'] : 0;
        if (!$row || $windowStarted <= 0 || ($now - $windowStarted) >= $windowSeconds) {
            $attempts = 0;
            $windowStarted = $now;
        }

        $attempts++;
        $blockedUntil = null;
        if ($attempts > $maxAttempts) {
            $blockedUntil = date('Y-m-d H:i:s', $now + max($lockoutSeconds, $windowSeconds));
        }

        $sql = 'INSERT INTO rate_limits (rate_key, bucket, attempts, window_started_at, blocked_until, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE bucket = VALUES(bucket), attempts = VALUES(attempts), window_started_at = VALUES(window_started_at), blocked_until = VALUES(blocked_until), updated_at = VALUES(updated_at)';
        $write = $pdo->prepare($sql);
        $write->execute([
            $key,
            substr($bucket, 0, 80),
            $attempts,
            date('Y-m-d H:i:s', $windowStarted),
            $blockedUntil,
            date('Y-m-d H:i:s', $now),
        ]);

        if ($blockedUntil !== null) {
            AuditLogger::warning('rate_limit.exceeded', ['bucket' => $bucket, 'subject_hash' => hash('sha256', $subject)]);
            throw new RateLimitException();
        }
    }

    public static function clear(string $bucket, string $subject): void
    {
        $key = hash('sha256', $bucket . ':' . $subject);
        try {
            $stmt = Database::pdo()->prepare('DELETE FROM rate_limits WHERE rate_key = ?');
            $stmt->execute([$key]);
        } catch (\Throwable) {
        }
    }

    private static function cleanup(\PDO $pdo): void
    {
        $marker = STORAGE_PATH . '/cache/rate-limit-cleanup';
        $lastCleanup = @filemtime($marker) ?: 0;
        if (time() - $lastCleanup < 3600) {
            return;
        }

        try {
            $pdo->exec('DELETE FROM rate_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)');
            @file_put_contents($marker, (string) time(), LOCK_EX);
        } catch (\Throwable) {
        }
    }
}
