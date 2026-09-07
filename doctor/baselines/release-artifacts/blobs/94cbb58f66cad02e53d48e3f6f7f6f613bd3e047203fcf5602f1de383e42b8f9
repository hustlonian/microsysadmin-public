<?php

declare(strict_types=1);

namespace SysAdmin;

use Symfony\Component\Process\Process;

final class CommandRunner
{
    private array $commands;

    public function __construct()
    {
        $this->commands = require APP_ROOT . '/config/commands.php';
    }

    public function service(string $service, string $action): array
    {
        return (new ServiceControlManager())->perform($service, $action);
    }

    public function system(string $key, int $timeout = 15, bool $audit = true): array
    {
        $map = $this->commands['system'][$key] ?? null;
        if (!$map) {
            throw new \InvalidArgumentException('Command is not whitelisted.');
        }

        return $this->runApproved($map, $timeout, null, $audit);
    }

    public function git(string $repo, array $args, int $timeout = 60, bool $audit = true): array
    {
        $repo = PathGuard::assertAllowedDirectory($repo);
        $allowedFirst = ['status', 'branch', 'log', 'diff', 'fetch', 'pull', 'push', 'add', 'commit', 'rev-parse'];
        if (!$args || !in_array($args[0], $allowedFirst, true)) {
            throw new \InvalidArgumentException('Git command is not whitelisted.');
        }

        return $this->runApproved(array_merge(['git', '-C', $repo], $args), $timeout, null, $audit);
    }

    public function artisan(string $project, string $command, bool $allowAny = false, int $timeout = 120): array
    {
        $project = PathGuard::assertAllowedDirectory($project);
        if (
            !is_file($project . '/artisan')
            || !is_file($project . '/composer.json')
            || !is_file($project . '/bootstrap/app.php')
            || !is_file($project . '/vendor/autoload.php')
        ) {
            throw new \InvalidArgumentException('Selected path is not a Laravel project.');
        }

        $command = trim($command);
        $arguments = $this->parseCommandLine($command);
        if (!$allowAny && !in_array($command, $this->commands['artisan'] ?? [], true)) {
            throw new \InvalidArgumentException('Artisan command is not whitelisted.');
        }
        if ($allowAny) {
            $this->assertWebRunnableArtisanCommand($arguments);
        }
        if (!in_array('--no-interaction', $arguments, true) && !in_array('-n', $arguments, true)) {
            $arguments[] = '--no-interaction';
        }

        $lock = $this->acquireProjectCommandLock($project);
        try {
            return $this->runApproved(
                array_merge([EnvironmentConfig::path('php_binary'), 'artisan'], $arguments),
                max(10, min(300, $timeout)),
                $project
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function composer(string $project, string $command, int $timeout = 180): array
    {
        $project = PathGuard::assertAllowedDirectory($project);
        if (!is_file($project . '/composer.json')) {
            throw new \InvalidArgumentException('Selected path has no composer.json.');
        }

        $allowed = $this->commands['composer'] ?? [];
        if (!in_array($command, $allowed, true)) {
            throw new \InvalidArgumentException('Composer command is not whitelisted.');
        }

        return $this->runApproved([EnvironmentConfig::path('composer_binary'), $command, '--no-interaction'], $timeout, $project);
    }

    public function script(string $scriptName, int $timeout = 300): array
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\.sh$/', $scriptName)) {
            throw new \InvalidArgumentException('Invalid script name.');
        }

        $scriptRoot = EnvironmentConfig::path('script_root', STORAGE_PATH . '/scripts');
        $script = realpath($scriptRoot . '/' . $scriptName);
        $root = realpath($scriptRoot);
        if (!$script || !$root || !str_starts_with($script, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Script is outside the allowed scripts directory.');
        }

        return $this->runApproved([EnvironmentConfig::path('bash_binary'), $script], $timeout);
    }

    public function runReadOnly(array $command, int $timeout = 20, ?string $cwd = null, bool $audit = true): array
    {
        $allowed = array_values(array_unique([
            'df', 'free', 'ps', 'uptime', 'whoami', 'hostname', 'uname', 'findmnt',
            'ss', 'ip', 'crontab', 'openssl', 'tail',
            EnvironmentConfig::path('git_binary'),
            EnvironmentConfig::path('xampp_root') . '/lampp',
            EnvironmentConfig::path('php_binary'),
            EnvironmentConfig::path('mysql_binary'),
            EnvironmentConfig::path('xampp_root') . '/bin/httpd',
            EnvironmentConfig::path('composer_binary'),
        ]));
        if (!in_array($command[0] ?? '', $allowed, true)) {
            throw new \InvalidArgumentException('Read-only command is not whitelisted.');
        }

        return $this->runApproved($command, $timeout, $cwd, $audit);
    }

    private function runApproved(array $command, int $timeout = 30, ?string $cwd = null, bool $audit = true): array
    {
        if ($audit) {
            AuditLogger::info('command.run', ['command' => $this->redact($command), 'cwd' => $cwd]);
        }

        if (class_exists(Process::class)) {
            $process = new Process($command, $cwd ?: null);
            $process->setTimeout($timeout);
            $process->run();

            return [
                'ok' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
                'command' => $this->redact($command),
            ];
        }

        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($command, $descriptor, $pipes, $cwd ?: null);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start process.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'ok' => $code === 0,
            'exit_code' => $code,
            'output' => $output,
            'error' => $error,
            'command' => $this->redact($command),
        ];
    }

    private function redact(array $command): string
    {
        $redacted = [];
        $redactNext = false;
        foreach ($command as $part) {
            $part = (string) $part;
            if ($redactNext) {
                $redacted[] = '[redacted]';
                $redactNext = false;
                continue;
            }

            if (
                preg_match('/(?:password|passwd|secret|token|api[-_]?key)/i', $part)
                || preg_match('/^--?[A-Za-z0-9_-]*key(?:=|$)/i', $part)
            ) {
                if (str_contains($part, '=')) {
                    [$name] = explode('=', $part, 2);
                    $redacted[] = $name . '=[redacted]';
                } else {
                    $redacted[] = $part;
                    $redactNext = str_starts_with($part, '-');
                }
                continue;
            }

            $redacted[] = $part;
        }

        return implode(' ', $redacted);
    }

    private function parseCommandLine(string $command): array
    {
        if ($command === '' || strlen($command) > 2000 || preg_match('/[\x00\r\n]/', $command)) {
            throw new \InvalidArgumentException('Enter a valid Artisan command.');
        }

        $arguments = [];
        $current = '';
        $quote = null;
        $escaping = false;
        $tokenStarted = false;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $character = $command[$index];
            if ($escaping) {
                $current .= $character;
                $escaping = false;
                $tokenStarted = true;
                continue;
            }

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } elseif ($character === '\\' && $quote === '"') {
                    $escaping = true;
                } else {
                    $current .= $character;
                }
                $tokenStarted = true;
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $tokenStarted = true;
                continue;
            }
            if ($character === '\\') {
                $escaping = true;
                $tokenStarted = true;
                continue;
            }
            if (ctype_space($character)) {
                if ($tokenStarted) {
                    $arguments[] = $current;
                    $current = '';
                    $tokenStarted = false;
                }
                continue;
            }

