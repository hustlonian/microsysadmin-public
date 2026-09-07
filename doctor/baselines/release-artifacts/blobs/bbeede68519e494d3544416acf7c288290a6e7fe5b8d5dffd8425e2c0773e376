<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use SysAdmin\Doctor\engine\Logging\DoctorAuditLogger;
use SysAdmin\Doctor\engine\Security\SecretRedactor;
use SysAdmin\Doctor\engine\Support\Value\CommandResult;

final class ProcessRunner
{
    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly ?DoctorAuditLogger $audit = null,
        private readonly int $maximumOutputBytes = 1048576,
        private readonly int $maximumTimeout = 900,
    ) {
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $environment
     */
    public function run(array $command, int $timeout = 20, ?string $cwd = null, array $environment = [], ?string $serviceUser = null): CommandResult
    {
        $this->validate($command, $cwd, $environment, $serviceUser);
        $timeout = max(1, min($this->maximumTimeout, $timeout));
        $effective = $serviceUser === null ? $command : ['runuser', '--user', $serviceUser, '--', ...$command];
        $safeCommand = $this->redactor->command($effective);
        $safeEnvironment = array_fill_keys(array_keys($environment), '[PRESENT]');
        $this->audit?->event('doctor.command.started', 'Structured command started.', [
            'command' => $safeCommand, 'cwd' => $cwd, 'environment' => $safeEnvironment,
            'timeout' => $timeout, 'service_user' => $serviceUser,
        ]);
        $started = microtime(true);
        $timedOut = false;
        $exitCode = null;
        $stdout = '';
        $stderr = '';

        try {
            if (class_exists(Process::class)) {
                $process = new Process($effective, $cwd, $environment === [] ? null : $environment);
                $process->setTimeout($timeout);
                $process->run();
                $exitCode = $process->getExitCode();
                $stdout = $process->getOutput();
                $stderr = $process->getErrorOutput();
            } else {
                [$exitCode, $stdout, $stderr, $timedOut] = $this->runNative($effective, $timeout, $cwd, $environment);
            }
        } catch (ProcessTimedOutException $exception) {
            $timedOut = true;
            $stderr = $exception->getMessage();
        } catch (\Throwable $exception) {
            $stderr = $exception->getMessage();
        }

        $duration = (microtime(true) - $started) * 1000;
        $stdout = $this->truncate($this->redactor->text($stdout));
        $stderr = $this->truncate($this->redactor->text($stderr));
        $result = new CommandResult([
            'successful' => !$timedOut && $exitCode === 0,
            'command' => $safeCommand,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
            'duration_ms' => round($duration, 3),
            'timed_out' => $timedOut,
            'cwd' => $cwd,
        ]);
        $this->audit?->event('doctor.command.completed', 'Structured command completed.', $result->toArray(), $result->successful() ? 'INFO' : 'WARNING');
        return $result;
    }

    private function validate(array $command, ?string $cwd, array $environment, ?string $serviceUser): void
    {
        if ($command === [] || !is_string($command[0]) || trim($command[0]) === '') {
            throw new \InvalidArgumentException('A structured command requires an executable.');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('Command arguments must be strings without null bytes.');
            }
        }
        if ($cwd !== null && (!is_dir($cwd) || !is_readable($cwd))) {
            throw new \InvalidArgumentException('Command working directory is unavailable.');
        }
        foreach ($environment as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) || !is_string($value) || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('Invalid command environment variable.');
            }
            if (preg_match('/pass|secret|token|key|credential/i', (string) $key)) {
                $this->redactor->register($value);
            }
        }
        if ($serviceUser !== null && !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $serviceUser)) {
            throw new \InvalidArgumentException('Invalid service account name.');
        }
    }

    private function runNative(array $command, int $timeout, ?string $cwd, array $environment): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment === [] ? null : $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $started >= $timeout) {
                proc_terminate($process, 15);
                usleep(100000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                $timedOut = true;
                break;
            }
            usleep(10000);
        } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        return [$timedOut ? null : $exitCode, $stdout, $stderr, $timedOut];
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= $this->maximumOutputBytes) {
            return $value;
        }
        return substr($value, 0, $this->maximumOutputBytes) . "\n[OUTPUT TRUNCATED]";
    }
}
