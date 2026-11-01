<?php

declare(strict_types=1);

namespace SysAdmin\Controllers;

use SysAdmin\Auth;
use SysAdmin\BackupService;
use SysAdmin\CommandRunner;
use SysAdmin\CronService;
use SysAdmin\Csrf;
use SysAdmin\Database;
use SysAdmin\FileVault;
use SysAdmin\GitService;
use SysAdmin\HostingService;
use SysAdmin\LaravelService;
use SysAdmin\LogService;
use SysAdmin\MediaService;
use SysAdmin\Models\Setting;
use SysAdmin\Models\User;
use SysAdmin\MysqlService;
use SysAdmin\NotificationService;
use SysAdmin\RateLimiter;
use SysAdmin\SandboxService;
use SysAdmin\ScriptService;
use SysAdmin\SitePageService;
use SysAdmin\SystemProbe;

final class ApiController
{
    public function handle(string $endpoint): void
    {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            Csrf::verify();
        }

        try {
            $this->rateLimit($endpoint);
            if (\SysAdmin\DemoMode::enabled()) {
                $payload = \SysAdmin\DemoMode::api($endpoint);
                json_response($payload, 200);
            }
            $payload = match ($endpoint) {
                'metrics' => $this->metrics(),
                'processes' => $this->processes(),
                'services' => $this->services(),
                'file' => $this->files(),
                'backups' => $this->backups(),
                'cron' => $this->cron(),
                'logs' => $this->logs(),
                'hosting' => $this->hosting(),
                'mysql' => $this->mysql(),
                'git' => $this->git(),
                'artisan' => $this->artisan(),
                'scripts' => $this->scripts(),
                'sandbox' => $this->sandbox(),
                'search' => $this->search(),
                'settings' => $this->settings(),
                'media' => $this->media(),
                'notifications' => $this->notifications(),
                'users' => $this->users(),
                'analytics' => $this->analytics(),
                'administration' => $this->administration(),
                'doctor' => $this->doctor(),
                default => ['ok' => false, 'error' => 'Unknown API endpoint.'],
            };
            json_response($payload, (int) ($payload['status'] ?? (($payload['ok'] ?? false) ? 200 : 400)));
        } catch (\SysAdmin\RateLimitException $exception) {
            json_response(['ok' => false, 'error' => $exception->getMessage()], 429);
        } catch (\SysAdmin\HttpException $exception) {
            json_response(['ok' => false, 'error' => $exception->getMessage()], $exception->statusCode());
        } catch (\Throwable $exception) {
            \SysAdmin\AuditLogger::error('api.error', ['endpoint' => $endpoint, 'error' => $exception->getMessage()]);
            json_response(['ok' => false, 'error' => \SysAdmin\Security::productionSafeMessage($exception, 'Request failed.')], app_debug() ? 400 : 500);
        }
    }

    private function services(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission('service.view');
            return ['ok' => true, 'data' => \SysAdmin\AgentManager::client()->request('services.status')];
        }
        $service = (string) ($_POST['service'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        match ($action) {
            'status' => Auth::requirePermission('service.view'),
            'restart', 'reload' => Auth::requirePermission('service.restart'),
            'start', 'stop' => Auth::requirePermission('service.start_stop'),
            default => throw new \InvalidArgumentException('Unknown service action.'),
        };
        $result = \SysAdmin\AgentManager::client()->request('services.action', ['service' => $service, 'action' => $action]);
        $result['states'] = \SysAdmin\AgentManager::client()->request('services.status');
        return ['ok' => true, 'data' => $result];
    }

    private function metrics(): array
    {
        Auth::requirePermission('dashboard.view');
        return ['ok' => true, 'data' => \SysAdmin\AgentManager::client()->request('metrics')];
    }

    private function processes(): array
    {
        Auth::requirePermission('dashboard.view');
        return ['ok' => true, 'data' => \SysAdmin\AgentManager::client()->request('processes', ['limit' => 150])];
    }

    private function search(): array
    {
        Auth::requirePermission('read');
        return ['ok' => true, 'data' => (new \SysAdmin\SearchService())->global((string) ($_GET['q'] ?? ''))];
    }

    private function files(): array
    {
        $vault = new FileVault();
        $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');
        if ($action === 'download') {
            Auth::requirePermission('file.view');
            $vault->sendDownload((string) ($_GET['path'] ?? ''), !empty($_GET['inline']));
        }
        if ($action === 'list') {
            Auth::requirePermission('file.view');
            return ['ok' => true, 'data' => $vault->list((string) ($_GET['path'] ?? ''))];
        }
        if ($action === 'preview') {
            Auth::requirePermission('file.view');
            return ['ok' => true, 'data' => $vault->preview((string) ($_GET['path'] ?? ''))];
        }
        if ($action === 'search') {
            Auth::requirePermission('file.view');
            return ['ok' => true, 'data' => $vault->search((string) ($_GET['root'] ?? ''), (string) ($_GET['q'] ?? ''))];
        }
        $this->requireFilePermission($action);
        return match ($action) {
            'read' => ['ok' => true, 'data' => $vault->read((string) ($_GET['path'] ?? $_POST['path'] ?? ''))],
            'upload' => ['ok' => true, 'data' => $vault->upload((string) ($_POST['path'] ?? ''), $_FILES['file'] ?? [])],
            'create-folder' => ['ok' => true, 'data' => ['path' => $vault->createFolder((string) $_POST['path'], (string) $_POST['name'])]],
            'create-file' => ['ok' => true, 'data' => ['path' => $vault->createFile((string) $_POST['path'], (string) $_POST['name'], (string) ($_POST['content'] ?? ''))]],
            'save-file' => ['ok' => true, 'data' => ['path' => $vault->saveFile((string) $_POST['path'], (string) ($_POST['content'] ?? ''))]],
            'rename' => ['ok' => true, 'data' => ['path' => $vault->rename((string) $_POST['path'], (string) $_POST['name'])]],
            'copy' => ['ok' => true, 'data' => ['path' => $vault->copy((string) $_POST['path'], (string) $_POST['target'])]],
            'move' => ['ok' => true, 'data' => ['path' => $vault->move((string) $_POST['path'], (string) $_POST['target'])]],
            'delete' => ['ok' => true, 'data' => ['trash' => $vault->delete((string) $_POST['path'])]],
            'chmod' => ['ok' => true, 'data' => $vault->changePermissions((string) $_POST['path'], (string) $_POST['mode'])],
            'zip' => ['ok' => true, 'data' => ['archive' => $vault->zip((string) $_POST['path'])]],
            'unzip' => ['ok' => true, 'data' => ['target' => $vault->unzip((string) $_POST['path'], (string) $_POST['target'])]],
            default => ['ok' => false, 'error' => 'Unknown file action.'],
        };
    }

    private function backups(): array
    {
        $service = new BackupService();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission('backup.create');
            return ['ok' => true, 'data' => $service->list()];
        }
        $action = (string) ($_POST['action'] ?? '');
        match ($action) {
            'database', 'folder' => Auth::requirePermission('backup.create'),
            'restore' => Auth::requirePermission('backup.restore'),
            default => null,
        };
        return match ($action) {
            'database' => ['ok' => true, 'data' => $this->queueOrRun(
                'backup.database',
                ['database' => (string) ($_POST['database'] ?? '')],
                fn () => $service->database((string) ($_POST['database'] ?? ''))
            )],
            'folder' => ['ok' => true, 'data' => $this->queueOrRun(
                'backup.folder',
                ['path' => (string) ($_POST['path'] ?? '')],
                fn () => $service->folder((string) ($_POST['path'] ?? ''))
            )],
            'restore' => ['ok' => true, 'data' => $this->queueOrRun(
                'backup.restore',
                ['archive' => (string) ($_POST['archive'] ?? ''), 'target' => (string) ($_POST['target'] ?? '')],
                fn () => $service->restore((string) ($_POST['archive'] ?? ''), (string) ($_POST['target'] ?? ''))
            )],
            default => ['ok' => false, 'error' => 'Unknown backup action.'],
        };
    }

    private function cron(): array
    {
        $service = new CronService();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission('cron.manage');
            return ['ok' => true, 'data' => ['system' => $service->systemJobs(), 'managed' => $service->managed()]];
        }
        Auth::requirePermission('cron.manage');
        $action = (string) ($_POST['action'] ?? '');
        return match ($action) {
            'create' => ['ok' => true, 'data' => ['id' => $service->create((string) $_POST['name'], (string) $_POST['schedule'], (string) $_POST['script'])]],
            'enable' => $this->cronToggle($service, true),
            'disable' => $this->cronToggle($service, false),
            'delete' => $this->cronDelete($service),
            'run' => ['ok' => true, 'data' => (new ScriptService())->run((string) $_POST['script'])],
            default => ['ok' => false, 'error' => 'Unknown cron action.'],
        };
    }

    private function logs(): array
    {
        $service = new LogService();
        Auth::requirePermission('log.view');
        $action = (string) ($_GET['action'] ?? 'read');
        if ($action === 'analyze') {
            return ['ok' => true, 'data' => $service->analyze((string) ($_GET['log'] ?? ''))];
        }
        return ['ok' => true, 'data' => $service->read((string) ($_GET['log'] ?? ''), (string) ($_GET['q'] ?? ''), 400)];
    }

    private function hosting(): array
    {
        $service = new HostingService();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission('file.view');
            $action = (string) ($_GET['action'] ?? '');
            if ($action === 'pages') {
                return ['ok' => true, 'data' => (new SitePageService())->pages((string) ($_GET['site'] ?? ''))];
            }
            if ($action === 'read-page') {
                return ['ok' => true, 'data' => (new SitePageService())->readBlade(
                    (string) ($_GET['site'] ?? ''),
                    (string) ($_GET['view'] ?? '')
                )];
            }

            return ['ok' => true, 'data' => $service->overview()];
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update-page') {
            $this->requireAnyPermission(['hosting.write', 'file.edit']);
        } else {
            Auth::requirePermission('hosting.write');
        }

        return match ($action) {
            'create' => ['ok' => true, 'data' => $this->queueOrRun(
                'website.provision',
                ['name' => (string) ($_POST['name'] ?? ''), 'root' => (string) ($_POST['root'] ?? ''), 'domain' => (string) ($_POST['domain'] ?? '')],
                fn () => $service->createSite(
                    (string) ($_POST['name'] ?? ''),
                    (string) ($_POST['root'] ?? ''),
                    (string) ($_POST['domain'] ?? '')
                ),
                1200
            )],
            'update' => ['ok' => true, 'data' => $service->updateSite(
                (string) ($_POST['document_root'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['domain'] ?? ''),
                (string) ($_POST['status'] ?? 'active'),
                !empty($_POST['rename_folder'])
            )],
            'delete' => ['ok' => true, 'data' => $service->deleteSite(
                (string) ($_POST['document_root'] ?? ''),
                !empty($_POST['cleanup_database']),
                !empty($_POST['cleanup_database_user'])
            )],
            'create-page' => ['ok' => true, 'data' => (new SitePageService())->createPage(
                (string) ($_POST['site'] ?? ''),
                (string) ($_POST['uri'] ?? ''),
                (string) ($_POST['view'] ?? ''),
                (string) ($_POST['content'] ?? '')
            )],
            'update-page' => ['ok' => true, 'data' => (new SitePageService())->saveBlade(
                (string) ($_POST['site'] ?? ''),
                (string) ($_POST['view'] ?? ''),
                (string) ($_POST['content'] ?? '')
            )],
            'delete-page' => ['ok' => true, 'data' => (new SitePageService())->deletePage(
                (string) ($_POST['site'] ?? ''),
                (string) ($_POST['uri'] ?? ''),
                (string) ($_POST['view'] ?? ''),
                !empty($_POST['delete_blade'])
            )],
            default => ['ok' => false, 'error' => 'Unknown hosting action.'],
        };
    }

    private function mysql(): array
    {
        $service = new MysqlService();
        $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'databases');
        match ($action) {
            'databases', 'tables', 'indexes', 'rows' => Auth::requirePermission('database.view'),
            'create-row', 'update-row', 'import' => Auth::requirePermission('database.modify'),
            'delete-row' => Auth::requirePermission('database.delete'),
            default => null,
        };

        return match ($action) {
            'databases' => ['ok' => true, 'data' => $service->databases()],
            'tables' => ['ok' => true, 'data' => $service->tables((string) ($_GET['database'] ?? $_POST['database'] ?? ''))],
            'indexes' => ['ok' => true, 'data' => $service->indexes((string) ($_GET['database'] ?? ''), (string) ($_GET['table'] ?? ''))],
            'rows' => ['ok' => true, 'data' => $service->rows((string) ($_GET['database'] ?? ''), (string) ($_GET['table'] ?? ''))],
            'create-row' => ['ok' => true, 'data' => $service->createRow((string) ($_POST['database'] ?? ''), (string) ($_POST['table'] ?? ''), (array) ($_POST['values'] ?? []), (array) ($_POST['nulls'] ?? []))],
            'update-row' => ['ok' => true, 'data' => $service->updateRow((string) ($_POST['database'] ?? ''), (string) ($_POST['table'] ?? ''), $this->mysqlRowKey(), (array) ($_POST['values'] ?? []), (array) ($_POST['nulls'] ?? []))],
            'delete-row' => ['ok' => true, 'data' => $service->deleteRow((string) ($_POST['database'] ?? ''), (string) ($_POST['table'] ?? ''), $this->mysqlRowKey())],
            'import' => ['ok' => true, 'data' => $this->mysqlImport($service)],
            default => ['ok' => false, 'error' => 'Unknown MySQL action.'],
        };
    }

    private function mysqlRowKey(): array
    {
        $key = json_decode((string) ($_POST['key'] ?? ''), true);
        if (!is_array($key)) {
            throw new \InvalidArgumentException('Invalid row key.');
        }
        return $key;
    }

    private function git(): array
    {
        $service = new GitService();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission('git.operations');
            $path = (string) ($_GET['path'] ?? '');
            return ['ok' => true, 'data' => $path ? $service->detail($path) : $service->repositories()];
        }
        Auth::requirePermission('git.operations');
        return ['ok' => true, 'data' => $this->queueOrRun(
            'git.action',
            ['path' => (string) $_POST['path'], 'action' => (string) $_POST['action'], 'message' => (string) ($_POST['message'] ?? '')],
            fn () => $service->action((string) $_POST['path'], (string) $_POST['action'], (string) ($_POST['message'] ?? ''))
        )];
    }

    private function artisan(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new \SysAdmin\HttpException(405, 'Artisan commands must be submitted with POST.');
        }
        Auth::requirePermission('command.execute');
        $service = new LaravelService();
        $type = (string) ($_POST['type'] ?? 'artisan');
        if (!in_array($type, ['artisan', 'composer'], true)) {
            throw new \InvalidArgumentException('Unknown Laravel command type.');
        }

        $project = (string) ($_POST['project'] ?? '');
        $command = (string) ($_POST['command'] ?? '');
        if ((string) ($_POST['scope'] ?? '') === 'site') {
            $project = (new HostingService())->resolveLaravelSitePath($project);
        }

        $allowAny = $type === 'artisan' && !empty($_POST['allow_any']);
        if ($allowAny) {
            Auth::requirePermission('terminal.access');
            if (empty($_POST['confirmed'])) {
                throw new \SysAdmin\HttpException(409, 'Confirm the free-form Artisan command before running it.');
            }
        }

        try {
            $result = $type === 'composer'
                ? $this->queueOrRun(
                    'composer.run',
                    ['project' => $project, 'command' => $command],
                    fn () => $service->runComposer($project, $command)
                )
                : $this->queueOrRun(
                    'artisan.run',
                    ['project' => $project, 'command' => $command, 'allow_any' => $allowAny],
                    fn () => $service->runArtisan($project, $command, $allowAny)
                );
        } catch (\InvalidArgumentException $exception) {
            throw new \SysAdmin\HttpException(422, $exception->getMessage(), $exception);
        }
        return ['ok' => true, 'data' => $result];
    }

    private function scripts(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission('command.execute');
            return ['ok' => true, 'data' => (new ScriptService())->scripts()];
        }
        Auth::requirePermission('command.execute');
        $script = (string) ($_POST['script'] ?? '');
        return ['ok' => true, 'data' => $this->queueOrRun(
            'script.run',
            ['script' => $script],
            fn () => (new ScriptService())->run($script)
        )];
    }

    private function sandbox(): array
    {
        $service = new SandboxService();
        $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === '') {
            Auth::requirePermission('command.execute');
            return ['ok' => true, 'data' => $service->pageData()];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'terminal-output') {
            Auth::requirePermission('terminal.access');
            return ['ok' => true, 'data' => $service->terminalOutput(
                (string) ($_GET['session'] ?? ''),
                (int) ($_GET['offset'] ?? 0)
            )];
        }

        $confirmed = (string) ($_POST['confirm_real'] ?? '') === '1';
        if ($action === 'terminal-start') {
            Auth::requirePermission('terminal.access');
            return ['ok' => true, 'data' => $service->startTerminal(
                (string) ($_POST['language'] ?? 'bash'),
                (string) ($_POST['code'] ?? ''),
                $confirmed,
                (int) ($_POST['cols'] ?? 100),
                (int) ($_POST['rows'] ?? 30)
            )];
        }

        if ($action === 'terminal-input') {
            Auth::requirePermission('terminal.access');
            return ['ok' => true, 'data' => $service->terminalInput(
                (string) ($_POST['session'] ?? ''),
                (string) ($_POST['input'] ?? '')
            )];
        }

        if ($action === 'terminal-stop') {
            Auth::requirePermission('terminal.access');
            return ['ok' => true, 'data' => $service->stopTerminal((string) ($_POST['session'] ?? ''))];
        }

        if ($action === 'save-web') {
            Auth::requirePermission('command.execute');
            return ['ok' => true, 'data' => $service->saveWeb(
                (string) ($_POST['html'] ?? ''),
                (string) ($_POST['css'] ?? ''),
                (string) ($_POST['javascript'] ?? ''),
                $confirmed
            )];
        }

        Auth::requirePermission('command.execute');
        foreach (['language', 'code', 'input', 'timeout', 'confirm_real'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) { throw new \SysAdmin\HttpException(422, 'Runner fields must be scalar text.'); }
        }
        if (array_diff(array_keys($_POST), ['_csrf','action','language','code','input','timeout','confirm_real']) !== []) {
            throw new \SysAdmin\HttpException(422, 'Unexpected runner field; host directories and interpreter commands cannot be supplied.');
        }
        if (!in_array(strtolower(trim((string)($_POST['language'] ?? ''))), ['bash','php','python','javascript'], true)) {
            throw new \SysAdmin\HttpException(422, 'Choose Bash, PHP, Python or JavaScript.');
        }
        if (isset($_POST['timeout']) && (!ctype_digit($_POST['timeout']) || (int)$_POST['timeout'] < 1 || (int)$_POST['timeout'] > 30)) {
            throw new \SysAdmin\HttpException(422, 'Timeout must be between 1 and 30 seconds.');
        }
        return ['ok' => true, 'data' => $service->run(
            (string) ($_POST['language'] ?? ''),
            (string) ($_POST['code'] ?? ''),
            (string) ($_POST['input'] ?? ''),
            (int) ($_POST['timeout'] ?? 10),
            $confirmed
        )];
    }

    private function settings(): array
    {
        Auth::requirePermission('security.settings');
        if (isset($_POST['security_profile'])) {
            \SysAdmin\SecurityProfile::set(
                (string) $_POST['security_profile'],
                (string) ($_POST['risk_acknowledged'] ?? '') === '1'
            );
        }
        if (isset($_POST['timezone']) && !in_array((string) $_POST['timezone'], timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException('Timezone is invalid.');
        }
        if (isset($_POST['theme']) && !in_array((string) $_POST['theme'], ['default', 'blue', 'green'], true)) {
            throw new \InvalidArgumentException('Theme is invalid.');
        }
        foreach (['dark_mode', 'theme', 'timezone', 'refresh_interval', 'email_notifications'] as $key) {
            if (isset($_POST[$key])) {
                Setting::set($key, (string) $_POST[$key]);
            }
        }
        if (isset($_POST['timezone'])) {
            date_default_timezone_set((string) $_POST['timezone']);
        }
        return ['ok' => true, 'data' => Setting::all()];
    }

    private function media(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission('file.view');
            return ['ok' => true, 'data' => (new MediaService())->tools()];
        }
        Auth::requirePermission('media.convert');
        return ['ok' => true, 'data' => $this->queueOrRun(
            'media.convert',
            ['path' => (string) $_POST['path'], 'format' => (string) $_POST['format']],
            fn () => (new MediaService())->convert((string) $_POST['path'], (string) $_POST['format'])
        )];
    }

    private function notifications(): array
    {
        Auth::requirePermission('security.settings');
        $service = new NotificationService();
        $action = (string) ($_POST['action'] ?? 'test');
        return match ($action) {
            'save' => ['ok' => true, 'data' => $service->saveConfiguration($_POST)],
            'test' => ['ok' => true, 'data' => $service->sendTest((string) ($_POST['email'] ?? ''))],
            default => ['ok' => false, 'error' => 'Unknown notification action.'],
        };
    }

    private function users(): array
    {
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['mfa-setup', 'mfa-enable', 'mfa-disable', 'mfa-recovery-regenerate'], true)) {
            return $this->mfa($action);
        }
        Auth::requirePermission('user.manage');
        if ($action === 'create') {
            return ['ok' => true, 'data' => ['id' => User::create((string) $_POST['username'], (string) $_POST['email'], (string) $_POST['password'], (string) $_POST['role'])]];
        }
        if ($action === 'password') {
            User::updatePassword((int) $_POST['id'], (string) $_POST['password'], false);
            return ['ok' => true, 'data' => true];
        }
        if ($action === 'role') {
            User::setRole((int) $_POST['id'], (string) $_POST['role']);
            return ['ok' => true, 'data' => true];
        }
        if ($action === 'disable' || $action === 'enable') {
            User::setDisabled((int) $_POST['id'], $action === 'disable');
            return ['ok' => true, 'data' => true];
        }
        if ($action === 'logout-all') {
            \SysAdmin\Auth::logoutAllForUser((int) $_POST['id']);
            return ['ok' => true, 'data' => true];
        }
        return ['ok' => false, 'error' => 'Unknown user action.'];
    }

    private function analytics(): array
    {
        Auth::requirePermission('dashboard.view');
        $stmt = Database::pdo()->query('SELECT cpu_percent, ram_percent, disk_percent, network_rx, network_tx, created_at FROM metric_samples ORDER BY id DESC LIMIT 120');
        $rows = array_reverse($stmt->fetchAll());
        return ['ok' => true, 'data' => $rows];
    }

    private function administration(): array
    {
        Auth::requirePermission('security.settings');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return ['ok' => true, 'data' => (new \SysAdmin\AdministrationService())->data((string) ($_GET['mode'] ?? 'machine-info'))];
        }

        $action = (string) ($_POST['action'] ?? '');
        return match ($action) {
            'machine-name' => $this->adminMachineName(),
            'environment-save' => ['ok' => true, 'data' => \SysAdmin\EnvironmentConfig::saveOverrides($_POST)],
            'security-profile' => $this->adminSecurityProfile(),
            'agent-rotate' => ['ok' => true, 'data' => (new \SysAdmin\AgentCredentialService())->rotate()],
            'hub-enroll' => $this->adminHubEnroll(),
            'hub-sharing' => ['ok' => true, 'data' => \SysAdmin\HubSharingPolicy::save(
                (array) ($_POST['share'] ?? [])
            )],
            'hub-test' => ['ok' => true, 'data' => \SysAdmin\HubManager::client()->test()],
            'hub-heartbeat' => ['ok' => true, 'data' => \SysAdmin\HubManager::client()->heartbeat()],
            'hub-rotate' => ['ok' => true, 'data' => \SysAdmin\HubManager::client()->rotateCredential()],
            'hub-disconnect' => $this->adminHubDisconnect(),
            'update-check' => ['ok' => true, 'data' => (new \SysAdmin\UpdateManager())->check()],
            'job-cancel' => $this->adminJobCancel(),
            'api-token-create' => ['ok' => true, 'data' => (new \SysAdmin\ApiTokenService())->create(
                (string) ($_POST['name'] ?? ''),
                (array) ($_POST['scopes'] ?? []),
                (string) ($_POST['expires_at'] ?? '') !== '' ? (string) $_POST['expires_at'] : null,
                (int) ($_POST['rate_limit'] ?? 60)
            )],
            'api-token-revoke' => $this->adminApiTokenRevoke(),
            'webhook-create' => ['ok' => true, 'data' => (new \SysAdmin\WebhookService())->create(
                (string) ($_POST['url'] ?? ''),
                (array) ($_POST['event_types'] ?? [])
            )],
            'webhook-enable' => $this->adminWebhookToggle(true),
            'webhook-disable' => $this->adminWebhookToggle(false),
            'webhook-delete' => $this->adminWebhookDelete(),
            default => ['ok' => false, 'error' => 'Unknown administration action.'],
        };
    }

    private function doctor(): array
    {
        $doctor = new \SysAdmin\Doctor\DoctorApplication();
        $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'dashboard');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            Auth::requirePermission(in_array($action, ['discover', 'execution'], true) ? 'doctor.sensitive' : 'doctor.view');
            if ($action === 'download-report') {
                Auth::requirePermission('doctor.sensitive');
                $this->doctorDownloadReport($doctor);
            }
            return match ($action) {
                'dashboard' => ['ok' => true, 'data' => $doctor->dashboard(Auth::can('doctor.sensitive'), Auth::can('doctor.audit'))],
                'discover' => ['ok' => true, 'data' => $doctor->discover(isset($_GET['project_path']) ? (string) $_GET['project_path'] : null)],
                'inventory' => ['ok' => true, 'data' => $doctor->inventory(isset($_GET['module']) && $_GET['module'] !== '' ? (string) $_GET['module'] : null)],
                'repair' => ['ok' => true, 'data' => $doctor->describeRepair((string) ($_GET['id'] ?? ''))],
                'execution' => ['ok' => true, 'data' => $doctor->execution((string) ($_GET['execution_id'] ?? ''), Auth::can('doctor.audit'))],
                default => ['ok' => false, 'error' => 'Unknown Doctor read action.'],
            };
        }

        $projectPath = trim((string) ($_POST['project_path'] ?? ''));
        $module = trim((string) ($_POST['module'] ?? ''));
        $repairIds = array_values(array_filter(array_map('strval', (array) ($_POST['repair_ids'] ?? []))));
        $checkIds = array_values(array_filter(array_map('strval', (array) ($_POST['check_ids'] ?? []))));
        $common = [
            'project_path' => $projectPath !== '' ? $projectPath : null,
            'modules' => $module !== '' ? [$module] : [],
            'repair_ids' => $repairIds,
            'check_ids' => $checkIds,
            'user_id' => Auth::id(),
            'report_formats' => ['json', 'text', 'html', 'pdf-html'],
        ];
        return match ($action) {
            'scan' => $this->doctorRun($doctor, [...$common, 'mode' => 'scan'], 'doctor.scan'),
            'diagnose' => $this->doctorRun($doctor, [...$common, 'mode' => 'diagnose'], 'doctor.scan'),
            'plan' => $this->doctorRun($doctor, [...$common, 'mode' => 'plan', 'approval_policy' => (string) ($_POST['approval_policy'] ?? 'manual')], 'doctor.scan'),
            'repair-safe' => $this->doctorRun($doctor, [...$common, 'mode' => 'repair', 'approval_policy' => 'safe'], 'doctor.execute'),
            'fix-all' => $this->doctorFixAll($doctor, $common),
            'fix' => $this->doctorFix($doctor, $common),
            'repair-approved' => $this->doctorApprovedRepair($doctor, $common),
            'approve' => $this->doctorApprove($doctor),
            'rollback' => $this->doctorRollback($doctor, $common),
            default => ['ok' => false, 'error' => 'Unknown Doctor action.'],
        };
    }

    private function doctorRun(\SysAdmin\Doctor\DoctorApplication $doctor, array $options, string $permission): array
    {
        Auth::requirePermission($permission);
        return ['ok' => true, 'data' => $doctor->run($options)];
    }

    private function doctorApprove(\SysAdmin\Doctor\DoctorApplication $doctor): array
    {
        Auth::requirePermission('doctor.approve');
        $doctor->approve(
            (string) ($_POST['execution_id'] ?? ''),
            strtoupper((string) ($_POST['repair_id'] ?? '')),
            strtolower((string) ($_POST['plan_hash'] ?? '')),
            (int) Auth::id(),
        );
        return ['ok' => true, 'data' => ['approved' => true]];
    }

    private function doctorFix(\SysAdmin\Doctor\DoctorApplication $doctor, array $common): array
    {
        Auth::requirePermission('doctor.execute');
        Auth::requirePermission('doctor.approve');
        return ['ok' => true, 'data' => $doctor->fixFromExecution(
            (string) ($_POST['execution_id'] ?? ''),
            strtoupper((string) ($_POST['repair_id'] ?? '')),
            strtolower((string) ($_POST['plan_hash'] ?? '')),
            $common,
        )];
    }

    private function doctorFixAll(\SysAdmin\Doctor\DoctorApplication $doctor, array $common): array
    {
        Auth::requirePermission('doctor.execute');
        Auth::requirePermission('doctor.approve');
        return ['ok' => true, 'data' => $doctor->fixEverything($common)];
    }

    private function doctorApprovedRepair(\SysAdmin\Doctor\DoctorApplication $doctor, array $common): array
    {
        Auth::requirePermission('doctor.execute');
        $approvalExecution = (string) ($_POST['approval_execution_id'] ?? '');
        if ($approvalExecution === '') {
            throw new \InvalidArgumentException('A preview execution is required before approved repairs can run.');
        }
        return ['ok' => true, 'data' => $doctor->run([
            ...$common,
            'mode' => 'repair',
            'approval_policy' => 'manual',
            'approval_execution_id' => $approvalExecution,
        ])];
    }

    private function doctorRollback(\SysAdmin\Doctor\DoctorApplication $doctor, array $common): array
    {
        Auth::requirePermission('doctor.rollback');
        return ['ok' => true, 'data' => $doctor->rollbackExecution(
            (string) ($_POST['execution_id'] ?? ''),
            (array) ($common['repair_ids'] ?? []),
            ['user_id' => Auth::id(), 'report_formats' => ['json', 'text', 'html', 'pdf-html']],
        )];
    }

    private function doctorDownloadReport(\SysAdmin\Doctor\DoctorApplication $doctor): never
    {
        $report = $doctor->reportFile((string) ($_GET['execution_id'] ?? ''), (string) ($_GET['format'] ?? ''));
        $path = (string) $report['report_path'];
        $mime = str_ends_with($path, '.json') ? 'application/json' : (str_ends_with($path, '.html') ? 'text/html' : 'text/plain');
        header('Content-Type: ' . $mime . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="doctor-' . rawurlencode((string) $report['execution_id']) . '-' . rawurlencode((string) $report['format']) . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function adminMachineName(): array
    {
        (new \SysAdmin\MachineIdentity())->setDisplayName((string) ($_POST['display_name'] ?? ''));
        return ['ok' => true, 'data' => (new \SysAdmin\MachineIdentity())->get()];
    }

    private function adminSecurityProfile(): array
    {
        \SysAdmin\SecurityProfile::set(
            (string) ($_POST['profile'] ?? ''),
            (string) ($_POST['risk_acknowledged'] ?? '') === '1'
        );
        return ['ok' => true, 'data' => \SysAdmin\SecurityProfile::capabilities()];
    }

    private function adminHubDisconnect(): array
    {
        \SysAdmin\HubManager::client()->disconnect();
        return ['ok' => true, 'data' => true];
    }

    private function adminHubEnroll(): array
    {
        \SysAdmin\HubSharingPolicy::save((array) ($_POST['share'] ?? []));
        return ['ok' => true, 'data' => (new \SysAdmin\HttpHubClient())->enroll(
            (string) ($_POST['url'] ?? ''),
            (string) ($_POST['organization_id'] ?? ''),
            (string) ($_POST['token'] ?? '')
        )];
    }

    private function adminJobCancel(): array
    {
        (new \SysAdmin\JobQueue())->cancel((string) ($_POST['uuid'] ?? ''));
        return ['ok' => true, 'data' => true];
    }

    private function adminApiTokenRevoke(): array
    {
        (new \SysAdmin\ApiTokenService())->revoke((int) ($_POST['id'] ?? 0));
        return ['ok' => true, 'data' => true];
    }

    private function adminWebhookToggle(bool $enabled): array
    {
        (new \SysAdmin\WebhookService())->setEnabled((int) ($_POST['id'] ?? 0), $enabled);
        return ['ok' => true, 'data' => true];
    }

    private function adminWebhookDelete(): array
    {
        (new \SysAdmin\WebhookService())->delete((int) ($_POST['id'] ?? 0));
        return ['ok' => true, 'data' => true];
    }

    private function cronToggle(CronService $service, bool $enabled): array
    {
        $service->setEnabled((int) $_POST['id'], $enabled);
        return ['ok' => true, 'data' => true];
    }

    private function cronDelete(CronService $service): array
    {
        $service->delete((int) $_POST['id']);
        return ['ok' => true, 'data' => true];
    }

    private function rateLimit(string $endpoint): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $bucket = $method === 'GET' ? 'api-read' : 'api-write:' . $endpoint;
        RateLimiter::hit(
            $bucket,
            (string) Auth::id() . ':' . \SysAdmin\Security::clientIp(),
            (int) config_value('security.api_rate_limit_attempts', 180),
            (int) config_value('security.api_rate_limit_window', 60)
        );
    }

    private function requireFilePermission(string $action): void
    {
        match ($action) {
            'read', 'zip' => Auth::requirePermission('file.view'),
            'upload', 'unzip' => Auth::requirePermission('file.upload'),
            'delete' => Auth::requirePermission('file.delete'),
            'create-folder', 'create-file', 'save-file', 'rename', 'copy', 'move', 'chmod' => Auth::requirePermission('file.edit'),
            default => throw new \InvalidArgumentException('Unknown file action.'),
        };
    }

    /**
     * @param array<int, string> $permissions
     */
    private function requireAnyPermission(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (Auth::can($permission)) {
                return;
            }
        }

        throw new \SysAdmin\HttpException(403, 'Permission denied.');
    }

    private function queueOrRun(string $type, array $payload, callable $operation, ?int $timeout = null): mixed
    {
        if ((bool) config_value('jobs.async_enabled', false)) {
            return [
                'queued' => true,
                'job' => (new \SysAdmin\JobQueue())->dispatch($type, $payload, Auth::id(), $timeout),
            ];
        }
        return $operation();
    }

    private function mysqlImport(MysqlService $service): mixed
    {
        if (!(bool) config_value('jobs.async_enabled', false)) {
            return $service->importSql((string) ($_POST['database'] ?? ''), $_FILES['sql'] ?? []);
        }
        $upload = $_FILES['sql'] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('SQL upload failed.');
        }
        $name = basename((string) ($upload['name'] ?? 'import.sql'));
        if (!str_ends_with(strtolower($name), '.sql')) {
            throw new \InvalidArgumentException('Only .sql imports are allowed.');
        }
        $directory = \SysAdmin\EnvironmentConfig::path('temporary_workspace_root', STORAGE_PATH . '/workspace') . '/imports';
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $staged = $directory . '/' . bin2hex(random_bytes(16)) . '.sql';
        if (!move_uploaded_file((string) $upload['tmp_name'], $staged)) {
            throw new \RuntimeException('Unable to stage SQL import.');
        }
        chmod($staged, 0600);
        return [
            'queued' => true,
            'job' => (new \SysAdmin\JobQueue())->dispatch('mysql.import', [
                'database' => (string) ($_POST['database'] ?? ''),
                'path' => $staged,
                'name' => $name,
            ], Auth::id(), 1200),
        ];
    }

    private function mfa(string $action): array
    {
        Auth::requireLogin();
        $user = Auth::user();
        if (!$user) {
            throw new \SysAdmin\HttpException(401, 'Authentication required.');
        }

        if ($action === 'mfa-disable') {
            \SysAdmin\Models\User::disableMfa((int) $user['id']);
            (new \SysAdmin\MfaRecoveryService())->clear((int) $user['id']);
            $_SESSION['session_version'] = (int) (\SysAdmin\Models\User::find((int) $user['id'])['session_version'] ?? 1);
            \SysAdmin\AuditLogger::warning('mfa.disabled', ['user_id' => $user['id']]);
            return ['ok' => true, 'data' => ['enabled' => false]];
        }

        if ($action === 'mfa-recovery-regenerate') {
            if (empty($user['mfa_enabled'])) {
                throw new \InvalidArgumentException('MFA must be enabled before recovery codes can be generated.');
            }
            return ['ok' => true, 'data' => [
                'recovery_codes' => (new \SysAdmin\MfaRecoveryService())->regenerate((int) $user['id']),
            ]];
        }

        if ($action === 'mfa-setup') {
            $secret = (new \SysAdmin\TotpService())->generateSecret();
            \SysAdmin\Models\User::setMfaSecret((int) $user['id'], $secret, false);
            $_SESSION['session_version'] = (int) (\SysAdmin\Models\User::find((int) $user['id'])['session_version'] ?? 1);
            \SysAdmin\AuditLogger::info('mfa.setup_started', ['user_id' => $user['id']]);
            return ['ok' => true, 'data' => [
                'secret' => $secret,
                'provisioning_uri' => (new \SysAdmin\TotpService())->provisioningUri((string) config_value('app.name', 'MicroSysAdmin'), (string) $user['username'], $secret),
            ]];
        }

        if ($action === 'mfa-enable') {
            $fresh = \SysAdmin\Models\User::find((int) $user['id']);
            if (!$fresh) {
                throw new \SysAdmin\HttpException(401, 'Authentication required.');
            }
            $secret = \SysAdmin\Models\User::mfaSecret($fresh);
            if ($secret === '' || !(new \SysAdmin\TotpService())->verify($secret, (string) ($_POST['code'] ?? ''))) {
                \SysAdmin\AuditLogger::warning('mfa.enable_failed', ['user_id' => $user['id']]);
                throw new \InvalidArgumentException('Invalid authentication code.');
            }
            \SysAdmin\Models\User::setMfaSecret((int) $user['id'], $secret, true);
            $_SESSION['session_version'] = (int) (\SysAdmin\Models\User::find((int) $user['id'])['session_version'] ?? 1);
            \SysAdmin\AuditLogger::warning('mfa.enabled', ['user_id' => $user['id']]);
            return ['ok' => true, 'data' => [
                'enabled' => true,
                'recovery_codes' => (new \SysAdmin\MfaRecoveryService())->regenerate((int) $user['id']),
                'message' => 'Store these recovery codes now. They will not be shown again.',
            ]];
        }

        return ['ok' => false, 'error' => 'Unknown MFA action.'];
    }
}
