<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use SysAdmin\IsolatedCodeRunner;
$tests = 0; $failures = []; $runner = new IsolatedCodeRunner();
$check = static function (bool $ok, string $why) use (&$tests, &$failures): void { $tests++; if (!$ok) { $failures[] = $why; } };
putenv('RUNNER_TEST_SECRET=must-not-reach-script');
$r = $runner->run('bash', <<<'BASH'
read -r name
greet() { printf '%s:%s\n' "$name" "$1"; }
for n in 1 2 3; do
  if (( n > 1 )); then greet "$n"; fi
done | sort
printf 'cwd=%s\n' "$PWD"
BASH, "sample\n");
$check($r['ok'] && $r['output'] === "sample:2\nsample:3\ncwd=/work\n", 'Multiline Bash, variables, functions, loop, condition, pipeline, input and cwd');
$check($r['isolated'] && !$r['real_files'] && $r['elapsed_ms'] > 0, 'Structured duration and isolation result');
$r = $runner->run('bash', "value=crlf\r\nfor n in 1 2; do\r\n echo \"\$value:\$n\"\r\ndone\r\n");
$check($r['ok'] && $r['output'] === "crlf:1\ncrlf:2\n", 'Browser CRLF source lines execute as ordinary Bash');
$r = $runner->run('bash', 'if then'); $check(!$r['ok'] && $r['exit_code'] === 2 && str_contains($r['error'], 'syntax'), 'Bash syntax errors visible');
$r = $runner->run('bash', 'echo out; echo err >&2; exit 7');
$check(!$r['ok'] && $r['exit_code'] === 7 && $r['output'] === "out\n" && $r['error'] === "err\n", 'Separate stdout/stderr and nonzero exit');
$r = $runner->run('bash', 'sleep 10', '', 1); $check($r['timed_out'] && $r['exit_code'] === 124 && $r['elapsed_ms'] < 4000, 'Timeout stops the isolated process namespace');
$r = $runner->run('bash', 'while :; do printf "%1000s" x; done');
$check($r['output_limited'] && !$r['ok'] && strlen($r['output']) + strlen($r['error']) <= IsolatedCodeRunner::MAX_OUTPUT, 'Output overflow stopped while streaming with bounded storage');
$r = $runner->run('bash', <<<'BASH'
set -eu
test "$(id -u)" != 0
test -z "${RUNNER_TEST_SECRET:-}"
test ! -e /opt/lampp/htdocs/clientse
test ! -e /etc/shadow
test ! -e /run
test ! -e /home
if mount -t tmpfs none /work 2>/dev/null; then exit 10; fi
if unshare -Ur true 2>/dev/null; then exit 11; fi
if sudo -n true 2>/dev/null; then exit 12; fi
if bash -c 'exec 3<>/dev/tcp/1.1.1.1/80' 2>/dev/null; then exit 13; fi
ln -s /opt/lampp/htdocs/clientse/.env /work/escape
if cat /work/escape 2>/dev/null; then exit 14; fi
echo isolated
BASH);
$check($r['ok'] && $r['output'] === "isolated\n", 'Non-root, clean environment, secret/project isolation, no privileged operations, user namespaces or network');
$check($runner->run('bash', 'echo private > local.txt; cat local.txt')['output'] === "private\n", 'Private working-directory writes supported');
$check($runner->run('bash', 'test ! -e local.txt')['ok'], 'Working-directory files do not cross runs');
$check($runner->run('bash', 'sleep 30 & echo done')['elapsed_ms'] < 3000, 'Orphan subprocesses cleaned when script ends');
$r = (new IsolatedCodeRunner(['bash' => '/missing/bash']))->run('bash', 'echo must-not-run');
$check(!$r['ok'] && $r['exit_code'] === 127 && str_contains($r['error'], 'interpreter'), 'Missing interpreter fails clearly without fallback');
$r = $runner->run('php', '<?php echo strtoupper(stream_get_contents(STDIN));', "php works\n");
$check($r['ok'] && $r['output'] === "PHP WORKS\n", 'PHP execution preserved: ' . json_encode($r));
$r = $runner->run('python', 'print(sum(range(4)))'); $check($r['ok'] && $r['output'] === "6\n", 'Python execution supported');
$r = $runner->run('javascript', 'console.log([1,2,3].map(n=>n*2).join(","))');
$check($r['ok'] && $r['output'] === "2,4,6\n", 'JavaScript execution supported');
try { $runner->run('sudo', 'id'); $check(false, 'Unsupported language rejected'); } catch (InvalidArgumentException) { $check(true, 'Unsupported language rejected'); }
try { $runner->run('bash', str_repeat('x', 65537)); $check(false, 'Oversized script rejected'); } catch (InvalidArgumentException) { $check(true, 'Oversized script rejected'); }
echo json_encode(['status' => $failures ? 'FAIL' : 'PASS', 'assertions' => $tests, 'failures' => $failures], JSON_PRETTY_PRINT) . "\n";
exit($failures ? 1 : 0);
