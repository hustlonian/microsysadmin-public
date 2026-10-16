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
        $timeoutSeconds ??= (int) config_value('jobs.default_timeout', 600);
        $maxAttempts ??= (int) config_value('jobs.max_attempts', 3);
        if ($timeoutSeconds < 1 || $timeoutSeconds > 2147483647 || $maxAttempts < 1 || $maxAttempts > 65535) {
            throw new \InvalidArgumentException('Job timeout and attempt limits must be positive and within storage limits.');
        }
        $uuid = MachineIdentity::uuid();
        $statement = Database::pdo()->prepare(
            'INSERT INTO jobs (
                uuid, organization_id, machine_id, type, payload, status, progress,
                max_attempts, timeout_seconds, available_at, owned_by,
                audit_correlation_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, CURRENT_TIMESTAMP, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            $uuid,
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
            $type,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'queued',
            $maxAttempts,
            $timeoutSeconds,
            $ownerId ?? Auth::id(),
            RequestContext::auditCorrelationId(),
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
                 WHERE status = 'queued' AND attempts = 0 AND available_at <= NOW() AND machine_id = ?
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
            $statement = $pdo->prepare(
                "UPDATE jobs
                 SET status = 'reserved', attempts = attempts + 1, reserved_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status = 'queued' AND attempts = 0"
            );
            $statement->execute([(int) $job['id']]);
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

    /** Claim an attempt once and hold its database-session lock through execution. */
    public function beginExecution(string $uuid, int $attempt): ?array
    {
        if (!$this->acquireExecutionLock($uuid)) {
            return null;
        }
        try {
            $statement = Database::pdo()->prepare(
                "UPDATE jobs SET status = 'running', started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE uuid = ? AND machine_id = ? AND status = 'reserved' AND attempts = ?
                   AND reserved_at IS NOT NULL AND timeout_seconds > 0
                   AND TIMESTAMPDIFF(SECOND, reserved_at, CURRENT_TIMESTAMP) < timeout_seconds"
            );
            $statement->execute([$uuid, CommercialContext::machineId(), $attempt]);
            if ($statement->rowCount() === 1) {
                return $this->find($uuid);
            }
        } catch (\Throwable $exception) {
            $this->releaseExecutionLock($uuid);
            throw $exception;
        }
        $this->releaseExecutionLock($uuid);
        return null;
    }

    public function releaseExecutionLock(string $uuid): void
    {
        $statement = Database::pdo()->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$this->executionLockName($uuid)]);
    }

    public function progress(string $uuid, int $attempt, int $progress, string $summary = ''): void
    {
        $statement = Database::pdo()->prepare(
            "UPDATE jobs SET progress = ?, output_summary = ?, updated_at = CURRENT_TIMESTAMP
             WHERE uuid = ? AND machine_id = ? AND status = 'running' AND attempts = ?
               AND TIMESTAMPDIFF(SECOND, reserved_at, CURRENT_TIMESTAMP) < timeout_seconds"
        );
        $statement->execute([
            min(99, max(0, $progress)),
            $summary !== '' ? substr($summary, 0, 65535) : null,
            $uuid,
            CommercialContext::machineId(),
            $attempt,
        ]);
    }

    public function complete(string $uuid, int $attempt, mixed $result): bool
    {
        $summary = is_string($result)
            ? $result
            : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $statement = Database::pdo()->prepare(
            "UPDATE jobs
             SET status = 'completed', progress = 100, output_summary = ?,
                 completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE uuid = ? AND machine_id = ? AND status = 'running' AND attempts = ?
               AND TIMESTAMPDIFF(SECOND, reserved_at, CURRENT_TIMESTAMP) < timeout_seconds"
        );
        $statement->execute([
            substr((string) $summary, 0, 65535),
            $uuid,
            CommercialContext::machineId(),
            $attempt,
        ]);
        if ($statement->rowCount() !== 1) {
            $this->requireReconciliation($uuid, $attempt, 'execution_timeout');
            return false;
        }
        AuditLogger::info('job.completed', ['job_uuid' => $uuid]);
        return true;
    }

    public function fail(string $uuid, int $attempt, \Throwable $exception): void
    {
        if ($this->requireReconciliation($uuid, $attempt, 'handler_failed')) {
            AuditLogger::error('job.failed', [
                'job_uuid' => $uuid, 'attempt' => $attempt, 'retrying' => false,
                'error' => Security::productionSafeMessage($exception, 'Job handler failed; outcome unknown.'),
            ]);
        }
    }

    /** Unknown effects are terminal for automation, never an implicit retry. */
    public function requireReconciliation(string $uuid, int $attempt, string $reason): bool
    {
        if (!in_array($reason, ['execution_timeout', 'worker_interrupted', 'handler_failed', 'previous_attempt_unreconciled'], true)) {
            throw new \InvalidArgumentException('Unknown job reconciliation reason.');
        }
        $statement = Database::pdo()->prepare(
            "UPDATE jobs SET status = 'needs_reconciliation', error_message = ?,
                completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE uuid = ? AND machine_id = ? AND attempts = ?
               AND (status IN ('reserved', 'running') OR (status = 'queued' AND attempts > 0))"
        );
        $statement->execute([
            $reason . ': Outcome unknown. Automatic retry disabled; inspect effects before any new job.',
            $uuid, CommercialContext::machineId(), $attempt,
        ]);
        if ($statement->rowCount() !== 1) {
            return false;
        }
        AuditLogger::error('job.needs_reconciliation', [
            'job_uuid' => $uuid, 'attempt' => $attempt, 'reason' => $reason, 'retrying' => false,
        ]);
        return true;
    }

    /** Run on worker ticks and independently from cron, including after restart. */
    public function reconcile(): int
    {
        $statement = Database::pdo()->prepare(
            "SELECT uuid, status, attempts,
                (reserved_at IS NULL OR timeout_seconds = 0
                    OR TIMESTAMPDIFF(SECOND, reserved_at, CURRENT_TIMESTAMP) >= timeout_seconds) AS expired,
                (reserved_at IS NOT NULL
                    AND TIMESTAMPDIFF(SECOND, reserved_at, CURRENT_TIMESTAMP) < 30) AS launching
             FROM jobs WHERE machine_id = ?
               AND (status IN ('reserved', 'running') OR (status = 'queued' AND attempts > 0))"
        );
        $statement->execute([CommercialContext::machineId()]);
        $changed = 0;
        foreach ($statement->fetchAll() as $job) {
            $uuid = (string) $job['uuid'];
            $attempt = (int) $job['attempts'];
            if ($job['status'] === 'queued') {
                $changed += (int) $this->requireReconciliation($uuid, $attempt, 'previous_attempt_unreconciled');
            } elseif ((bool) $job['expired']) {
                // A timeout does not establish that the handler or its effects stopped.
                $changed += (int) $this->requireReconciliation($uuid, $attempt, 'execution_timeout');
            } elseif ($job['status'] === 'reserved' && (bool) $job['launching']) {
                continue;
            } elseif ($this->acquireExecutionLock($uuid)) {
                try {
                    $changed += (int) $this->requireReconciliation($uuid, $attempt, 'worker_interrupted');
                } finally {
                    $this->releaseExecutionLock($uuid);
                }
            }
        }
        return $changed;
    }

    private function acquireExecutionLock(string $uuid): bool
    {
        $statement = Database::pdo()->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$this->executionLockName($uuid)]);
        $result = $statement->fetchColumn();
        if ($result === null || $result === false) {
            throw new \RuntimeException('Job ownership could not be checked.');
        }
        return (int) $result === 1;
    }

    private function executionLockName(string $uuid): string
    {
        $database = (string) Database::pdo()->query('SELECT DATABASE()')->fetchColumn();
        return 'microsysadmin.job.' . sha1($database . ':' . $uuid);
    }

    public function cancel(string $uuid): void
    {
        Database::pdo()->prepare(
            "UPDATE jobs SET status = 'cancelled', cancelled_at = ?, updated_at = ?
             WHERE uuid = ? AND status = 'queued' AND attempts = 0 AND organization_id = ? AND machine_id = ?"
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
