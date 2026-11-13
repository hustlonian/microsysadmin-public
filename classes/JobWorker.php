<?php

declare(strict_types=1);

namespace SysAdmin;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class JobWorker
{
    public function runOnce(): bool
    {
        // GNU timeout owns a separate process group and survives loss of this PHP
        // supervisor. Killing only the executor leaves its nested commands alive.
        $timeoutBinary = $this->timeoutBinary();
        $queue = new JobQueue();
        $queue->reconcile();
        $job = $queue->reserve();
        if (!$job) {
            return false;
        }

        $started = hrtime(true);
        try {
            $process = new Process([
                $timeoutBinary, '--signal=KILL', (string) $job['timeout_seconds'],
                PHP_BINARY, APP_ROOT . '/scripts/job-worker.php', '--execute',
                (string) $job['uuid'], (string) $job['attempts'],
            ], APP_ROOT);
            $process->setTimeout((int) $job['timeout_seconds'] + 5);
            $process->disableOutput();
            $process->run();
            // A clean process exit alone is not durable completion evidence.
            $queue->requireReconciliation((string) $job['uuid'], (int) $job['attempts'], 'worker_interrupted');
        } catch (ProcessTimedOutException) {
            $queue->requireReconciliation((string) $job['uuid'], (int) $job['attempts'], 'execution_timeout');
        } catch (\Throwable $exception) {
            if ((hrtime(true) - $started) / 1e9 >= (int) $job['timeout_seconds']) {
                $queue->requireReconciliation((string) $job['uuid'], (int) $job['attempts'], 'execution_timeout');
            } else {
                $queue->fail((string) $job['uuid'], (int) $job['attempts'], $exception);
            }
        }
        return true;
    }

    private function timeoutBinary(): string
    {
        foreach (['/usr/bin/timeout', '/bin/timeout'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        throw new \RuntimeException('Background jobs require the GNU coreutils timeout command. No job was reserved.');
    }

    /** Internal CLI entry: atomically claim one reserved attempt before effects. */
    public function execute(string $uuid, int $attempt): bool
    {
        $queue = new JobQueue();
        $job = $queue->beginExecution($uuid, $attempt);
        if ($job === null) {
            return false;
        }
        try {
            $queue->progress($uuid, $attempt, 5, 'Job started.');
            $result = $this->handle((string) $job['type'], (array) $job['payload'], $queue, (string) $job['uuid']);
            // A health inspection can finish successfully and report degraded
            // services. Preserve that report; it is not an uncertain mutation.
            // Every effectful handler retains the fail-closed outcome rule.
            if ($job['type'] !== 'maintenance.health-check'
                && is_array($result) && array_key_exists('ok', $result) && $result['ok'] === false) {
                throw new \RuntimeException('Job handler reported an unsuccessful result.');
            }
            $queue->complete($uuid, $attempt, $result);
        } catch (\Throwable $exception) {
            $queue->fail($uuid, $attempt, $exception);
        } finally {
            $queue->releaseExecutionLock($uuid);
        }
        return true;
    }

    public function run(int $idleMilliseconds = 1000): never
    {
        while (true) {
            if (!$this->runOnce()) {
                usleep(max(100, $idleMilliseconds) * 1000);
            }
        }
    }

    private function handle(string $type, array $payload, JobQueue $queue, string $uuid): mixed
    {
        return match ($type) {
            'backup.database' => (new BackupService())->database((string) ($payload['database'] ?? '')),
            'backup.folder' => (new BackupService())->folder((string) ($payload['path'] ?? '')),
            'backup.restore' => (new BackupService())->restore(
                (string) ($payload['archive'] ?? ''),
                (string) ($payload['target'] ?? '')
            ),
            'media.convert' => (new MediaService())->convert(
                (string) ($payload['path'] ?? ''),
                (string) ($payload['format'] ?? '')
            ),
            'composer.run' => (new LaravelService())->runComposer(
                (string) ($payload['project'] ?? ''),
                (string) ($payload['command'] ?? '')
            ),
            'artisan.run' => (new LaravelService())->runArtisan(
                (string) ($payload['project'] ?? ''),
                (string) ($payload['command'] ?? ''),
                (bool) ($payload['allow_any'] ?? false)
            ),
            'git.action' => (new GitService())->action(
                (string) ($payload['path'] ?? ''),
                (string) ($payload['action'] ?? ''),
                (string) ($payload['message'] ?? '')
            ),
            'website.provision' => (new HostingService())->createSite(
                (string) ($payload['name'] ?? ''),
                (string) ($payload['root'] ?? ''),
                (string) ($payload['domain'] ?? '')
            ),
            'mysql.import' => $this->mysqlImport($payload),
            'script.run' => (new ScriptService())->run((string) ($payload['script'] ?? '')),
            'notification.email' => (new NotificationService())->sendTest((string) ($payload['email'] ?? '')),
            'maintenance.health-check' => (new HealthCheckService())->all(),
            'hub.heartbeat' => HubManager::client()->heartbeat(),
            'webhook.deliver' => (new WebhookService())->deliver((int) ($payload['delivery_id'] ?? 0)),
            default => throw new \InvalidArgumentException('No handler is registered for this job type.'),
        };
    }

    private function mysqlImport(array $payload): array
    {
        $path = (string) ($payload['path'] ?? '');
        $workspace = EnvironmentConfig::path('temporary_workspace_root', STORAGE_PATH . '/workspace') . '/imports';
        $real = realpath($path);
        $root = realpath($workspace);
        if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Staged SQL import is unavailable.');
        }
        try {
            return (new MysqlService())->importSql((string) ($payload['database'] ?? ''), [
                'error' => UPLOAD_ERR_OK,
                'name' => (string) ($payload['name'] ?? 'import.sql'),
                'tmp_name' => $real,
            ]);
        } finally {
            @unlink($real);
        }
    }
}
