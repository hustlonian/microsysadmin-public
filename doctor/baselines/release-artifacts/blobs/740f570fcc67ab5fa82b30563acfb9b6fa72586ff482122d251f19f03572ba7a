<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\MachineAgentInterface;

final class LocalMachineAgent implements MachineAgentInterface
{
    public function version(): string
    {
        return 'v1';
    }

    public function execute(string $action, array $payload = [], array $context = []): array
    {
        return match ($action) {
            'health' => ['checks' => (new SystemProbe())->healthChecks()],
            'metrics' => (new SystemProbe())->metrics(),
            'processes' => (new SystemProbe())->topProcesses((int) ($payload['limit'] ?? 100)),
            'machine.metadata' => (new MachineIdentity())->get(),
            'environment.status' => [
                'configuration' => EnvironmentConfig::all(),
                'diagnostics' => EnvironmentConfig::diagnostics(),
                'detected' => (new EnvironmentDetector())->detect(),
            ],
            'services.status' => (new ServiceControlManager())->states(),
            'services.action' => (new ServiceControlManager())->perform(
                (string) ($payload['service'] ?? ''),
                (string) ($payload['action'] ?? 'status')
            ),
            'files.list' => (new FileVault())->list((string) ($payload['path'] ?? '')),
            'files.read' => (new FileVault())->read((string) ($payload['path'] ?? '')),
            'files.preview' => (new FileVault())->preview((string) ($payload['path'] ?? '')),
            'files.search' => (new FileVault())->search(
                (string) ($payload['root'] ?? ''),
                (string) ($payload['query'] ?? '')
            ),
            'files.save' => ['path' => (new FileVault())->saveFile(
                (string) ($payload['path'] ?? ''),
                (string) ($payload['content'] ?? '')
            )],
            'files.delete' => ['trash' => (new FileVault())->delete((string) ($payload['path'] ?? ''))],
            'databases.list' => (new MysqlService())->databases(),
            'databases.tables' => (new MysqlService())->tables((string) ($payload['database'] ?? '')),
            'databases.rows' => (new MysqlService())->rows(
                (string) ($payload['database'] ?? ''),
                (string) ($payload['table'] ?? ''),
                min(100, max(1, (int) ($payload['limit'] ?? 100))),
                max(0, (int) ($payload['offset'] ?? 0))
            ),
            'git.repositories' => (new GitService())->repositories(),
            'git.detail' => (new GitService())->detail((string) ($payload['path'] ?? '')),
            'git.action' => (new GitService())->action(
                (string) ($payload['path'] ?? ''),
                (string) ($payload['action'] ?? ''),
                (string) ($payload['message'] ?? '')
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
            'backups.list' => (new BackupService())->list(),
            'backups.database' => (new BackupService())->database((string) ($payload['database'] ?? '')),
            'backups.folder' => (new BackupService())->folder((string) ($payload['path'] ?? '')),
            'backups.restore' => (new BackupService())->restore(
                (string) ($payload['archive'] ?? ''),
                (string) ($payload['target'] ?? '')
            ),
            'scheduled-tasks.list' => [
                'system' => (new CronService())->systemJobs(),
                'managed' => (new CronService())->managed(),
            ],
            'scheduled-tasks.create' => ['id' => (new CronService())->create(
                (string) ($payload['name'] ?? ''),
                (string) ($payload['schedule'] ?? ''),
                (string) ($payload['script'] ?? '')
            )],
            'scheduled-tasks.enable' => $this->taskToggle((int) ($payload['id'] ?? 0), true),
            'scheduled-tasks.disable' => $this->taskToggle((int) ($payload['id'] ?? 0), false),
            'scheduled-tasks.delete' => $this->taskDelete((int) ($payload['id'] ?? 0)),
            'scripts.list' => (new ScriptService())->scripts(),
            'scripts.run' => (new ScriptService())->run((string) ($payload['script'] ?? '')),
            'logs.files' => (new LogService())->files(),
            'logs.read' => (new LogService())->read(
                (string) ($payload['label'] ?? ''),
                (string) ($payload['search'] ?? ''),
                min(1000, max(1, (int) ($payload['limit'] ?? 300)))
            ),
            'logs.analyze' => (new LogService())->analyze((string) ($payload['label'] ?? '')),
            'websites.list' => (new HostingService())->overview((bool) ($payload['include_sizes'] ?? false)),
            'websites.create' => (new HostingService())->createSite(
                (string) ($payload['name'] ?? ''),
                (string) ($payload['root'] ?? ''),
                (string) ($payload['domain'] ?? '')
            ),
            'websites.delete' => (new HostingService())->deleteSite((string) ($payload['document_root'] ?? '')),
            'terminal.status' => [
                'enabled' => SecurityProfile::allows('terminal'),
                'profile' => SecurityProfile::current(),
            ],
            'terminal.start' => $this->terminalStart($payload),
            'terminal.output' => $this->terminalOutput($payload),
            'terminal.input' => $this->terminalInput($payload),
            'terminal.stop' => $this->terminalStop($payload),
            'notifications.test' => (new NotificationService())->sendTest((string) ($payload['email'] ?? '')),
            default => throw new \InvalidArgumentException('Unknown agent action.'),
        };
    }

    private function taskToggle(int $id, bool $enabled): array
    {
        (new CronService())->setEnabled($id, $enabled);
        return ['id' => $id, 'enabled' => $enabled];
    }

    private function taskDelete(int $id): array
    {
        (new CronService())->delete($id);
        return ['id' => $id, 'deleted' => true];
    }

    private function terminalStart(array $payload): array
    {
        $this->assertAgentTerminalEnabled();
        return (new SandboxService())->startTerminal(
            (string) ($payload['language'] ?? 'bash'),
            (string) ($payload['code'] ?? ''),
            (bool) ($payload['confirmed'] ?? false),
            (int) ($payload['cols'] ?? 100),
            (int) ($payload['rows'] ?? 30)
        );
    }

    private function terminalOutput(array $payload): array
    {
        $this->assertAgentTerminalEnabled();
        return (new SandboxService())->terminalOutput(
            (string) ($payload['session'] ?? ''),
            (int) ($payload['offset'] ?? 0)
        );
    }

    private function terminalInput(array $payload): array
    {
        $this->assertAgentTerminalEnabled();
        return (new SandboxService())->terminalInput(
            (string) ($payload['session'] ?? ''),
            (string) ($payload['input'] ?? '')
        );
    }

    private function terminalStop(array $payload): array
    {
        $this->assertAgentTerminalEnabled();
        return (new SandboxService())->stopTerminal((string) ($payload['session'] ?? ''));
    }

    private function assertAgentTerminalEnabled(): void
    {
        if (!(bool) config_value('agent.allow_terminal', false)) {
            throw new HttpException(403, 'Terminal sessions are disabled on the agent API.');
        }
        SecurityProfile::assertAllowed('terminal');
    }
}
