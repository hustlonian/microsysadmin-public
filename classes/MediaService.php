<?php

declare(strict_types=1);

namespace SysAdmin;

use Symfony\Component\Process\Process;

final class MediaService
{
    public function tools(): array
    {
        return [
            'ffmpeg' => $this->binary('ffmpeg'),
            'ffprobe' => $this->binary('ffprobe'),
        ];
    }

    public function convert(string $path, string $format): array
    {
        $path = PathGuard::assertAllowed($path);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Media input must be a file.');
        }
        $format = strtolower($format);
        $allowed = ['mp3', 'wav', 'mp4', 'webm', 'gif'];
        if (!in_array($format, $allowed, true)) {
            throw new \InvalidArgumentException('Format is not whitelisted.');
        }
        $ffmpeg = $this->binary('ffmpeg');
        if (!$ffmpeg) {
            throw new \RuntimeException('ffmpeg is not installed.');
        }

        $target = dirname($path) . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '-' . date('Ymd-His') . '.' . $format;
        PathGuard::assertAllowed($target);
        $command = [$ffmpeg, '-y', '-i', $path, $target];
        if (class_exists(Process::class)) {
            $process = new Process($command);
            $process->setTimeout(600);
            $process->run();
            return ['ok' => $process->isSuccessful(), 'output' => $process->getOutput(), 'error' => $process->getErrorOutput(), 'target' => $target];
        }
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start ffmpeg.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['ok' => $code === 0, 'output' => $output, 'error' => $error, 'target' => $target];
    }

    private function binary(string $name): ?string
    {
        if ($name === 'ffmpeg') {
            $configured = EnvironmentConfig::path('ffmpeg_binary');
            return is_executable($configured) ? $configured : null;
        }
        if ($name === 'ffprobe') {
            $configured = dirname(EnvironmentConfig::path('ffmpeg_binary')) . '/ffprobe';
            if (is_executable($configured)) {
                return $configured;
            }
        }
        foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name, EnvironmentConfig::path('xampp_root') . '/bin/' . $name] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }
}
