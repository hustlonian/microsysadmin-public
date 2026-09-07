<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Logging;

use PDO;
use SysAdmin\Database;

final class DoctorRepository
{
    private const COMPRESSED_JSON_PREFIX = 'gz64:';
    private const COMPRESSION_THRESHOLD_BYTES = 262144;

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::pdo();
    }

    public function beginExecution(string $id, string $mode, string $projectPath, array $environment, array $options, ?int $userId): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO doctor_executions
             (execution_id, mode, status, project_path, environment_json, options_json, initiated_by, started_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $id, $mode, 'RUNNING', $projectPath,
            $this->encodeJson($environment),
            $this->encodeJson($options),
            $userId, date('Y-m-d H:i:s'),
        ]);
    }

    public function completeExecution(string $id, string $status, array $result, ?array $before = null, ?array $after = null): void
    {
        $statement = $this->db()->prepare(
            'UPDATE doctor_executions SET status = ?, result_json = ?, before_score_json = ?,
             after_score_json = ?, completed_at = ? WHERE execution_id = ?'
        );
        $statement->execute([
            $status,
            $this->encodeJson($result),
            $before === null ? null : $this->encodeJson($before),
            $after === null ? null : $this->encodeJson($after),
            date('Y-m-d H:i:s'), $id,
        ]);
    }

    public function execution(string $id): ?array
    {
        $statement = $this->db()->prepare('SELECT * FROM doctor_executions WHERE execution_id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }
        foreach (['environment_json', 'options_json', 'result_json', 'before_score_json', 'after_score_json'] as $key) {
            $row[$key] = !empty($row[$key]) ? $this->decodeJson((string) $row[$key]) : null;
        }
        return $row;
    }

    public function recentExecutions(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db()->query(
            'SELECT execution_id, mode, status, project_path, initiated_by, started_at, completed_at
             FROM doctor_executions ORDER BY started_at DESC LIMIT ' . $limit
        )->fetchAll();
    }

    public function audit(string $executionId, string $level, string $type, string $message, array $context): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO doctor_audit_events (execution_id, level, event_type, message, context_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $executionId !== '' ? $executionId : null, $level, $type, $message,
            $this->encodeJson($context),
            date('Y-m-d H:i:s'),
        ]);
    }

    public function auditEvents(string $executionId, int $limit = 500): array
    {
        $statement = $this->db()->prepare(
            'SELECT level, event_type, message, context_json, created_at
             FROM doctor_audit_events WHERE execution_id = ? ORDER BY id ASC LIMIT ' . max(1, min(2000, $limit))
        );
        $statement->execute([$executionId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['context'] = $this->decodeJson((string) ($row['context_json'] ?? '{}')) ?: [];
            unset($row['context_json']);
        }
        unset($row);
        return $rows;
    }

    public function approve(string $executionId, string $repairId, string $planHash, int $userId): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO doctor_approvals (execution_id, repair_id, plan_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE approved_by = VALUES(approved_by), approved_at = VALUES(approved_at), consumed_at = NULL'
        );
        $statement->execute([$executionId, $repairId, $planHash, $userId, date('Y-m-d H:i:s')]);
    }

    public function consumeApproval(string $executionId, string $repairId, string $planHash): bool
    {
        $statement = $this->db()->prepare(
            'UPDATE doctor_approvals SET consumed_at = ?
             WHERE execution_id = ? AND repair_id = ? AND plan_hash = ? AND consumed_at IS NULL'
        );
        $statement->execute([date('Y-m-d H:i:s'), $executionId, $repairId, $planHash]);
        return $statement->rowCount() === 1;
    }

    public function recordReport(string $executionId, string $format, string $path, string $hash): void
    {
        $statement = $this->db()->prepare(
            'INSERT INTO doctor_reports (execution_id, format, report_path, content_hash, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$executionId, $format, $path, $hash, date('Y-m-d H:i:s')]);
    }

    public function report(string $executionId, string $format): ?array
    {
        $statement = $this->db()->prepare(
            'SELECT execution_id, format, report_path, content_hash, created_at
             FROM doctor_reports WHERE execution_id = ? AND format = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$executionId, $format]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) < self::COMPRESSION_THRESHOLD_BYTES) {
            return $json;
        }
        if (!function_exists('gzencode')) {
            throw new \RuntimeException('Doctor requires the PHP zlib extension to persist large execution results.');
        }
        $compressed = gzencode($json, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Unable to compress the Doctor execution result.');
        }
        return self::COMPRESSED_JSON_PREFIX . base64_encode($compressed);
    }

    private function decodeJson(string $value): array
    {
        if (str_starts_with($value, self::COMPRESSED_JSON_PREFIX)) {
            if (!function_exists('gzdecode')) {
                throw new \RuntimeException('Doctor requires the PHP zlib extension to read this execution result.');
            }
            $compressed = base64_decode(substr($value, strlen(self::COMPRESSED_JSON_PREFIX)), true);
            $value = $compressed === false ? false : gzdecode($compressed);
            if (!is_string($value)) {
                throw new \RuntimeException('Stored Doctor execution data is invalid.');
            }
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Stored Doctor execution data is invalid.');
        }
        return $decoded;
    }
}