            $current .= $character;
            $tokenStarted = true;
        }

        if ($escaping || $quote !== null) {
            throw new \InvalidArgumentException('Artisan command contains an unfinished quote or escape.');
        }
        if ($tokenStarted) {
            $arguments[] = $current;
        }
        if (!$arguments || count($arguments) > 64) {
            throw new \InvalidArgumentException('Artisan command has too many arguments.');
        }
        foreach ($arguments as $argument) {
            if (strlen($argument) > 1024) {
                throw new \InvalidArgumentException('An Artisan command argument is too long.');
            }
        }
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]*\z/', $arguments[0])) {
            throw new \InvalidArgumentException('Invalid Artisan command name.');
        }

        return $arguments;
    }

    private function assertWebRunnableArtisanCommand(array $arguments): void
    {
        $command = strtolower((string) ($arguments[0] ?? ''));
        $persistent = [
            'db',
            'horizon',
            'octane:start',
            'pail',
            'queue:listen',
            'reverb:start',
            'schedule:work',
            'serve',
            'websockets:serve',
        ];
        if (in_array($command, $persistent, true)) {
            throw new \InvalidArgumentException(
                'This persistent Artisan command must be run from the Terminal page.'
            );
        }
        if (
            $command === 'queue:work'
            && !in_array('--once', $arguments, true)
            && !in_array('--stop-when-empty', $arguments, true)
        ) {
            throw new \InvalidArgumentException(
                'Use queue:work with --once or --stop-when-empty from this page.'
            );
        }
        if (
            $command === 'tinker'
            && count(array_filter(
                $arguments,
                static fn (string $argument): bool => $argument === '--execute'
                    || str_starts_with($argument, '--execute=')
            )) === 0
        ) {
            throw new \InvalidArgumentException('Use tinker --execute from this page.');
        }
    }

    /** @return resource */
    private function acquireProjectCommandLock(string $project)
    {
        $directory = STORAGE_PATH . '/locks';
        if (!is_dir($directory) && !mkdir($directory, 02775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the command lock directory.');
        }
        @chmod($directory, 02775);

        $path = $directory . '/artisan-' . hash('sha256', $project) . '.lock';
        $lock = fopen($path, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to create the Artisan command lock.');
        }
        @chmod($path, 0660);

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new HttpException(409, 'Another Artisan command is already running for this site.');
        }

        return $lock;
    }
}
