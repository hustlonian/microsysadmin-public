<?php

declare(strict_types=1);

namespace SysAdmin;

final class JobQueue
{
    /**
     * @return array<string, mixed>
     */
    public function dispatch(
        string $type,
        array $payload,
        ?int $ownerId = null,
        ?int $timeoutSeconds = null,
        ?int $maxAttempts = null
    ): array {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,119}$/', $type)) {
            throw new \InvalidArgumentException('Job type is invalid.');
        }
        $uuid = MachineIdentity::uuid();
        $now = date('Y-m-d H:i:s');
        $statement = Database::pdo()->prepare(
            'INSERT INTO jobs (
                uuid, organization_id, machine_id, type, payload, status, progress,
                max_attempts, timeout_seconds, available_at, owned_by,
                audit_correlation_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $uuid,
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
            $type,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'queued',
            $maxAttempts ?? (int) config_value('jobs.max_attempts', 3),
            $timeoutSeconds ?? (int) config_value('jobs.default_timeout', 600),
            $now,
            $ownerId ?? Auth::id(),
            RequestContext::auditCorrelationId(),
            $now,
            $now,
        ]);
        $job = $this->find($uuid);
        AuditLogger::info('job.queued', ['job_uuid' => $uuid, 'type' => $type]);
        return $job;
    }

    public function reserve(): ?array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $reserve = $pdo->prepare(
                "SELECT * FROM jobs
                 WHERE status = 'queued' AND available_at <= NOW() AND machine_id = ?
                 ORDER BY id
                 LIMIT 1
                 FOR UPDATE"
            );
            $reserve->execute([CommercialContext::machineId()]);
            $job = $reserve->fetch();
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $now = date('Y-m-d H:i:s');
            $statement = $pdo->prepare(
                "UPDATE jobs
                 SET status = 'running', attempts = attempts + 1, reserved_at = ?,
                     started_at = COALESCE(started_at, ?), updated_at = ?
                 WHERE id = ? AND status = 'queued'"
            );
            $statement->execute([$now, $now, $now, (int) $job['id']]);
            if ($statement->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            return $this->find((string) $job['uuid']);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function progress(string $uuid, int $progress, string $summary = ''): void
    {
        $statement = Database::pdo()->prepare(
            "UPDATE jobs SET progress = ?, output_summary = ?, updated_at = ?
             WHERE uuid = ? AND status = 'running'"
        );
        $statement->execute([
            min(99, max(0, $progress)),
            $summary !== '' ? substr($summary, 0, 65535) : null,
            date('Y-m-d H:i:s'),
            $uuid,
        ]);
    }

    public function complete(string $uuid, mixed $result): void
    {
        $summary = is_string($result)
            ? $result
            : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Database::pdo()->prepare(
            "UPDATE jobs
             SET status = 'completed', progress = 100, output_summary = ?,
                 completed_at = ?, updated_at = ?
             WHERE uuid = ?"
        )->execute([
            substr((string) $summary, 0, 65535),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $uuid,
        ]);
        AuditLogger::info('job.completed', ['job_uuid' => $uuid]);
    }

    public function fail(string $uuid, \Throwable $exception): void
    {
        $job = $this->find($uuid);
        $attempts = (int) ($job['attempts'] ?? 1);
        $maximum = (int) ($job['max_attempts'] ?? 1);
        $retry = $attempts < $maximum && !($exception instanceof HttpException && $exception->statusCode() < 500);
        $delay = min(300, 2 ** max(1, $attempts));
        Database::pdo()->prepare(
            'UPDATE jobs SET status = ?, error_message = ?, available_at = ?,
             reserved_at = NULL, updated_at = ?, completed_at = ?
             WHERE uuid = ?'
        )->execute([
            $retry ? 'queued' : 'failed',
            substr(Security::productionSafeMessage($exception, 'Job failed.'), 0, 2000),
            date('Y-m-d H:i:s', time() + $delay),
            date('Y-m-d H:i:s'),
            $retry ? null : date('Y-m-d H:i:s'),
            $uuid,
        ]);
        AuditLogger::error('job.failed', [
            'job_uuid' => $uuid,
            'retrying' => $retry,
            'attempt' => $attempts,
            'error' => $exception->getMessage(),
        ]);
    }

    public function cancel(string $uuid): void
    {
        Database::pdo()->prepare(
            "UPDATE jobs SET status = 'cancelled', cancelled_at = ?, updated_at = ?
             WHERE uuid = ? AND status = 'queued' AND organization_id = ? AND machine_id = ?"
        )->execute([
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $uuid,
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
        ]);
    }

    public function find(string $uuid): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT * FROM jobs WHERE uuid = ? AND machine_id = ? LIMIT 1'
        );
        $statement->execute([$uuid, CommercialContext::machineId()]);
        $job = $statement->fetch();
        if (!$job) {
            throw new \InvalidArgumentException('Job not found.');
        }
        $job['payload'] = json_decode((string) $job['payload'], true) ?: [];
        return $job;
    }

    public function recent(int $limit = 100): array
    {
        $organizationId = CommercialContext::organizationId();
        $statement = Database::pdo()->prepare(
            'SELECT * FROM jobs WHERE organization_id = ? ORDER BY id DESC LIMIT ?'
        );
        $statement->bindValue(1, $organizationId, \PDO::PARAM_INT);
        $statement->bindValue(2, max(1, min(500, $limit)), \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }
}
