<?php

declare(strict_types=1);

namespace SysAdmin;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SandboxService implements \SysAdmin\Contracts\TerminalSessionInterface
{
    private const MAX_CODE_BYTES = 65536;
    private const MAX_INPUT_BYTES = 32768;
    private const MAX_OUTPUT_BYTES = 131072;
    private const MAX_TERMINAL_INPUT_BYTES = 8192;
    private const MAX_TERMINAL_OUTPUT_CHUNK_BYTES = 65536;
    private const TERMINAL_IDLE_SECONDS = 900;
    private const WORKSPACE_RELATIVE = 'workspace/code-runner';
    private const WEB_FILE_TYPES = [
        'index.html' => 'text/html; charset=utf-8',
        'style.css' => 'text/css; charset=utf-8',
        'app.js' => 'application/javascript; charset=utf-8',
    ];

    public function pageData(): array
    {
        $examples = $this->examples();

        return [
            'examples' => $examples,
            'sources' => $this->sources($examples),
            'workspace' => $this->workspace(),
        ];
    }

    public function examples(): array
    {
        return [
            'php' => "<?php\n",
            'bash' => "#!/usr/bin/env bash\nmessage='Hello from Bash'\nfor n in 1 2 3; do\n  printf '%s %s\\n' \"\$message\" \"\$n\"\ndone\n",
            'html' => <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Code Runner Preview</title>
  <link rel="stylesheet" href="style.css">
  <script src="app.js" defer></script>
</head>
<body>
  <main class="demo">
    <h1>Real Preview</h1>
    <p>This page is saved as a real HTML file before it loads.</p>
    <button id="counter">Clicked 0 times</button>
  </main>
</body>
</html>
HTML,
            'css' => <<<'CSS'
body {
  margin: 0;
  font-family: system-ui, sans-serif;
  background: #f8fafc;
  color: #172033;
}

.demo {
  min-height: 100vh;
  display: grid;
  place-content: center;
  gap: 1rem;
  text-align: center;
}

button {
  border: 0;
  border-radius: 6px;
  padding: .75rem 1rem;
  background: #2563eb;
  color: #fff;
  font-weight: 700;
}
CSS,
            'javascript' => <<<'JS'
let count = 0;
document.getElementById('counter').addEventListener('click', () => {
  count += 1;
  document.getElementById('counter').textContent = `Clicked ${count} times`;
  console.log('Counter updated:', count);
});
JS,
        ];
    }

    public function sources(?array $examples = null): array
    {
        $examples ??= $this->examples();

        return [
            'php' => $examples['php'],
            'bash' => $examples['bash'],
            'html' => $this->readWorkspaceFile('web/index.html') ?? $examples['html'],
            'css' => $this->readWorkspaceFile('web/style.css') ?? $examples['css'],
            'javascript' => $this->readWorkspaceFile('web/app.js') ?? $examples['javascript'],
        ];
    }

    public function workspace(): array
    {
        $root = $this->workspaceRoot();
        $executionRoot = $this->executionRoot();

        return [
            'root' => $root,
            'working_directory' => '/work',
            'bash_working_directory' => '/work',
            'php_file' => $root . '/php/main.php',
            'bash_file' => $root . '/bash/script.sh',
            'web_dir' => $root . '/web',
            'web_index' => $root . '/web/index.html',
            'web_css' => $root . '/web/style.css',
            'web_js' => $root . '/web/app.js',
            'preview_url' => url('workspace-preview/index.html'),
        ];
    }

    public function run(string $language, string $code, string $input = '', int $timeout = 10, bool $confirmed = false): array
    {
        SecurityProfile::assertAllowed('code_runner');
        $this->requireRealConfirmation($confirmed);
        $language = strtolower(trim($language));
        $result = (new IsolatedCodeRunner())->run($language, $code, $input, $timeout);
        AuditLogger::info('code_runner.run', ['language' => $language, 'submitted_source_sha256' => hash('sha256', $code),
            'executed_source_sha256' => hash('sha256', str_replace(["\r\n", "\r"], "\n", $code)),
            'exit_code' => $result['exit_code'], 'timed_out' => $result['timed_out'], 'output_limited' => $result['output_limited'],
            'elapsed_ms' => $result['elapsed_ms'], 'isolation' => 'REQUIRED_NO_HOST_FALLBACK']);
        return $result;
    }

    public function startTerminal(string $language, string $code = '', bool $confirmed = false, int $cols = 100, int $rows = 30): array
    {
        SecurityProfile::assertAllowed('terminal');
        throw new \RuntimeException('Interactive host shells are disabled in Code Runner. Submit a bounded isolated Bash script.');
    }

