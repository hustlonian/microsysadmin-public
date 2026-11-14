<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\AuditLogger;
use SysAdmin\Auth;
use SysAdmin\BackupService;
use SysAdmin\CronService;
use SysAdmin\FileVault;
use SysAdmin\GitService;
use SysAdmin\HostingService;
use SysAdmin\LaravelService;
use SysAdmin\LogService;
use SysAdmin\MediaService;
use SysAdmin\Models\Setting;
use SysAdmin\Models\User;
use SysAdmin\MysqlService;
use SysAdmin\SandboxService;
use SysAdmin\ScriptService;
use SysAdmin\SystemProbe;
use SysAdmin\View;
use SysAdmin\Doctor\DoctorApplication;

final class PageController
{
    public function show(string $slug): void
    {
        if ($slug === 'permissions') {
            $target = 'explorer';
            if (isset($_GET['path']) && (string) $_GET['path'] !== '') {
                $target .= '?path=' . rawurlencode((string) $_GET['path']);
            }
            redirect_to($target);
        }
        if ($slug === 'user-permissions') {
            redirect_to('linux-users');
        }

        $navigation = require APP_ROOT . '/config/navigation.php';
        $title = $this->titleFor($slug, $navigation);
        Auth::requirePermission($this->permissionForSlug($slug));
        $data = ['title' => $title, 'active' => $slug, 'navigation' => $navigation];

        if (\SysAdmin\DemoMode::enabled()) {
            View::render('pages/demo', array_merge($data, [
                'mode' => $slug,
                'demo' => \SysAdmin\DemoMode::data(),
            ]));
            return;
        }

        [$view, $extra] = match ($slug) {
            'doctor' => ['pages/doctor', ['doctor' => (new DoctorApplication())->dashboard(Auth::can('doctor.sensitive'), Auth::can('doctor.audit'))]],
            'server', 'health', 'storage', 'networking', 'apache', 'virtual-hosts', 'ssl', 'domains' => ['pages/system', $this->systemData($slug)],
            'sites' => ['pages/sites', [
                'hosting' => \SysAdmin\AgentManager::client()->request('websites.list', ['include_sizes' => isset($_GET['sizes']) && $_GET['sizes'] === '1']),
                'sizesLoaded' => isset($_GET['sizes']) && $_GET['sizes'] === '1',
            ]],
            'services' => ['pages/services', ['states' => \SysAdmin\AgentManager::client()->request('services.status'), 'services' => (new \SysAdmin\ServiceControlManager())->definitions()]],
            'processes' => ['pages/processes', ['processes' => \SysAdmin\AgentManager::client()->request('processes', ['limit' => 100])]],
            'explorer', 'uploads' => ['pages/files', ['mode' => $slug, 'listing' => (new FileVault())->list((string) ($_GET['path'] ?? ''))]],
            'search' => ['pages/search', ['query' => (string) ($_GET['q'] ?? ''), 'results' => (new \SysAdmin\SearchService())->global((string) ($_GET['q'] ?? ''))]],
            'backups', 'database-backups' => ['pages/backups', ['mode' => $slug, 'backups' => (new BackupService())->list(), 'databases' => (new MysqlService())->databases(), 'roots' => (new FileVault())->roots()]],
            'logs', 'log-analyzer', 'log-insights' => ['pages/logs', $this->logData($slug === 'log-insights' ? 'log-analyzer' : $slug)],
            'laravel', 'artisan-runner', 'composer', 'queues', 'storage-link' => ['pages/laravel', ['mode' => $slug, 'projects' => (new LaravelService())->projects(), 'commands' => require APP_ROOT . '/config/commands.php']],
            'repositories', 'commit', 'pull', 'push', 'deploy', 'git-actions' => ['pages/git', ['mode' => $slug, 'repositories' => (new GitService())->repositories()]],
            'mysql', 'tables', 'import', 'export' => ['pages/mysql', $this->mysqlData($slug)],
            'cron-jobs', 'scheduled-tasks' => ['pages/cron', ['mode' => $slug, 'systemJobs' => (new CronService())->systemJobs(), 'managed' => (new CronService())->managed(), 'scripts' => (new ScriptService())->scripts()]],
            'bash-scripts' => ['pages/scripts', ['scripts' => (new ScriptService())->scripts(), 'runs' => \SysAdmin\Models\ScriptRun::recent(25)]],
            'sandbox', 'code-runner' => ['pages/sandbox', (new SandboxService())->pageData()],
            'notifications' => ['pages/notifications', ['settings' => Setting::all(), 'smtp' => (new \SysAdmin\NotificationService())->configuration()]],
            'owner-setup' => ['pages/owner-setup', ['setup' => (new \SysAdmin\OwnerSetupService())->overview()]],
            'command-assistant', 'operations-assistant' => ['pages/assistant', ['commands' => $this->commandAssistant()]],
            'media', 'audio', 'video', 'conversions' => ['pages/media', ['mode' => $slug, 'tools' => (new MediaService())->tools(), 'roots' => (new FileVault())->roots()]],
            'analytics-cpu', 'analytics-memory', 'analytics-disk', 'analytics-traffic' => ['pages/analytics', ['mode' => $slug]],
            'users', 'ssh-keys', 'linux-users' => ['pages/users', ['mode' => $slug, 'users' => User::all(), 'linuxUsers' => $this->linuxUsers(), 'sshKeys' => $this->sshKeys(), 'audit' => AuditLogger::rows(50)]],
            'settings' => ['pages/settings', [
                'settings' => Setting::all(),
                'audit' => AuditLogger::rows(100),
                'securityProfile' => \SysAdmin\SecurityProfile::current(),
                'securityCapabilities' => \SysAdmin\SecurityProfile::capabilities(),
                'mfaRecoveryRemaining' => (new \SysAdmin\MfaRecoveryService())->remaining((int) Auth::id()),
            ]],
            'machine-info', 'audit', 'licensing', 'updates', 'jobs', 'api-access', 'webhooks', 'environment', 'hub-connection', 'security' => [
                'pages/administration',
                (new \SysAdmin\AdministrationService())->data($slug),
            ],
            default => ['pages/system', $this->systemData('server')],
        };

        View::render($view, array_merge($data, $extra));
    }

