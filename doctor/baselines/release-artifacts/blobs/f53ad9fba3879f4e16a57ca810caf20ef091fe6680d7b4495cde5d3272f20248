<?php

declare(strict_types=1);

namespace SysAdmin;

final class DemoMode
{
    public static function enabled(): bool
    {
        if ((bool) config_value('installation.demo_mode', false)) {
            return true;
        }
        try {
            return (\SysAdmin\Models\Setting::all()['demo_mode'] ?? '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function seed(): array
    {
        $path = self::path();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $data = self::fixtures();
        $data['seeded_at'] = gmdate(DATE_ATOM);
        $data['reset_at'] = gmdate(DATE_ATOM, time() + (int) config_value('demo.reset_interval', 3600));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $data;
    }

    public static function reset(): array
    {
        return self::seed();
    }

    public static function data(): array
    {
        if (!is_file(self::path())) {
            return self::seed();
        }
        $data = json_decode((string) file_get_contents(self::path()), true);
        if (!is_array($data) || strtotime((string) ($data['reset_at'] ?? '')) <= time()) {
            return self::reset();
        }
        return $data;
    }

    public static function api(string $endpoint): array
    {
        $data = self::data();
        $action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'read');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return [
                'ok' => true,
                'data' => [
                    'simulated' => true,
                    'endpoint' => $endpoint,
                    'action' => $action,
                    'message' => 'Demonstration action completed without touching the host.',
                    'output' => self::simulatedOutput($endpoint, $action),
                ],
            ];
        }

        return ['ok' => true, 'data' => match ($endpoint) {
            'metrics' => $data['metrics'],
            'processes' => $data['processes'],
            'services' => $data['services'],
            'hosting' => $data['hosting'],
            'git' => $data['git'],
            'backups' => $data['backups'],
            'logs' => $data['logs'],
            'analytics' => $data['analytics'],
            default => ['simulated' => true, 'endpoint' => $endpoint, 'items' => []],
        }];
    }

    public static function path(): string
    {
        return STORAGE_PATH . '/demo/fixtures.json';
    }

    private static function fixtures(): array
    {
        $services = [
            'apache' => ['label' => 'Running', 'running' => true, 'driver' => 'simulation'],
            'mysql' => ['label' => 'Running', 'running' => true, 'driver' => 'simulation'],
            'ssh' => ['label' => 'Listening', 'running' => true, 'driver' => 'simulation'],
            'cron' => ['label' => 'Running', 'running' => true, 'driver' => 'simulation'],
            'php-fpm' => ['label' => 'Stopped', 'running' => false, 'driver' => 'simulation'],
        ];
        $sites = [
            ['name' => 'Acme Portal', 'slug' => 'acme-portal', 'document_root' => '/demo/projects/acme-portal', 'status' => 'active', 'managed' => true, 'laravel' => true, 'url' => '#', 'database_name' => 'demo_acme'],
            ['name' => 'Agency Website', 'slug' => 'agency-site', 'document_root' => '/demo/projects/agency-site', 'status' => 'active', 'managed' => true, 'laravel' => true, 'url' => '#', 'database_name' => 'demo_agency'],
            ['name' => 'Static Campaign', 'slug' => 'campaign', 'document_root' => '/demo/projects/campaign', 'status' => 'active', 'managed' => false, 'laravel' => false, 'url' => '#', 'database_name' => null],
        ];
        $metrics = [
            'cpu' => 23.4,
            'ram' => ['percent' => 47.8, 'used_human' => '7.6 GB', 'total_human' => '16 GB'],
            'swap' => ['percent' => 2.1, 'used_human' => '86 MB', 'total_human' => '4 GB'],
            'disk' => ['percent' => 61.2, 'used_human' => '306 GB', 'total_human' => '500 GB', 'summary' => '306 GB / 500 GB'],
            'load' => [0.48, 0.62, 0.55],
            'services' => $services,
            'php_version' => PHP_VERSION,
            'uptime' => '12d 7h',
            'network' => ['rx' => 12884901888, 'tx' => 4294967296, 'rx_human' => '12 GB', 'tx_human' => '4 GB'],
            'temperature' => ['celsius' => 48.2],
            'top_processes' => [
                ['pid' => 1421, 'owner' => 'demo', 'cpu' => 4.2, 'memory' => 2.8, 'name' => 'php', 'command' => 'php artisan queue:work'],
                ['pid' => 812, 'owner' => 'mysql', 'cpu' => 2.1, 'memory' => 8.4, 'name' => 'mysqld', 'command' => 'mysqld'],
            ],
            'recent_logs' => [
                ['level' => 'warning', 'line' => 'Demo: slow request recovered in 842ms'],
                ['level' => 'info', 'line' => 'Demo: scheduled backup completed'],
            ],
            'cron_jobs' => [],
            'git' => [
                ['path' => '/demo/projects/acme-portal', 'branch' => 'main', 'status' => 'clean'],
            ],
            'backup_jobs' => [
                ['type' => 'database', 'status' => 'completed', 'created_at' => date('Y-m-d H:i:s', time() - 3600)],
            ],
            'time' => date('c'),
        ];

        $analytics = [];
        for ($index = 24; $index >= 0; $index--) {
            $analytics[] = [
                'cpu_percent' => 18 + (($index * 7) % 31),
                'ram_percent' => 44 + (($index * 3) % 9),
                'disk_percent' => 61.2,
                'network_rx' => 12000000000 + ($index * 12000000),
                'network_tx' => 4000000000 + ($index * 5000000),
                'created_at' => date('Y-m-d H:i:s', time() - ($index * 300)),
            ];
        }

        return [
            'metrics' => $metrics,
            'services' => $services,
            'processes' => $metrics['top_processes'],
            'hosting' => ['sites' => $sites, 'roots' => [['path' => '/demo/projects', 'writable' => false]], 'summary' => ['sites' => 3, 'managed' => 2, 'discovered' => 1, 'writable_roots' => 0]],
            'databases' => [
                ['name' => 'demo_acme', 'tables' => 18, 'size' => '34 MB'],
                ['name' => 'demo_agency', 'tables' => 12, 'size' => '11 MB'],
            ],
            'git' => [
                ['path' => '/demo/projects/acme-portal', 'branch' => 'main', 'status' => 'clean', 'last_commit' => 'Improve onboarding'],
                ['path' => '/demo/projects/agency-site', 'branch' => 'feature/portfolio', 'status' => 'modified', 'last_commit' => 'Add project gallery'],
            ],
            'backups' => [
                'archives' => [
                    ['name' => 'demo_acme-20260727.sql.zip', 'size_human' => '4.2 MB', 'modified' => date('Y-m-d H:i:s', time() - 3600), 'path' => '/demo/backups/demo_acme.sql.zip'],
                ],
                'history' => [
                    ['type' => 'database', 'target' => 'demo_acme', 'status' => 'completed', 'progress' => 100, 'archive_path' => '/demo/backups/demo_acme.sql.zip', 'created_at' => date('Y-m-d H:i:s', time() - 3600)],
                ],
            ],
            'logs' => [
                ['level' => 'info', 'message' => 'Demo site provisioned successfully', 'created_at' => date('c', time() - 900)],
                ['level' => 'warning', 'message' => 'Demo disk forecast reaches 80% in 47 days', 'created_at' => date('c', time() - 600)],
            ],
            'audit' => [
                ['action' => 'hosting.site.create', 'user' => 'demo-admin', 'created_at' => date('c', time() - 7200)],
                ['action' => 'backup.database', 'user' => 'demo-operator', 'created_at' => date('c', time() - 3600)],
            ],
            'analytics' => $analytics,
        ];
    }

    private static function simulatedOutput(string $endpoint, string $action): string
    {
        return match ($endpoint) {
            'services' => "Simulated {$action}: service remains isolated from the host.",
            'sandbox' => "$ php demo.php\nHello from the MicroSysAdmin demonstration.\nNo command was executed.",
            'git' => "Simulated Git {$action}. No repository changed.",
            'mysql' => "Simulated database {$action}. No SQL was executed.",
            default => "Simulated {$endpoint} {$action}. No host data changed.",
        };
    }
}