    public function terminalOutput(string $id, int $offset = 0): array
    {
        [$sessionDir, $meta] = $this->requireTerminalSession($id);
        $paths = $this->terminalPaths($sessionDir);
        @touch($paths['heartbeat']);

        $status = $this->readJsonFile($paths['status']);
        $running = $this->terminalIsRunning($meta, $status);
        if (!$running) {
            $this->persistTerminalDirectory($paths['cwd']);
        }

        $output = '';
        $size = is_file($paths['output']) ? (int) filesize($paths['output']) : 0;
        $offset = max(0, min($offset, $size));
        if ($size > $offset && ($handle = fopen($paths['output'], 'rb')) !== false) {
            fseek($handle, $offset);
            $output = (string) fread($handle, min(self::MAX_TERMINAL_OUTPUT_CHUNK_BYTES, $size - $offset));
            $offset = (int) ftell($handle);
            fclose($handle);
        }

        return [
            'id' => $id,
            'output_b64' => base64_encode($output),
            'offset' => $offset,
            'running' => $running,
            'state' => (string) ($status['state'] ?? ($running ? 'running' : 'finished')),
            'exit_code' => $status['exit_code'] ?? null,
            'working_directory' => $this->terminalWorkingDirectory($paths['cwd'], $meta),
        ];
    }

    public function terminalInput(string $id, string $input): array
    {
        SecurityProfile::assertAllowed('terminal');
        throw new \RuntimeException('Legacy host-shell input is disabled; use an isolated script.');
    }

    public function stopTerminal(string $id): array
    {
        [$sessionDir, $meta] = $this->requireTerminalSession($id);
        $paths = $this->terminalPaths($sessionDir);
        file_put_contents($paths['stop'], (string) time(), LOCK_EX);
        $pid = (int) ($meta['pid'] ?? 0);

        AuditLogger::info('code_runner.terminal.stop', [
            'id' => $id,
            'pid' => $pid,
        ]);

        return ['ok' => true];
    }

    public function saveWeb(string $html, string $css, string $javascript, bool $confirmed = false): array
    {
        SecurityProfile::assertAllowed('code_runner');
        $this->requireRealConfirmation($confirmed);

        $files = [
            'index.html' => $html,
            'style.css' => $css,
            'app.js' => $javascript,
        ];

        foreach ($files as $name => $contents) {
            if ($name === 'index.html' && $contents === '') {
                throw new \InvalidArgumentException($name . ' cannot be empty.');
            }

            if (strlen($contents) > self::MAX_CODE_BYTES) {
                throw new \InvalidArgumentException($name . ' is too large.');
            }
        }

        $dir = $this->ensureWorkspaceDirectory('web');
        $paths = [];
        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            if (file_put_contents($path, $contents) === false) {
                throw new \RuntimeException('Unable to write ' . $name . '.');
            }
            chmod($path, 0640);
            $paths[$name] = $path;
        }

        AuditLogger::info('code_runner.web.save', [
            'files' => array_values($paths),
            'preview_url' => url('workspace-preview/index.html'),
        ]);

