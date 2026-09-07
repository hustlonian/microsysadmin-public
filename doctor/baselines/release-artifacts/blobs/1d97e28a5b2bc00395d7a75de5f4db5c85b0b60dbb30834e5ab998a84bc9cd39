<?php

declare(strict_types=1);

namespace SysAdmin;

final class CronService implements \SysAdmin\Contracts\ScheduledTaskOperationsInterface
{
    public function systemJobs(int $limit = 200): array
    {
        $jobs = [];
        try {
            $result = (new CommandRunner())->runReadOnly(['crontab', '-l'], 10, null, false);
            foreach (preg_split('/\R/', trim($result['output'] ?: $result['error'])) as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $jobs[] = ['source' => 'user crontab', 'line' => $line];
                }
            }
        } catch (\Throwable) {
        }

        foreach (['/etc/crontab'] as $file) {
            if (is_readable($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && $line[0] !== '#') {
                        $jobs[] = ['source' => $file, 'line' => $line];
                    }
                }
            }
        }

        return array_slice($jobs, 0, $limit);
    }

    public function managed(): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT * FROM scheduled_tasks
             WHERE organization_id = ? AND machine_id = ?
             ORDER BY enabled DESC, name'
        );
        $statement->execute([CommercialContext::organizationId(), CommercialContext::machineId()]);
        return $statement->fetchAll();
    }

    public function create(string $name, string $schedule, string $scriptName): int
    {
        if (str_contains(strtolower($scriptName), 'backup')) {
            LicenseManager::service()->assert('backups.scheduled');
        }
        if (!preg_match('/^[A-Za-z0-9_. -]{3,120}$/', $name)) {
            throw new \InvalidArgumentException('Task name is invalid.');
        }
        if (!preg_match('/^[\d\*\/,\- ]{9,80}$/', $schedule)) {
            throw new \InvalidArgumentException('Schedule must be a five-part cron expression.');
        }
        $scriptRoot = EnvironmentConfig::path('script_root', STORAGE_PATH . '/scripts');
        if (!is_file($scriptRoot . '/' . basename($scriptName))) {
            throw new \InvalidArgumentException('Script does not exist in storage/scripts.');
        }
        $stmt = Database::pdo()->prepare('INSERT INTO scheduled_tasks (organization_id, machine_id, name, schedule, script_name, enabled, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([CommercialContext::organizationId(), CommercialContext::machineId(), $name, $schedule, basename($scriptName), Auth::id(), $now, $now]);
        $id = (int) Database::pdo()->lastInsertId();
        AuditLogger::info('cron.create', ['name' => $name, 'script' => $scriptName]);
        return $id;
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE scheduled_tasks SET enabled = ?, updated_at = ?
             WHERE id = ? AND organization_id = ? AND machine_id = ?'
        );
        $stmt->execute([
            $enabled ? 1 : 0,
            date('Y-m-d H:i:s'),
            $id,
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
        ]);
        AuditLogger::info('cron.toggle', ['id' => $id, 'enabled' => $enabled]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM scheduled_tasks
             WHERE id = ? AND organization_id = ? AND machine_id = ?'
        );
        $stmt->execute([$id, CommercialContext::organizationId(), CommercialContext::machineId()]);
        AuditLogger::warning('cron.delete', ['id' => $id]);
    }
}
