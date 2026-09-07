<?php

declare(strict_types=1);

namespace SysAdmin\Models;

use SysAdmin\CommercialContext;
use SysAdmin\Database;

final class ScriptRun
{
    public static function create(string $scriptName, ?int $userId): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO script_runs (
                organization_id, machine_id, script_name, status, run_by, created_at
             ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
            $scriptName,
            'running',
            $userId,
            date('Y-m-d H:i:s'),
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function finish(int $id, string $status, string $output, int $exitCode): void
    {
        $stmt = Database::pdo()->prepare('UPDATE script_runs SET status = ?, output = ?, exit_code = ?, finished_at = ? WHERE id = ?');
        $stmt->execute([$status, $output, $exitCode, date('Y-m-d H:i:s'), $id]);
    }

    public static function recent(int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT script_runs.*, users.username
             FROM script_runs
             LEFT JOIN users ON users.id = script_runs.run_by
             WHERE script_runs.organization_id = ?
             ORDER BY script_runs.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, CommercialContext::organizationId(), \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
