<?php

declare(strict_types=1);

namespace SysAdmin;

final class HealthCheckService
{
    public function all(): array
    {
        $checks = [];
        $checks[] = $this->check('application', is_file(APP_ROOT . '/index.php') && is_file(APP_ROOT . '/VERSION'), MachineIdentity::version());
        try {
            Database::pdo()->query('SELECT 1')->fetchColumn();
            $checks[] = $this->check('database', true, 'Connected');
        } catch (\Throwable $exception) {
            $checks[] = $this->check('database', false, $exception->getMessage());
        }
        foreach (EnvironmentConfig::diagnostics() as $environment) {
            $checks[] = [
                'name' => 'environment.' . $environment['key'],
                'ok' => $environment['status'] === 'ok',
                'status' => $environment['status'],
                'message' => $environment['message'],
            ];
        }
        $checks[] = $this->storageSpaceCheck();
        foreach ((new ServiceControlManager())->states() as $name => $state) {
            if (in_array($name, ['apache', 'mysql'], true)) {
                $checks[] = $this->check('service.' . $name, (bool) ($state['running'] ?? false), (string) ($state['label'] ?? 'Unknown'));
            }
        }
        $checks[] = $this->binaryCheck('composer', EnvironmentConfig::path('composer_binary'));
        $checks[] = $this->binaryCheck('git', EnvironmentConfig::path('git_binary'));
        $checks[] = $this->schedulerCheck();
        $checks[] = $this->backupRecencyCheck();
        $checks[] = $this->check('agent', (bool) config_value('agent.enabled', true), 'Agent boundary ' . ((bool) config_value('agent.enabled', true) ? 'enabled' : 'disabled'));
        $checks[] = $this->updateCheck();
        $hubClient = HubManager::client();
        $hub = $hubClient->status();
        $hubEnabled = (bool) ($hub['enabled'] ?? $hubClient->enabled());
        $hubStatus = (string) ($hub['status'] ?? ($hubEnabled ? 'unknown' : 'disabled'));
        $checks[] = [
            'name' => 'hub',
            'ok' => !$hubEnabled || in_array($hubStatus, ['connected', 'disabled', 'disconnected'], true),
            'status' => $hubEnabled ? $hubStatus : 'optional',
            'message' => $hubEnabled ? $hubStatus : 'Not configured',
        ];

        $failed = count(array_filter($checks, static fn (array $check): bool => !$check['ok']));
        return [
            'ok' => $failed === 0,
            'status' => $failed === 0 ? 'healthy' : 'degraded',
            'failed' => $failed,
            'checks' => $checks,
            'checked_at' => gmdate(DATE_ATOM),
            'request_id' => RequestContext::requestId(),
        ];
    }

    /**
     * Evaluate only checks that prove the installed release can safely serve
     * MicroSysAdmin. Operational services may intentionally be stopped and
     * backup age may be advisory, so they remain visible in all() without
     * causing an otherwise successful update or rollback to be rejected.
     *
     * @return array{ok:bool,failures:array<int,array<string,mixed>>}
     */
    public function releaseGate(array $report): array
    {
        $requiredNames = ['application', 'database', 'storage_space', 'scheduler', 'agent'];
        $checks = (array) ($report['checks'] ?? []);
        $failures = array_values(array_filter(
            $checks,
            static function (array $check) use ($requiredNames): bool {
                $name = (string) ($check['name'] ?? '');
                $required = in_array($name, $requiredNames, true)
                    || str_starts_with($name, 'environment.');
                return $required && !($check['ok'] ?? false);
            }
        ));
        $reportedNames = array_map(
            static fn (array $check): string => (string) ($check['name'] ?? ''),
            $checks
        );
        foreach ($requiredNames as $requiredName) {
            if (!in_array($requiredName, $reportedNames, true)) {
                $failures[] = [
                    'name' => $requiredName,
                    'ok' => false,
                    'status' => 'missing',
                    'message' => 'Required release-gate check was not reported.',
                ];
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
    }

    private function schedulerCheck(): array
    {
        try {
            Database::pdo()->query('SELECT COUNT(*) FROM scheduled_tasks')->fetchColumn();
            return $this->check('scheduler', is_file(APP_ROOT . '/cron/runner.php'), 'Runner present');
        } catch (\Throwable $exception) {
            return $this->check('scheduler', false, $exception->getMessage());
        }
    }

    private function storageSpaceCheck(): array
    {
        $path = EnvironmentConfig::path('storage_root', STORAGE_PATH);
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return $this->check('storage_space', false, 'Unable to read storage capacity.');
        }
        $freePercent = ($free / $total) * 100;
        $minimumBytes = 1024 * 1024 * 1024;
        $ok = $free >= $minimumBytes && $freePercent >= 5;
        $status = !$ok ? 'error' : ($freePercent < 15 ? 'warning' : 'ok');
        return [
            'name' => 'storage_space',
            'ok' => $ok,
            'status' => $status,
            'message' => sprintf(
                '%s free of %s (%.1f%% free)',
                human_bytes((int) $free),
                human_bytes((int) $total),
                $freePercent
            ),
        ];
    }

    private function backupRecencyCheck(): array
    {
        try {
            $latest = Database::pdo()->query(
                "SELECT finished_at FROM backup_jobs WHERE status = 'completed' ORDER BY id DESC LIMIT 1"
            )->fetchColumn();
            if ($latest === false) {
                return ['name' => 'backup_recency', 'ok' => true, 'status' => 'not_configured', 'message' => 'No completed backup yet'];
            }
            $age = time() - (int) strtotime((string) $latest);
            return [
                'name' => 'backup_recency',
                'ok' => $age <= 172800,
                'status' => $age <= 86400 ? 'ok' : ($age <= 172800 ? 'warning' : 'error'),
                'message' => 'Latest backup: ' . $latest,
            ];
        } catch (\Throwable $exception) {
            return $this->check('backup_recency', false, $exception->getMessage());
        }
    }

    private function updateCheck(): array
    {
        $history = Database::pdo()->query('SELECT status, to_version, completed_at FROM update_history ORDER BY id DESC LIMIT 1')->fetch();
        return [
            'name' => 'update_service',
            'ok' => !$history || $history['status'] !== 'failed',
            'status' => !$history ? 'not_run' : (string) $history['status'],
            'message' => !$history ? 'No update has been applied' : 'Latest target: ' . $history['to_version'],
        ];
    }

    private function binaryCheck(string $name, string $path): array
    {
        return $this->check($name, is_file($path) && is_executable($path), $path);
    }

    private function check(string $name, bool $ok, string $message): array
    {
        return ['name' => $name, 'ok' => $ok, 'status' => $ok ? 'ok' : 'error', 'message' => $message];
    }
}
