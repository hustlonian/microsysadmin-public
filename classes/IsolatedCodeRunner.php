<?php

declare(strict_types=1);

namespace SysAdmin;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/** No host-shell fallback. Only runtime binaries and the submitted file cross the boundary. */
final class IsolatedCodeRunner
{
    public const MAX_OUTPUT = 131072;
    private const LANGUAGES = ['bash' => ['/bin/bash', 'sh'], 'php' => ['/usr/bin/php', 'php'],
        'python' => ['/usr/bin/python3', 'py'], 'javascript' => ['/usr/bin/node', 'js']];

    public function __construct(private readonly array $binaries = []) {}

    public function run(string $language, string $code, string $input = '', int $timeout = 10): array
    {
        if (!isset(self::LANGUAGES[$language]) || $code === '' || strlen($code) > 65536 || strlen($input) > 32768) {
            throw new \InvalidArgumentException('Use a supported language and bounded nonempty script/input.');
        }
        // Browser multipart forms serialize textarea newlines as CRLF. Bash needs Unix source lines.
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        $started = microtime(true); $timeout = max(1, min(30, $timeout));
        $output = ''; $error = ''; $limited = false; $timedOut = false; $exit = 125; $process = null; $directory = null;
        try {
            if (!function_exists('posix_geteuid') || !function_exists('posix_getegid')) { throw new \RuntimeException('Non-root sandbox identity support is unavailable.'); }
            $binary = $this->binaries[$language] ?? self::LANGUAGES[$language][0];
            if (!is_executable($binary)) { $exit = 127; throw new \RuntimeException('The requested interpreter is unavailable.'); }
            foreach (['/usr/bin/bwrap', '/usr/bin/prlimit'] as $required) {
                if (!is_executable($required)) { throw new \RuntimeException('Required isolation backend is unavailable; host execution is disabled.'); }
            }
            // Only reviewed runtime locations can be selected, never application or request paths.
            $realBinary = realpath($binary);
            if (!is_string($realBinary) || !str_starts_with($realBinary, '/usr/bin/')) { throw new \RuntimeException('Interpreter is outside the isolated runtime image.'); }
            $directory = sys_get_temp_dir() . '/microsysadmin-code-' . bin2hex(random_bytes(16));
            if (!mkdir($directory, 0700)) { throw new \RuntimeException('Unable to create private runner input.'); }
            $file = $directory . '/script.' . self::LANGUAGES[$language][1];
            if (file_put_contents($file, $code, LOCK_EX) !== strlen($code) || !chmod($file, 0644)) { throw new \RuntimeException('Unable to save isolated script input.'); }
            $uid = posix_geteuid() === 0 ? 65534 : posix_geteuid(); $gid = posix_getegid() === 0 ? 65534 : posix_getegid();
            $command = ['/usr/bin/prlimit', '--cpu=' . ($timeout + 1), '--as=1073741824', '--nproc=64', '--nofile=64', '--fsize=1048576', '--core=0', '--',
                '/usr/bin/bwrap', '--unshare-all', '--unshare-user', '--die-with-parent', '--new-session', '--disable-userns', '--assert-userns-disabled',
                '--ro-bind', '/usr/bin', '/usr/bin', '--ro-bind', '/usr/lib', '/usr/lib',
                '--symlink', 'usr/bin', '/bin', '--symlink', 'usr/lib', '/lib'];
            if (is_dir('/usr/lib64')) { array_push($command, '--ro-bind', '/usr/lib64', '/usr/lib64', '--symlink', 'usr/lib64', '/lib64'); }
            if (is_dir('/usr/share/zoneinfo')) { array_push($command, '--ro-bind', '/usr/share/zoneinfo', '/usr/share/zoneinfo'); }
            array_push($command, '--proc', '/proc', '--dev', '/dev', '--size', '16777216', '--tmpfs', '/work', '--chmod', '0777', '/work',
                '--size', '16777216', '--tmpfs', '/tmp', '--ro-bind', $file, '/script.' . self::LANGUAGES[$language][1],
                '--clearenv', '--setenv', 'PATH', '/usr/bin:/bin', '--setenv', 'HOME', '/work', '--setenv', 'TMPDIR', '/tmp', '--setenv', 'LANG', 'C',
                '--chdir', '/work', '--uid', (string)$uid, '--gid', (string)$gid, '--cap-drop', 'ALL', $realBinary);
            if ($language === 'bash') { array_push($command, '--noprofile', '--norc'); }
            if ($language === 'php') { array_push($command, '-n', '-d', 'display_errors=stderr', '-d', 'memory_limit=128M'); }
            if ($language === 'python') { array_push($command, '-I', '-B'); }
            if ($language === 'javascript') { array_push($command, '--jitless', '--max-old-space-size=64', '--max-semi-space-size=4'); }
            $command[] = '/script.' . self::LANGUAGES[$language][1];
            $environment = array_fill_keys(array_unique(array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER))), false);
            $environment['PATH'] = '/usr/bin:/bin'; $environment['LANG'] = 'C';
            $process = new Process($command, '/', $environment, $input, $timeout);
            $process->disableOutput();
            $process->start(static function (string $type, string $chunk) use (&$output, &$error, &$limited): void {
                $remaining = max(0, self::MAX_OUTPUT - 256 - strlen($output) - strlen($error));
                if (strlen($chunk) > $remaining) { $limited = true; }
                if ($type === Process::OUT) { $output .= substr($chunk, 0, $remaining); }
                else { $error .= substr($chunk, 0, $remaining); }
            });
            while ($process->isRunning()) {
                $process->checkTimeout();
                if ($limited) { $process->stop(0.1, 9); break; }
                usleep(10000);
            }
            $exit = $limited ? 125 : ($process->getExitCode() ?? 125);
        } catch (ProcessTimedOutException) {
            $timedOut = true; $exit = 124;
        } catch (\Throwable $exception) {
            // No source, input, environment values, host interpreter details or process argv in errors.
            $error = substr($exception->getMessage(), 0, 1024);
        } finally {
            if ($process !== null && $process->isRunning()) { $process->stop(0.1, 9); }
            if ($directory !== null) { if (isset($file) && is_file($file)) { unlink($file); } rmdir($directory); }
        }
        if ($timedOut) { $error = substr($error, 0, self::MAX_OUTPUT - strlen($output) - 80) . "\nExecution timed out; the isolated process namespace was stopped."; }
        if ($limited) { $error = substr($error, 0, max(0, self::MAX_OUTPUT - strlen($output) - 80)) . "\nOutput limit reached; isolated execution stopped."; }
        return ['ok' => $exit === 0 && !$timedOut && !$limited, 'language' => $language, 'exit_code' => $exit,
            'output' => $output, 'error' => $error, 'timed_out' => $timedOut, 'output_limited' => $limited,
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000), 'working_directory' => '/work', 'started_in' => '/work',
            'saved_file' => null, 'real_files' => false, 'isolated' => true,
            'warning' => 'Private temporary workspace; files are discarded after this run. Network and application files are unavailable.'];
    }
}
