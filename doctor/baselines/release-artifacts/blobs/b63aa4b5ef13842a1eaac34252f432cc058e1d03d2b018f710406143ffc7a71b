<?php

declare(strict_types=1);

const TERMINAL_IDLE_SECONDS = 900;

if ($argc < 8) {
    fwrite(STDERR, "Usage: terminal-worker.php <session-dir> <mode> <script-file> <cwd> <cwd-file> <cols> <rows>\n");
    exit(2);
}

$sessionDir = $argv[1];
$mode = $argv[2];
$scriptFile = $argv[3];
$cwd = $argv[4];
$cwdFile = $argv[5];
$cols = max(40, min(240, (int) $argv[6]));
$rows = max(12, min(80, (int) $argv[7]));

$paths = [
    'status' => $sessionDir . '/status.json',
    'input_queue' => $sessionDir . '/input.queue',
    'output' => $sessionDir . '/output.log',
    'heartbeat' => $sessionDir . '/heartbeat',
    'stop' => $sessionDir . '/stop.request',
];

$writeStatus = static function (array $state) use ($paths): void {
    $state['updated_at'] = time();
    file_put_contents($paths['status'], json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
};

$filteredInitialClear = false;
$appendOutput = static function (string $chunk) use ($paths, &$filteredInitialClear): void {
    if ($chunk !== '') {
        $chunk = preg_replace([
            '/bash: cannot set terminal process group \([^)]+\): Inappropriate ioctl for device\r?\n?/',
            '/bash: no job control in this shell\r?\n?/',
        ], '', $chunk) ?? $chunk;

        if (!$filteredInitialClear) {
            $chunk = preg_replace('/\x1B\[H\x1B\[2J\x1B\[3J/', '', $chunk, 1, $replacements) ?? $chunk;
            $filteredInitialClear = ($replacements ?? 0) > 0;
        }
    }

    if ($chunk !== '') {
        file_put_contents($paths['output'], $chunk, FILE_APPEND | LOCK_EX);
    }
};

$readQueuedInput = static function (int &$offset) use ($paths): array {
    if (!is_file($paths['input_queue'])) {
        return [];
    }

    $size = (int) filesize($paths['input_queue']);
    if ($size <= $offset) {
        return [];
    }

    $handle = fopen($paths['input_queue'], 'rb');
    if ($handle === false) {
        return [];
    }

    flock($handle, LOCK_SH);
    fseek($handle, $offset);
    $chunks = [];
    while (($line = fgets($handle)) !== false) {
        $offset = (int) ftell($handle);
        $decoded = base64_decode(trim($line), true);
        if ($decoded !== false) {
            $chunks[] = $decoded;
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    return $chunks;
};

$fail = static function (string $message, int $exitCode = 1) use ($appendOutput, $writeStatus): never {
    $appendOutput($message . "\r\n");
    $writeStatus([
        'state' => 'failed',
        'exit_code' => $exitCode,
        'message' => $message,
    ]);
    exit($exitCode);
};

if (!function_exists('proc_open')) {
    $fail('proc_open is not available.');
}

if (!in_array($mode, ['shell', 'script'], true)) {
    $fail('Terminal mode is invalid.');
}

if (!is_dir($sessionDir) || !is_dir($cwd) || ($mode === 'script' && !is_file($scriptFile))) {
    $fail('Terminal session paths are not available.');
}

$rcFile = $sessionDir . '/bashrc';
if ($mode === 'shell') {
    $rc = <<<'BASH'
export PS1='microsysadmin:\w\$ '
export PROMPT_COMMAND='pwd > "$SYSADMIN_CWD_FILE" 2>/dev/null'
trap 'pwd > "$SYSADMIN_CWD_FILE" 2>/dev/null' EXIT
pwd > "$SYSADMIN_CWD_FILE" 2>/dev/null
BASH;
    if (file_put_contents($rcFile, $rc . "\n") === false) {
        $fail('Unable to prepare terminal shell.');
    }
    chmod($rcFile, 0600);
}

$command = $mode === 'script'
    ? [
        'bash',
        '--noprofile',
        '--norc',
        '-c',
        'stty rows "$3" cols "$4" 2>/dev/null || true; trap \'status=$?; pwd > "$2" 2>/dev/null; exit $status\' EXIT; source "$1"',
        'sysadmin-bash-runner',
        $scriptFile,
        $cwdFile,
        (string) $rows,
        (string) $cols,
    ]
    : [
        'bash',
        '--noprofile',
        '--norc',
        '-c',
        'stty rows "$2" cols "$3" 2>/dev/null || true; exec bash --noprofile --rcfile "$1" -i',
        'sysadmin-bash-terminal',
        $rcFile,
        (string) $rows,
        (string) $cols,
    ];

$process = proc_open($command, [
    0 => ['pty'],
    1 => ['pty'],
    2 => ['pty'],
], $pipes, $cwd, [
    'HOME' => dirname(dirname($sessionDir)),
    'TMPDIR' => dirname($sessionDir),
    'TERM' => 'xterm-256color',
    'COLORTERM' => 'truecolor',
    'COLUMNS' => (string) $cols,
    'LINES' => (string) $rows,
    'SYSADMIN_CWD_FILE' => $cwdFile,
    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
]);

if (!is_resource($process)) {
    $fail('Unable to start terminal process.');
}

foreach ($pipes as $pipe) {
    stream_set_blocking($pipe, false);
}

$status = proc_get_status($process);
$writeStatus([
    'state' => 'running',
    'exit_code' => null,
    'child_pid' => $status['pid'] ?? null,
]);

$inputOffset = 0;
$stopping = false;
$stopStarted = null;

while (true) {
    foreach ([1, 2] as $index) {
        if (!isset($pipes[$index]) || !is_resource($pipes[$index])) {
            continue;
        }

        while (($chunk = @fread($pipes[$index], 8192)) !== false && $chunk !== '') {
            $appendOutput($chunk);
        }
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        foreach ($readQueuedInput($inputOffset) as $input) {
            @fwrite($pipes[0], $input);
        }
    }

    clearstatcache(true, $paths['heartbeat']);
    $heartbeat = (int) (@filemtime($paths['heartbeat']) ?: time());
    if (!$stopping && (time() - $heartbeat) > TERMINAL_IDLE_SECONDS) {
        $appendOutput("\r\n[terminal idle timeout]\r\n");
        @proc_terminate($process, SIGTERM);
        $stopping = true;
        $stopStarted = microtime(true);
    }

    if (!$stopping && is_file($paths['stop'])) {
        @proc_terminate($process, SIGTERM);
        $stopping = true;
        $stopStarted = microtime(true);
    }

    $status = proc_get_status($process);
    if (!$status['running']) {
        $exitCode = $status['exitcode'];
        break;
    }

    if ($stopping && $stopStarted !== null && (microtime(true) - $stopStarted) > 2.0) {
        @proc_terminate($process, SIGKILL);
    }

    usleep(20000);
}

for ($i = 0; $i < 10; $i++) {
    foreach ([1, 2] as $index) {
        if (!isset($pipes[$index]) || !is_resource($pipes[$index])) {
            continue;
        }
        while (($chunk = @fread($pipes[$index], 8192)) !== false && $chunk !== '') {
            $appendOutput($chunk);
        }
    }
    usleep(20000);
}

foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}

$closedExit = proc_close($process);
if (!is_int($exitCode) || $exitCode < 0) {
    $exitCode = $stopping ? 143 : (is_int($closedExit) ? $closedExit : 0);
}

$writeStatus([
    'state' => $stopping ? 'stopped' : 'finished',
    'exit_code' => $exitCode,
]);

exit($exitCode);
