<?php

declare(strict_types=1);

namespace SysAdmin\Models;

use SysAdmin\CommercialContext;
use SysAdmin\Database;

final class BackupJob
{
    public static function create(string $type, string $target, ?int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO backup_jobs (
                organization_id, machine_id, type, target, status, progress, created_by, created_at
             ) VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
            $type,
            $target,
            'queued',
            $userId,
            date('Y-m-d H:i:s'),
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function finish(int $id, string $status, int $progress, string $message, ?string $archive): void
    {
        $stmt = Database::pdo()->prepare('UPDATE backup_jobs SET status = ?, progress = ?, message = ?, archive_path = ?, finished_at = ? WHERE id = ?');
        $stmt->execute([$status, $progress, $message, $archive, date('Y-m-d H:i:s'), $id]);
    }

    public static function recent(int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT backup_jobs.*, users.username
             FROM backup_jobs
             LEFT JOIN users ON users.id = backup_jobs.created_by
             WHERE backup_jobs.organization_id = ?
             ORDER BY backup_jobs.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, CommercialContext::organizationId(), \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
