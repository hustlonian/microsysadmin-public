<?php

declare(strict_types=1);

namespace SysAdmin;

use Symfony\Component\Process\Process;

final class ProcessExecutor
{
    /**
     * @param array<int, string> $command
     * @return array<string, mixed>
     */
    public function run(array $command, int $timeout = 30, ?string $cwd = null, array $environment = []): array
    {
        if ($command === [] || trim((string) $command[0]) === '') {
            throw new \InvalidArgumentException('An executable is required.');
        }

        if (class_exists(Process::class)) {
            $process = new Process($command, $cwd, $environment ?: null);
            $process->setTimeout(max(1, $timeout));
            $process->run();
            return [
                'ok' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ];
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $environment ?: null
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
            'error' => $error,
        ];
    }
}
