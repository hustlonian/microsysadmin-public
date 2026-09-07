<?php

declare(strict_types=1);

namespace SysAdmin;

final class JobWorker
{
    public function runOnce(): bool
    {
        $queue = new JobQueue();
        $job = $queue->reserve();
        if (!$job) {
            return false;
        }

        try {
            $queue->progress((string) $job['uuid'], 5, 'Job started.');
            $result = $this->handle((string) $job['type'], (array) $job['payload'], $queue, (string) $job['uuid']);
            $queue->complete((string) $job['uuid'], $result);
        } catch (\Throwable $exception) {
            $queue->fail((string) $job['uuid'], $exception);
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