        return [
            'ok' => true,
            'files' => $paths,
            'preview_url' => url('workspace-preview/index.html'),
            'version' => time(),
            'real_files' => true,
            'warning' => 'Saved browser files preview in an isolated frame without application privileges.',
        ];
    }

    public function sendWebFile(string $file): never
    {
        $name = basename(str_replace('\\', '/', $file));
        if (!isset(self::WEB_FILE_TYPES[$name])) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Preview file not found.';
            exit;
        }

        $path = $this->workspacePath('web/' . $name);
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No saved preview file.';
            exit;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: sandbox allow-scripts; frame-ancestors 'self'; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'");
        header('Content-Type: ' . self::WEB_FILE_TYPES[$name]);
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        if ($name === 'index.html') { echo $this->previewDocument((string)file_get_contents($path)); } else { readfile($path); }
        exit;
    }

    private function previewDocument(string $html): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $css = json_encode($this->readWorkspaceFile('web/style.css') ?? '', $flags);
        $js = json_encode($this->readWorkspaceFile('web/app.js') ?? '', $flags);
        // Inline only the two fixed saved resources: opaque-origin frames cannot fetch authenticated files.
        $html = preg_replace_callback('~<link\b(?=[^>]*\bhref=[\"\'](?:\./)?style\.css[\"\'])[^>]*>~i',
            static fn() => '<script>(()=>{const s=document.createElement("style");s.textContent=' . $css . ';document.head.appendChild(s)})()</script>', $html) ?? $html;
        return preg_replace_callback('~<script\b(?=[^>]*\bsrc=[\"\'](?:\./)?app\.js[\"\'])[^>]*>\s*</script>~i',
            static fn() => '<script>(()=>{const run=()=>{const s=document.createElement("script");s.textContent=' . $js . ';document.body.appendChild(s)};if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run,{once:true})}else{run()}})()</script>', $html) ?? $html;
    }

    private function phpCommand(string $file): array
    {
        return [
            $this->phpBinary(),
            '-d',
            'display_errors=1',
            '-d',
            'html_errors=0',
            '-d',
            'error_reporting=E_ALL',
            '-d',
            'memory_limit=256M',
            '-d',
            'max_execution_time=30',
            $file,
        ];
    }

    private function bashCommand(string $file, string $cwdFile): array
    {
        return [
            'bash',
            '--noprofile',
            '--norc',
            '-c',
            'trap \'status=$?; pwd > "$2"; exit $status\' EXIT; source "$1"',
            'sysadmin-bash-runner',
            $file,
            $cwdFile,
        ];
    }

    private function runProcess(array $command, string $cwd, string $input, int $timeout, string $workspaceRoot): array
    {
        if (!class_exists(Process::class)) {
            throw new \RuntimeException('Symfony Process is required to run code.');
        }

        $process = new Process($command, $cwd, [
            'HOME' => $workspaceRoot,
            'TMPDIR' => $workspaceRoot,
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ]);
        $process->setInput($input);
        $process->setTimeout($timeout);

        $timedOut = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $process->stop(1, 9);
        }

        $output = $this->clip($process->getOutput());
        $error = $this->clip($process->getErrorOutput());
        if ($timedOut) {
            $error = trim($error . "\nProcess timed out after {$timeout} seconds.");
        }

        return [
            'ok' => !$timedOut && $process->isSuccessful(),
            'timed_out' => $timedOut,
            'exit_code' => $timedOut ? 124 : $process->getExitCode(),
            'output' => $output,
            'error' => $error,
        ];
    }

    private function requireRealConfirmation(bool $confirmed): void
    {
        if (!$confirmed) {
            throw new \InvalidArgumentException('Confirm that this action may change real files.');
        }
    }

    private function cleanLegacyPhpSource(string $code): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $code);
        $legacy = <<<'PHP'
$name = trim(stream_get_contents(STDIN)) ?: 'MicroSysAdmin';
$path = getcwd() . '/storage/workspace/code-runner/php/last-run.txt';

file_put_contents($path, "Hello, {$name}! Written at " . date(DATE_ATOM) . PHP_EOL);

