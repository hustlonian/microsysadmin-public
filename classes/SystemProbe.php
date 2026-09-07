<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\BackupJob;
use Throwable;

final class SystemProbe implements \SysAdmin\Contracts\SystemMetricsInterface
{
    private const SERVER_INFO_CACHE_TTL = 30;
    private const METRIC_RETENTION_CLEANUP_TTL = 3600;

    public function metrics(): array
    {
        $cpu = $this->cpuPercent();
        $memory = $this->memory();
        $disk = $this->diskMetrics();
        $network = $this->networkTotals();

        $data = [
            'cpu' => $cpu,
            'ram' => $memory['ram'],
            'swap' => $memory['swap'],
            'disk' => $disk,
            'load' => sys_getloadavg() ?: [0, 0, 0],
            'services' => $this->serviceStates(),
            'php_version' => PHP_VERSION,
            'uptime' => $this->uptime(),
            'network' => $network,
            'temperature' => $this->temperature(),
            'top_processes' => $this->topProcesses(8),
            'recent_logs' => (new LogService())->recentEvents(8),
            'cron_jobs' => (new CronService())->systemJobs(8),
            'git' => (new GitService())->summary(),
            'backup_jobs' => BackupJob::recent(5),
            'time' => date('c'),
        ];

        $this->recordMetric($data);

        return $data;
    }

    public function serverInfo(): array
    {
        $cachePath = STORAGE_PATH . '/cache/server-info.json';
        $info = $this->readArrayCache($cachePath, self::SERVER_INFO_CACHE_TTL);
        if ($info === null) {
            $runner = new CommandRunner();
            $disk = $this->diskMetrics();
            $info = [
                'hostname' => gethostname() ?: php_uname('n'),
                'kernel' => php_uname('r') . ' ' . php_uname('m'),
                'distribution' => $this->distribution(),
                'php_version' => PHP_VERSION,
                'apache_version' => $this->commandText(fn () => $runner->system('apache_version', 15, false)),
                'mysql_version' => $this->commandText(fn () => $runner->system('mysql_version', 15, false)),
                'composer_version' => $this->commandText(fn () => $runner->system('composer_version', 15, false)),
                'storage_scope' => $this->storageScopeText($disk),
                'disk_layout' => $this->commandText(fn () => $runner->runReadOnly(['df', '-hT', $disk['path']], 20, null, false)),
                'mounted_drives' => $this->commandText(fn () => $runner->system('mounts', 15, false)),
                'network_interfaces' => $this->interfaces(),
                'ip_addresses' => $this->commandText(fn () => $runner->system('network', 15, false)),
                'open_ports' => $this->commandText(fn () => $runner->system('ports', 15, false)),
                'php_modules' => $this->commandText(fn () => $runner->system('php_modules', 15, false)),
            ];
            $this->writeArrayCache($cachePath, $info);
        }

        $info['uptime'] = $this->uptime();
        return $info;
    }

    public function healthChecks(): array
    {
        $memory = $this->memory();
        $disk = $this->diskMetrics();
        $services = $this->serviceStates();
        $checks = [];
        $cpu = $this->cpuPercent();
        $checks[] = $this->check('CPU below 85%', $cpu < 85, $cpu . '%');
        $checks[] = $this->check('RAM below 90%', $memory['ram']['percent'] < 90, $memory['ram']['percent'] . '%');
        $checks[] = $this->check('Filesystem below 90%', $disk['percent'] < 90, $disk['percent'] . '%');
        $checks[] = $this->check('Apache running', ($services['apache']['running'] ?? false), $services['apache']['label'] ?? 'unknown');
        $checks[] = $this->check('MySQL running', ($services['mysql']['running'] ?? false), $services['mysql']['label'] ?? 'unknown');
        $checks[] = $this->check('Writable logs', is_writable(STORAGE_PATH . '/logs'), STORAGE_PATH . '/logs');
        $backupRoot = EnvironmentConfig::path('backup_root', STORAGE_PATH . '/backups');
        $checks[] = $this->check('Writable backups', is_writable($backupRoot), $backupRoot);

        return $checks;
    }

    public function topProcesses(int $limit = 50): array
    {
        try {
            $result = (new CommandRunner())->runReadOnly(
                ['ps', '-eo', 'pid,user,pcpu,pmem,comm,args', '--sort=-pcpu'],
                10,
                null,
                false
            );
            $lines = preg_split('/\R/', trim($result['output']));
            array_shift($lines);
            $rows = [];
            foreach (array_slice($lines, 0, $limit) as $line) {
                $parts = preg_split('/\s+/', trim($line), 6);
                if (count($parts) < 6) {
                    continue;
                }
                $rows[] = [
                    'pid' => (int) $parts[0],
                    'owner' => $parts[1],
                    'cpu' => (float) $parts[2],
                    'memory' => (float) $parts[3],
                    'name' => $parts[4],
                    'command' => $parts[5],
                ];
            }
            return $rows;
        } catch (Throwable $exception) {
            return [['pid' => 0, 'owner' => 'system', 'cpu' => 0, 'memory' => 0, 'name' => 'ps unavailable', 'command' => $exception->getMessage()]];
        }
    }