    private function titleFor(string $slug, array $navigation): string
    {
        foreach ($navigation as $items) {
            $title = $this->titleInItems($slug, $items);
            if ($title !== null) {
                return $title;
            }
        }
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function permissionForSlug(string $slug): string
    {
        return match ($slug) {
            'doctor' => 'doctor.view',
            'server', 'health', 'storage', 'networking', 'processes', 'analytics-cpu', 'analytics-memory', 'analytics-disk', 'analytics-traffic' => 'dashboard.view',
            'apache', 'virtual-hosts', 'ssl', 'domains', 'services' => 'service.view',
            'explorer', 'uploads', 'search', 'sites' => 'file.view',
            'logs', 'log-analyzer', 'log-insights' => 'log.view',
            'mysql', 'tables', 'import', 'export' => 'database.view',
            'backups', 'database-backups' => 'backup.create',
            'repositories', 'commit', 'pull', 'push', 'deploy', 'git-actions' => 'git.operations',
            'laravel', 'artisan-runner', 'composer', 'queues', 'storage-link', 'bash-scripts', 'command-assistant', 'operations-assistant' => 'command.execute',
            'sandbox', 'code-runner' => 'terminal.access',
            'cron-jobs', 'scheduled-tasks' => 'cron.manage',
            'media', 'audio', 'video', 'conversions' => 'file.view',
            'notifications', 'owner-setup', 'settings' => 'security.settings',
            'users', 'ssh-keys', 'linux-users' => 'user.manage',
            'machine-info', 'audit', 'licensing', 'updates', 'jobs', 'api-access', 'webhooks', 'environment', 'hub-connection', 'security' => 'security.settings',
            default => 'dashboard.view',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function titleInItems(string $slug, array $items): ?string
    {
        foreach ($items as $item) {
            if (($item['slug'] ?? null) === $slug) {
                return (string) $item['label'];
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $title = $this->titleInItems($slug, $item['children']);
                if ($title !== null) {
                    return $title;
                }
            }
        }

        return null;
    }

    private function systemData(string $mode): array
    {
        $probe = new SystemProbe();
        return [
            'mode' => $mode,
            'info' => $probe->serverInfo(),
            'checks' => $mode === 'health' ? $probe->healthChecks() : [],
        ];
    }

    private function logData(string $mode): array
    {
        $logs = new LogService();
        $files = $logs->files();
        $selected = (string) ($_GET['log'] ?? array_key_first($files));
        return [
            'mode' => $mode,
            'files' => $files,
            'selected' => $selected,
            'read' => $logs->read($selected, (string) ($_GET['q'] ?? ''), 400),
            'analysis' => $logs->analyze($selected),
        ];
    }

    private function mysqlData(string $mode): array
    {
        $service = new MysqlService();
        $databases = $service->databases();
        $databaseNames = array_column($databases, 'name');
        $selectedDatabase = (string) ($_GET['database'] ?? config_value('database.name', 'microsysadmin'));
        if (!in_array($selectedDatabase, $databaseNames, true)) {
            $selectedDatabase = (string) ($databaseNames[0] ?? '');
        }

        $tables = $selectedDatabase !== '' ? $service->tables($selectedDatabase) : [];
        $tableNames = array_column($tables, 'name');
        $selectedTable = (string) ($_GET['table'] ?? '');
        if ($selectedTable === '' && $tables) {
            $selectedTable = MysqlService::preferredTable($tables);
        }
        if (!in_array($selectedTable, $tableNames, true)) {
            $selectedTable = '';
        }

        $databaseRows = 0;
        $databaseBytes = 0;
        foreach ($tables as $table) {
            $databaseRows += (int) ($table['rows_count'] ?? 0);
            $databaseBytes += (int) ($table['bytes'] ?? 0);
        }

        return [
            'mode' => $mode,
            'databases' => $databases,
            'tables' => $tables,
            'databaseSummary' => [
                'table_count' => count($tables),
                'estimated_rows' => $databaseRows,
                'bytes' => $databaseBytes,
                'size_human' => human_bytes($databaseBytes),
            ],
            'selectedDatabase' => $selectedDatabase,
            'selectedTable' => $selectedTable,
            'columns' => $selectedTable !== '' ? $service->columns($selectedDatabase, $selectedTable) : [],
            'primaryKey' => $selectedTable !== '' ? $service->primaryKey($selectedDatabase, $selectedTable) : [],
            'rows' => $selectedTable !== '' ? $service->rows($selectedDatabase, $selectedTable) : [],
            'rowCount' => $selectedTable !== '' ? $service->rowCount($selectedDatabase, $selectedTable) : 0,
        ];
    }

    private function linuxUsers(): array
    {
        $rows = [];
        if (!is_readable('/etc/passwd')) {
            return $rows;
        }
        foreach (file('/etc/passwd', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 7) {
                $rows[] = [
                    'name' => $parts[0],
                    'uid' => $parts[2],
                    'gid' => $parts[3],
                    'home' => $parts[5],
                    'shell' => $parts[6],
                ];
            }
        }
        return $rows;
    }

    private function sshKeys(): array
    {
        $keys = [];
        foreach (['/root/.ssh', getenv('HOME') . '/.ssh'] as $dir) {
            if (!is_dir((string) $dir) || !is_readable((string) $dir)) {
                continue;
            }
            foreach (glob($dir . '/*.pub') ?: [] as $file) {
                $keys[] = ['file' => $file, 'fingerprint' => substr(hash('sha256', (string) file_get_contents($file)), 0, 32)];
            }
        }
        return $keys;
    }

    private function commandAssistant(): array
    {
        return [
            ['intent' => 'Restart Apache', 'command' => \SysAdmin\EnvironmentConfig::path('xampp_root') . '/lampp restartapache', 'page' => 'services'],
            ['intent' => 'Restart MySQL', 'command' => \SysAdmin\EnvironmentConfig::path('xampp_root') . '/lampp restartmysql', 'page' => 'services'],
            ['intent' => 'Clear Laravel cache', 'command' => 'php artisan cache:clear', 'page' => 'artisan-runner'],
            ['intent' => 'Inspect open ports', 'command' => 'ss -ltnp', 'page' => 'networking'],
            ['intent' => 'Create database backup', 'command' => 'mysqldump through Backup Center', 'page' => 'database-backups'],
            ['intent' => 'Run approved script', 'command' => 'bash storage/scripts/{script}.sh', 'page' => 'bash-scripts'],
        ];
    }
}