echo "Hello, {$name}!\n";
echo "PHP " . PHP_VERSION . "\n";
echo "Working directory: " . getcwd() . "\n";
echo "Wrote: {$path}\n";
PHP;

        $cleaned = str_replace($legacy, '', $normalized, $replacements);
        if ($replacements === 0) {
            $cleaned = preg_replace(
                '/\n*\$name\s*=\s*trim\(stream_get_contents\(STDIN\)\).*?echo\s+"Wrote:\s+\{\$path\}\\\\n";\s*/s',
                "\n",
                $normalized,
                1,
                $replacements
            ) ?? $normalized;
        }

        if ($replacements === 0) {
            return $code;
        }

        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned) ?? $cleaned;

        return trim($cleaned) === '<?php' ? $this->examples()['php'] : trim($cleaned);
    }

    private function ensureWorkspaceDirectory(string $relative): string
    {
        $dir = $this->workspacePath($relative);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create runner workspace.');
        }

        return $dir;
    }

    private function readWorkspaceFile(string $relative): ?string
    {
        $path = $this->workspacePath($relative);
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    private function workspaceRoot(): string
    {
        return STORAGE_PATH . '/' . self::WORKSPACE_RELATIVE;
    }

    private function executionRoot(): string
    {
        $configured = EnvironmentConfig::paths('document_roots')[0] ?? dirname(APP_ROOT);
        $root = realpath($configured) ?: realpath(dirname(APP_ROOT)) ?: dirname(APP_ROOT);

        if (!is_dir($root)) {
            throw new \RuntimeException('Runner root directory is not available.');
        }

        return $root;
    }

    private function bashWorkingDirectory(): string
    {
        $stored = $_SESSION['code_runner_bash_cwd'] ?? null;
        if (is_string($stored)) {
            $real = realpath($stored);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        return $this->executionRoot();
    }

    private function persistBashDirectory(string $cwdFile): void
    {
        if (!is_file($cwdFile) || !is_readable($cwdFile)) {
            return;
        }

        $path = $this->cleanTerminalPath((string) file_get_contents($cwdFile));
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return;
        }

        $_SESSION['code_runner_bash_cwd'] = $real;
    }

    private function workspacePath(string $relative): string
    {
        return $this->workspaceRoot() . '/' . trim($relative, '/');
    }

    private function phpBinary(): string
    {
        $configured = EnvironmentConfig::path('php_binary');
        return is_executable($configured) ? $configured : PHP_BINARY;
    }

    private function launchTerminalWorker(string $sessionDir, string $mode, ?string $scriptFile, string $cwd, string $cwdFile, int $cols, int $rows): int
    {
        $worker = APP_ROOT . '/scripts/terminal-worker.php';
        if (!is_file($worker)) {
            throw new \RuntimeException('Terminal worker is not installed.');
        }

        $log = $sessionDir . '/worker.log';
        $command = sprintf(
            'nohup %s %s %s %s %s %s %s %d %d > %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($this->phpBinary()),
            escapeshellarg($worker),
            escapeshellarg($sessionDir),
            escapeshellarg($mode),
            escapeshellarg($scriptFile ?? '-'),
            escapeshellarg($cwd),
            escapeshellarg($cwdFile),
            $cols,
            $rows,
            escapeshellarg($log)
        );

        $process = proc_open(['/bin/sh', '-c', $command], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, APP_ROOT, [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start terminal worker.');
        }

        $pid = isset($pipes[1]) ? trim((string) stream_get_contents($pipes[1])) : '';
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);

        if (!ctype_digit($pid)) {
            throw new \RuntimeException('Terminal worker did not report a process id.');
        }

        return (int) $pid;
    }

    private function requireTerminalSession(string $id): array
    {
        $id = $this->validateTerminalId($id);
        $sessionDir = $this->workspacePath('terminal/' . $id);
        if (!is_dir($sessionDir)) {
            throw new \RuntimeException('Terminal session was not found.');
        }

        $meta = $this->readJsonFile($sessionDir . '/meta.json');
        if (($meta['id'] ?? '') !== $id) {
            throw new \RuntimeException('Terminal session metadata is invalid.');
        }

        $owner = $meta['user_id'] ?? null;
        if ($owner !== null && Auth::id() !== null && (int) $owner !== Auth::id()) {
            throw new \RuntimeException('Terminal session is not available for this user.');
        }

        return [$sessionDir, $meta];
    }

    private function validateTerminalId(string $id): string
    {
        $id = strtolower(trim($id));
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new \InvalidArgumentException('Invalid terminal session id.');
        }

        return $id;
    }

    private function terminalPaths(string $sessionDir): array
    {
        return [
            'meta' => $sessionDir . '/meta.json',
            'status' => $sessionDir . '/status.json',
            'input_queue' => $sessionDir . '/input.queue',
            'output' => $sessionDir . '/output.log',
            'cwd' => $sessionDir . '/cwd.txt',
            'heartbeat' => $sessionDir . '/heartbeat',
            'stop' => $sessionDir . '/stop.request',
        ];
    }

    private function terminalIsRunning(array $meta, array $status): bool
    {
        if (($status['state'] ?? '') === 'finished' || ($status['state'] ?? '') === 'stopped') {
            return false;
        }

        $pid = (int) ($meta['pid'] ?? 0);
        return $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    private function terminalWorkingDirectory(string $cwdFile, array $meta): string
    {
        if (is_file($cwdFile) && is_readable($cwdFile)) {
            $path = $this->cleanTerminalPath((string) file_get_contents($cwdFile));
            $real = realpath($path);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        return (string) ($meta['working_directory'] ?? $this->bashWorkingDirectory());
    }

    private function persistTerminalDirectory(string $cwdFile): void
    {
        $path = $this->terminalWorkingDirectory($cwdFile, []);
        $real = realpath($path);
        if ($real !== false && is_dir($real)) {
            $_SESSION['code_runner_bash_cwd'] = $real;
        }
    }

    private function cleanTerminalPath(string $path): string
    {
        $path = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $path) ?? $path;
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path) ?? $path;

        return trim($path);
    }

    private function cleanupTerminalSessions(): void
    {
        $root = $this->workspacePath('terminal');
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            return;
        }

        $entries = scandir($root);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (!preg_match('/\A[a-f0-9]{32}\z/', $entry)) {
                continue;
            }

            $dir = $root . '/' . $entry;
            $paths = $this->terminalPaths($dir);
            $meta = $this->readJsonFile($paths['meta']);
            $status = $this->readJsonFile($paths['status']);
            $stale = (time() - (int) (@filemtime($dir) ?: time())) > 86400;
            if ($stale && !$this->terminalIsRunning($meta, $status)) {
                $this->removeTerminalDirectory($dir);
            }
        }
    }

    private function removeTerminalDirectory(string $dir): void
    {
        $root = realpath($this->workspacePath('terminal'));
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real, $root . '/')) {
            return;
        }

        $items = scandir($real);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $real . '/' . $item;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($real);
    }

    private function readJsonFile(string $path): array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write terminal session metadata.');
        }
        chmod($path, 0600);
    }

    private function clip(string $value): string
    {
        if (strlen($value) <= self::MAX_OUTPUT_BYTES) {
            return $value;
        }

        return substr($value, 0, self::MAX_OUTPUT_BYTES) . "\n[output truncated]";
    }
}