    public function serviceStates(): array
    {
        return (new ServiceControlManager())->states();
    }

    private function xamppStates(): array
    {
        try {
            $result = (new CommandRunner())->runReadOnly([EnvironmentConfig::path('xampp_root') . '/lampp', 'status'], 10, null, false);
            $text = $result['output'] . "\n" . $result['error'];
            $states = [];
            foreach (['apache' => 'Apache', 'mysql' => 'MySQL'] as $key => $service) {
                if (preg_match('/' . preg_quote($service, '/') . '\s+is\s+running/i', $text)) {
                    $states[$key] = true;
                } elseif (preg_match('/' . preg_quote($service, '/') . '\s+is\s+(?:not\s+running|stopped)/i', $text)) {
                    $states[$key] = false;
                }
            }
            return $states;
        } catch (Throwable) {
            return [];
        }
    }

    private function cpuPercent(): float
    {
        $sample = $this->procStat();
        $sample['sampled_at'] = microtime(true);
        $cachePath = STORAGE_PATH . '/cache/cpu-sample.json';
        $previous = $this->readArrayCache($cachePath, 120);
        $this->writeArrayCache($cachePath, $sample);

        if ($previous !== null) {
            $idleDelta = ($sample['idle'] + $sample['iowait']) - ((int) $previous['idle'] + (int) $previous['iowait']);
            $totalDelta = $sample['total'] - (int) $previous['total'];
            if ($totalDelta > 0) {
                return round(max(0, min(100, (1 - ($idleDelta / $totalDelta)) * 100)), 1);
            }
        }

        $load = sys_getloadavg();
        $processorCount = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuInfo = (string) @file_get_contents('/proc/cpuinfo');
            $processorCount = max(1, preg_match_all('/^processor\s*:/m', $cpuInfo));
        }
        return round(max(0, min(100, (((float) ($load[0] ?? 0)) / $processorCount) * 100)), 1);
    }

    private function procStat(): array
    {
        $line = is_readable('/proc/stat') ? (string) strtok((string) file_get_contents('/proc/stat'), "\n") : '';
        $parts = preg_split('/\s+/', trim($line));
        array_shift($parts);
        $values = array_map('intval', $parts ?: []);

        return [
            'idle' => $values[3] ?? 0,
            'iowait' => $values[4] ?? 0,
            'total' => array_sum($values),
        ];
    }

    private function memory(): array
    {
        $info = [];
        if (is_readable('/proc/meminfo')) {
            foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^([^:]+):\s+(\d+)/', $line, $m)) {
                    $info[$m[1]] = (int) $m[2] * 1024;
                }
            }
        }

        $total = $info['MemTotal'] ?? 0;
        $available = $info['MemAvailable'] ?? ($info['MemFree'] ?? 0);
        $used = max(0, $total - $available);
        $swapTotal = $info['SwapTotal'] ?? 0;
        $swapFree = $info['SwapFree'] ?? 0;
        $swapUsed = max(0, $swapTotal - $swapFree);

        return [
            'ram' => [
                'used' => $used,
                'available' => $available,
                'total' => $total,
                'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
                'used_human' => human_bytes($used),
                'total_human' => human_bytes($total),
            ],
            'swap' => [
                'used' => $swapUsed,
                'free' => $swapFree,
                'total' => $swapTotal,
                'percent' => $swapTotal > 0 ? round(($swapUsed / $swapTotal) * 100, 1) : 0,
                'used_human' => human_bytes($swapUsed),
                'total_human' => human_bytes($swapTotal),
            ],
        ];
    }

    private function diskMetrics(): array
    {
        $diskPath = PathGuard::storageScopeRoot();
        $diskTotal = @disk_total_space($diskPath) ?: 0;
        $filesystemFree = @disk_free_space($diskPath) ?: 0;
        $diskUsed = $diskTotal > 0 ? max(0, $diskTotal - $filesystemFree) : 0;
        $diskFree = $filesystemFree;
        $percent = $diskTotal > 0 ? min(100, round(($diskUsed / $diskTotal) * 100, 1)) : 0;
        $usedHuman = human_bytes($diskUsed);
        $totalHuman = human_bytes($diskTotal);

        return [
            'path' => $diskPath,
            'used' => $diskUsed,
            'free' => $diskFree,
            'total' => $diskTotal,
            'percent' => $percent,
            'used_human' => $usedHuman,
            'free_human' => human_bytes($diskFree),
            'total_human' => $totalHuman,
            'summary' => $usedHuman . ' / ' . $totalHuman,
            'filesystem_free' => $filesystemFree,
            'filesystem_free_human' => human_bytes($filesystemFree),
        ];
    }

    private function storageScopeText(array $disk): string
    {
        return implode("\n", [
            'Filesystem containing: ' . $disk['path'],
            'Used: ' . $disk['used_human'],
            'Capacity: ' . $disk['total_human'],
            'Filesystem usage: ' . $disk['percent'] . '%',
            'Available: ' . $disk['free_human'],
        ]);
    }

    private function uptime(): string
    {
        if (!is_readable('/proc/uptime')) {
            return 'Unknown';
        }
        $seconds = (float) explode(' ', trim((string) file_get_contents('/proc/uptime')))[0];
        return elapsed($seconds);
    }

    private function networkTotals(): array
    {
        $rx = 0;
        $tx = 0;
        if (is_readable('/proc/net/dev')) {
            foreach (file('/proc/net/dev', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (!str_contains($line, ':')) {
                    continue;
                }
                [$iface, $data] = explode(':', $line, 2);
                if (trim($iface) === 'lo') {
                    continue;
                }
                $parts = preg_split('/\s+/', trim($data));
                $rx += (int) ($parts[0] ?? 0);
                $tx += (int) ($parts[8] ?? 0);
            }
        }

        return [
            'rx' => $rx,
            'tx' => $tx,
            'rx_human' => human_bytes($rx),
            'tx_human' => human_bytes($tx),
        ];
    }

    private function temperature(): ?array
    {
        foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $file) {
            $raw = trim((string) @file_get_contents($file));
            if (is_numeric($raw)) {
                return ['celsius' => round(((float) $raw) / 1000, 1)];
            }
        }

        return null;
    }

    private function processNames(): array
    {
        $processes = [];
        foreach (glob('/proc/[0-9]*/comm') ?: [] as $comm) {
            $name = trim((string) @file_get_contents($comm));
            if ($name !== '') {
                $processes[$name] = true;
            }
        }

        return array_keys($processes);
    }

    private function namesContain(array $processes, array $names): bool
    {
        foreach ($processes as $process) {
            foreach ($names as $candidate) {
                if ($process === $candidate || str_contains($process, $candidate)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function tcpPortListening(int $port): bool
    {
        $hexPort = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
            if (!is_readable($file)) {
                continue;
            }
            foreach (array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], 1) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                $local = $parts[1] ?? '';
                $state = $parts[3] ?? '';
                if ($state === '0A' && str_ends_with($local, ':' . $hexPort)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function distribution(): string
    {
        if (is_readable('/etc/os-release')) {
            foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'PRETTY_NAME=')) {
                    return trim(substr($line, 12), '"');
                }
            }
        }

        return php_uname('s');
    }

    private function interfaces(): array
    {
        $rows = [];
        foreach (glob('/sys/class/net/*') ?: [] as $iface) {
            $name = basename($iface);
            $rows[] = [
                'name' => $name,
                'state' => trim((string) @file_get_contents($iface . '/operstate')) ?: 'unknown',
                'mac' => trim((string) @file_get_contents($iface . '/address')) ?: '',
            ];
        }

        return $rows;
    }

    private function readArrayCache(string $path, int $ttl): ?array
    {
        if (!is_readable($path)) {
            return null;
        }

        $payload = json_decode((string) @file_get_contents($path), true);
        if (
            !is_array($payload)
            || !isset($payload['created_at'], $payload['data'])
            || !is_array($payload['data'])
            || time() - (int) $payload['created_at'] > $ttl
        ) {
            return null;
        }

        return $payload['data'];
    }

    private function writeArrayCache(string $path, array $data): void
    {
        $payload = json_encode([
            'created_at' => time(),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }

    private function commandText(callable $callback): string
    {
        try {
            $result = $callback();
            return trim(($result['output'] ?: $result['error']) ?: 'Unavailable');
        } catch (Throwable $exception) {
            return 'Unavailable: ' . $exception->getMessage();
        }
    }

    private function check(string $name, bool $ok, string $value): array
    {
        return ['name' => $name, 'ok' => $ok, 'value' => $value];
    }

    private function recordMetric(array $data): void
    {
        try {
            $stmt = Database::pdo()->prepare('INSERT INTO metric_samples (cpu_percent, ram_percent, disk_percent, network_rx, network_tx, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $data['cpu'],
                $data['ram']['percent'],
                $data['disk']['percent'],
                $data['network']['rx'],
                $data['network']['tx'],
                date('Y-m-d H:i:s'),
            ]);
            $cleanupMarker = STORAGE_PATH . '/cache/metric-retention-cleanup';
            $lastCleanup = @filemtime($cleanupMarker) ?: 0;
            if (time() - $lastCleanup > self::METRIC_RETENTION_CLEANUP_TTL) {
                Database::pdo()->exec('DELETE FROM metric_samples WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
                @file_put_contents($cleanupMarker, (string) time(), LOCK_EX);
            }
        } catch (Throwable) {
        }
    }
}
