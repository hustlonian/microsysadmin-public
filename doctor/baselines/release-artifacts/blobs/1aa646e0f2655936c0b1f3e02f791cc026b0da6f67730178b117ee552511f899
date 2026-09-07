<?php

declare(strict_types=1);

namespace SysAdmin;

final class SystemIntegrationManager
{
    /**
     * @return array<string, string>
     */
    public function render(string $outputRoot, string $serviceUser, string $serviceGroup): array
    {
        $serviceUser = $this->account($serviceUser, 'service user');
        $serviceGroup = $this->account($serviceGroup, 'service group');
        $outputRoot = rtrim($this->absolute($outputRoot, 'output root'), DIRECTORY_SEPARATOR);
        $directories = [
            $outputRoot . '/systemd',
            $outputRoot . '/cron',
            $outputRoot . '/logrotate',
        ];
        foreach ($directories as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException("Unable to create {$directory}.");
            }
        }

        $php = EnvironmentConfig::path('php_binary');
        $app = rtrim(APP_ROOT, DIRECTORY_SEPARATOR);
        $storage = EnvironmentConfig::path('storage_root', STORAGE_PATH);
        $private = EnvironmentConfig::path('private_root', '/var/lib/microsysadmin');
        $writable = array_values(array_unique(array_filter([
            $storage,
            $private,
            EnvironmentConfig::path('backup_root'),
            EnvironmentConfig::path('trash_root'),
            EnvironmentConfig::path('script_root'),
            EnvironmentConfig::path('temporary_workspace_root'),
            ...EnvironmentConfig::paths('document_roots'),
        ])));
        $readWritePaths = implode(' ', array_map([$this, 'systemdQuote'], $writable));

        $files = [
            'worker' => $outputRoot . '/systemd/microsysadmin-worker.service',
            'health_service' => $outputRoot . '/systemd/microsysadmin-health.service',
            'health_timer' => $outputRoot . '/systemd/microsysadmin-health.timer',
            'cron' => $outputRoot . '/cron/microsysadmin',
            'logrotate' => $outputRoot . '/logrotate/microsysadmin',
        ];

        $this->write($files['worker'], <<<UNIT
[Unit]
Description=MicroSysAdmin durable job worker
After=network.target mysql.service mariadb.service

[Service]
Type=simple
User={$serviceUser}
Group={$serviceGroup}
WorkingDirectory={$this->systemdPath($app)}
ExecStart={$this->systemdQuote($php)} {$this->systemdQuote($app . '/scripts/job-worker.php')}
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths={$readWritePaths}

[Install]
WantedBy=multi-user.target
UNIT);

        $this->write($files['health_service'], <<<UNIT
[Unit]
Description=MicroSysAdmin health check
After=mysql.service mariadb.service

[Service]
Type=oneshot
User={$serviceUser}
Group={$serviceGroup}
WorkingDirectory={$this->systemdPath($app)}
ExecStart={$this->systemdQuote($app . '/health-check.sh')}
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths={$readWritePaths}
UNIT);

        $this->write($files['health_timer'], <<<'UNIT'
[Unit]
Description=Run MicroSysAdmin health checks

[Timer]
OnBootSec=5m
OnUnitActiveSec=5m
Persistent=true

[Install]
WantedBy=timers.target
UNIT);

        $cronCommand = implode(' ', [
            escapeshellarg($php),
            escapeshellarg($app . '/cron/runner.php'),
            '>/dev/null 2>&1',
        ]);
        $this->write($files['cron'], "* * * * * {$serviceUser} {$cronCommand}\n");
        $this->write($files['logrotate'], sprintf(
            "\"%s\"/*.log {\n    daily\n    rotate 14\n    compress\n    delaycompress\n    missingok\n    notifempty\n    copytruncate\n    su %s %s\n}\n",
            str_replace('"', '\\"', $storage . '/logs'),
            $serviceUser,
            $serviceGroup
        ));

        return $files;
    }

    /**
     * Install previously rendered assets. The caller must explicitly request
     * this operation and run as root.
     *
     * @param array<string, string> $files
     */
    public function install(array $files): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new \RuntimeException('System integration installation requires root. Use preview mode otherwise.');
        }
        $targets = [
            'worker' => '/etc/systemd/system/microsysadmin-worker.service',
            'health_service' => '/etc/systemd/system/microsysadmin-health.service',
            'health_timer' => '/etc/systemd/system/microsysadmin-health.timer',
            'cron' => '/etc/cron.d/microsysadmin',
            'logrotate' => '/etc/logrotate.d/microsysadmin',
        ];
        foreach ($targets as $key => $target) {
            if (!isset($files[$key]) || !is_file($files[$key]) || !copy($files[$key], $target)) {
                throw new \RuntimeException("Unable to install {$target}.");
            }
            chmod($target, 0644);
        }

        $runner = new ProcessExecutor();
        if (!is_dir('/run/systemd/system')) {
            // Containers used by local Actions emulation may not boot systemd.
            // The cron and logrotate integrations above remain active, while
            // a real supported Ubuntu host always follows the enable path.
            return $targets;
        }
        $reload = $runner->run(['systemctl', 'daemon-reload'], 30);
        if (!$reload['ok']) {
            throw new \RuntimeException('systemctl daemon-reload failed: ' . $reload['error']);
        }
        $enable = $runner->run([
            'systemctl', 'enable', '--now',
            'microsysadmin-worker.service',
            'microsysadmin-health.timer',
        ], 60);
        if (!$enable['ok']) {
            throw new \RuntimeException('Unable to enable MicroSysAdmin services: ' . $enable['error']);
        }
        $active = $runner->run([
            'systemctl', 'is-active', '--quiet',
            'microsysadmin-worker.service',
            'microsysadmin-health.timer',
        ], 30);
        if (!$active['ok']) {
            throw new \RuntimeException('MicroSysAdmin worker or health timer did not become active after installation.');
        }
        return $targets;
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, rtrim($contents) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write {$path}.");
        }
        chmod($path, 0640);
    }

    private function account(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $value)) {
            throw new \InvalidArgumentException("The {$label} is invalid.");
        }
        return $value;
    }

    private function absolute(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || !str_starts_with($value, '/') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException("The {$label} must be an absolute path.");
        }
        return $value;
    }

    private function systemdQuote(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private function systemdPath(string $value): string
    {
        return preg_replace_callback(
            '/[^A-Za-z0-9_\/.\-]/',
            static fn (array $match): string => implode('', array_map(
                static fn (string $byte): string => sprintf('\\x%02x', ord($byte)),
                str_split($match[0])
            )),
            $value
        ) ?? $value;
    }
}
