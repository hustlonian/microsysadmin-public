<?php

declare(strict_types=1);

// Fail before bootstrap: it can migrate a configured database.
define('APP_ROOT', dirname(__DIR__));
$configuration = require APP_ROOT . '/config/config.php';
if (!preg_match('~^/tmp/msa-job-test-[^/]+/app$~', APP_ROOT)
    || ($configuration['database']['name'] ?? '') !== 'microsysadmin_job_test_isolated'
    || ($configuration['database']['socket'] ?? '') !== dirname(APP_ROOT) . '/mysql.sock') {
    throw new RuntimeException('Run through tests/job-recovery-isolated.py with generated configuration.');
}
require APP_ROOT . '/classes/bootstrap.php';

use Symfony\Component\Process\Process;
use SysAdmin\Database;
use SysAdmin\EnvironmentConfig;
use SysAdmin\JobQueue;
use SysAdmin\JobWorker;

$queue = new JobQueue();
if (($argv[1] ?? '') === '--abandon') {
    exit($queue->beginExecution($argv[2], (int) $argv[3]) === null ? 2 : 0);
}

$passed = 0;
$failed = 0;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try {
        $callback();
        $passed++;
        echo "[PASS] {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "[FAIL] {$name}: {$exception->getMessage()}\n";
    }
};
$script = static function (string $name, string $body): void {
    file_put_contents(EnvironmentConfig::path('script_root') . '/' . $name . '.sh', "#!/bin/bash\nset -eu\n" . $body . "\n");
};
$worker = static function (array $args = ['--once']): Process {
    $process = new Process([PHP_BINARY, APP_ROOT . '/scripts/job-worker.php', ...$args], APP_ROOT);
    $process->setTimeout(12);
    $process->start();
    return $process;
};
$waitFor = static function (callable $condition) use ($assert): void {
    $deadline = microtime(true) + 5;
    while (!$condition() && microtime(true) < $deadline) {
        usleep(20000);
    }
    $assert($condition(), 'Timed out waiting for fixture execution.');
};

$test('successful job runs exactly once with durable completion', static function () use ($queue, $script, $assert): void {
    $marker = STORAGE_PATH . '/success';
    $script('success', 'echo effect >> ' . escapeshellarg($marker));
    $job = $queue->dispatch('script.run', ['script' => 'success.sh'], null, 10, 3);
    $assert((new JobWorker())->runOnce(), 'No job reserved.');
    $saved = $queue->find($job['uuid']);
    $assert($saved['status'] === 'completed' && (int) $saved['progress'] === 100, 'Completion was not persisted.');
    $assert(!(new JobWorker())->runOnce(), 'Completed job ran twice.');
    $assert(file_get_contents($marker) === "effect\n", 'Effect duplicated.');
});

$test('completed health inspection preserves degraded findings without retry', static function () use ($queue, $assert): void {
    $configPath = APP_ROOT . '/config/config.php';
    $original = file_get_contents($configPath);
    $config = require $configPath;
    $config['environment']['composer_binary'] = APP_ROOT . '/missing-fixture-composer';
    file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
    try {
        $job = $queue->dispatch('maintenance.health-check', [], null, 30, 1);
        $assert((new JobWorker())->runOnce(), 'Health inspection was not reserved.');
        $saved = $queue->find($job['uuid']);
        $assert($saved['status'] === 'completed' && (int) $saved['progress'] === 100, 'Completed inspection was treated as an unknown effect.');
        $report = json_decode($saved['output_summary'], true, 512, JSON_THROW_ON_ERROR);
        $assert($report['ok'] === false && $report['status'] === 'degraded' && $report['failed'] > 0, 'Unhealthy findings were hidden.');
        $assert(!(new JobWorker())->runOnce(), 'Completed health inspection was retried.');
        $assert($queue->find($job['uuid'])['attempts'] === 1, 'Health inspection attempt changed.');
    } finally {
        file_put_contents($configPath, $original);
    }
});

$test('unsuccessful handler with an effect is held without retry', static function () use ($queue, $script, $assert): void {
    $marker = STORAGE_PATH . '/failed-effect';
    $script('failure', 'echo effect >> ' . escapeshellarg($marker) . "\nexit 1");
    $job = $queue->dispatch('script.run', ['script' => 'failure.sh'], null, 10, 3);
    (new JobWorker())->runOnce();
    $assert($queue->find($job['uuid'])['status'] === 'needs_reconciliation', 'Failure silently retried or passed.');
    $assert(!(new JobWorker())->runOnce(), 'Unknown effect retried.');
    $assert(file_get_contents($marker) === "effect\n", 'Failed effect duplicated.');
});

$test('deadline stops nested commands before their late effect', static function () use ($queue, $script, $assert): void {
    $marker = STORAGE_PATH . '/late-effect';
    $script('timeout', "trap '' TERM\n(sleep 3; echo late > " . escapeshellarg($marker) . ") &\nwait");
    $job = $queue->dispatch('script.run', ['script' => 'timeout.sh'], null, 1, 3);
    $started = microtime(true);
    (new JobWorker())->runOnce();
    $elapsed = microtime(true) - $started;
    $saved = $queue->find($job['uuid']);
    $assert($saved['status'] === 'needs_reconciliation', 'Timed-out job was not held.');
    $assert($elapsed < 3, 'Supervisor exceeded its bounded stop interval.');
    usleep(3300000);
    $assert(!file_exists($marker), 'A descendant survived the job timeout and performed a late effect.');
    $assert(!(new JobWorker())->runOnce(), 'Timed-out job was retried.');
});

$test('concurrent executors and reconciler preserve one live owner', static function () use ($queue, $script, $assert, $worker, $waitFor): void {
    $marker = STORAGE_PATH . '/concurrent';
    $script('concurrent', 'echo started >> ' . escapeshellarg($marker) . "\nsleep 1\necho finished >> " . escapeshellarg($marker));
    $job = $queue->dispatch('script.run', ['script' => 'concurrent.sh'], null, 10, 1);
    $reservation = $queue->reserve();
    $assert($reservation['uuid'] === $job['uuid'], 'Wrong reservation.');
    $first = $worker(['--execute', $job['uuid'], '1']);
    try {
        $waitFor(static fn (): bool => file_exists($marker));
        $second = $worker(['--execute', $job['uuid'], '1']);
        $assert($second->wait() === 2, 'Second executor acquired the same attempt.');
        $queue->reconcile();
        $assert($queue->find($job['uuid'])['status'] === 'running', 'Active lock was treated as abandoned.');
        $assert($first->wait() === 0, 'First executor failed.');
        $assert(file_get_contents($marker) === "started\nfinished\n", 'Concurrent effect duplicated.');
    } finally {
        $first->stop(0);
    }
});

$test('timeout survives supervisor death and preserves uncertain effects', static function () use ($queue, $script, $assert, $worker, $waitFor): void {
    $started = STORAGE_PATH . '/orphan-started';
    $late = STORAGE_PATH . '/orphan-late';
    $script('orphan', 'echo started > ' . escapeshellarg($started) . "\ntrap '' TERM\n(sleep 4; echo late > " . escapeshellarg($late) . ") &\nwait");
    $job = $queue->dispatch('script.run', ['script' => 'orphan.sh'], null, 2, 3);
    $supervisor = $worker();
    try {
        $waitFor(static fn (): bool => file_exists($started));
        $supervisor->signal(9);
        try {
            $supervisor->wait();
        } catch (\Symfony\Component\Process\Exception\ProcessSignaledException) {
            // This deliberate interruption is the condition under test.
        }
        usleep(4300000);
        $queue->reconcile();
        $assert(!file_exists($late), 'Nested command escaped its deadline after supervisor death.');
        $assert($queue->find($job['uuid'])['status'] === 'needs_reconciliation', 'Interrupted job not held.');
        $assert(!(new JobWorker())->runOnce(), 'Interrupted effect retried.');
    } finally {
        $supervisor->stop(0);
    }
});

$test('stale attempts cannot complete, fail, or overwrite progress', static function () use ($queue, $assert): void {
    $job = $queue->dispatch('maintenance.health-check', [], null, 30, 3);
    $queue->reserve();
    $queue->beginExecution($job['uuid'], 1);
    try {
        $queue->progress($job['uuid'], 1, 42, 'current');
        $queue->progress($job['uuid'], 2, 90, 'stale');
        $assert(!$queue->complete($job['uuid'], 2, 'stale'), 'Stale completion accepted.');
        $queue->fail($job['uuid'], 2, new RuntimeException('stale'));
        $saved = $queue->find($job['uuid']);
        $assert($saved['status'] === 'running' && (int) $saved['progress'] === 42 && $saved['output_summary'] === 'current', 'Stale attempt altered current state.');
        $assert($queue->complete($job['uuid'], 1, 'verified'), 'Current owner could not complete.');
        $queue->requireReconciliation($job['uuid'], 1, 'worker_interrupted');
        $assert($queue->find($job['uuid'])['status'] === 'completed', 'Reconciliation overwrote success.');
    } finally {
        $queue->releaseExecutionLock($job['uuid']);
    }
});

$test('dead executor is reconciled without replay', static function () use ($queue, $assert): void {
    $job = $queue->dispatch('maintenance.health-check', [], null, 60, 3);
    $queue->reserve();
    $process = new Process([PHP_BINARY, __FILE__, '--abandon', $job['uuid'], '1']);
    $assert($process->run() === 0, 'Abandonment fixture did not claim the job.');
    $queue->reconcile();
    $saved = $queue->find($job['uuid']);
    $assert($saved['status'] === 'needs_reconciliation' && str_starts_with($saved['error_message'], 'worker_interrupted:'), 'Dead executor was not reconciled.');
    $assert(!(new JobWorker())->runOnce(), 'Abandoned job was replayed.');
});

$test('expired reservation and late completion are fenced', static function () use ($queue, $assert): void {
    $job = $queue->dispatch('maintenance.health-check', [], null, 10, 3);
    $queue->reserve();
    Database::pdo()->prepare('UPDATE jobs SET reserved_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 20 SECOND) WHERE uuid = ?')->execute([$job['uuid']]);
    $assert($queue->beginExecution($job['uuid'], 1) === null, 'Expired reservation executed.');
    $queue->reconcile();
    $assert($queue->find($job['uuid'])['status'] === 'needs_reconciliation', 'Expired reservation remained pending.');
    $assert(!$queue->complete($job['uuid'], 1, 'late'), 'Late completion overwrote uncertainty.');
});

$test('legacy retries cannot run or conceal uncertainty through cancellation', static function () use ($queue, $assert): void {
    $job = $queue->dispatch('maintenance.health-check', [], null, 10, 3);
    Database::pdo()->prepare('UPDATE jobs SET attempts = 1 WHERE uuid = ?')->execute([$job['uuid']]);
    $assert($queue->reserve() === null, 'Legacy retry was reserved.');
    $queue->cancel($job['uuid']);
    $queue->reconcile();
    $assert($queue->find($job['uuid'])['status'] === 'needs_reconciliation', 'Cancellation concealed an uncertain prior attempt.');
});

$test('queued cancellation and bounds remain enforced', static function () use ($queue, $assert): void {
    $job = $queue->dispatch('maintenance.health-check', [], null, 10, 1);
    $queue->cancel($job['uuid']);
    $assert($queue->find($job['uuid'])['status'] === 'cancelled', 'Queued cancellation failed.');
    $assert($queue->reserve() === null, 'Cancelled job reserved.');
    foreach ([[0, 1], [1, 0], [1, 65536]] as [$timeout, $attempts]) {
        try {
            $queue->dispatch('maintenance.health-check', [], null, $timeout, $attempts);
            $assert(false, 'Invalid bounds accepted.');
        } catch (InvalidArgumentException) {
            $assert(true, 'Invalid bounds rejected.');
        }
    }
});

$test('review UI explains uncertainty and escapes handler data', static function () use ($assert): void {
    $title = 'Jobs';
    $mode = 'jobs';
    $organization = ['name' => 'Fixture'];
    $jobs = [['uuid' => 'fixture', 'type' => 'script.run', 'status' => 'needs_reconciliation',
        'progress' => 5, 'attempts' => 1, 'max_attempts' => 3, 'owned_by' => 1,
        'created_at' => '2026-01-01 00:00:00', 'error_message' => '<script>unsafe</script>']];
    ob_start();
    require APP_ROOT . '/views/pages/administration.php';
    $html = (string) ob_get_clean();
    $assert(str_contains($html, 'Review required'), 'Uncertainty label missing.');
    $assert(str_contains($html, '&lt;script&gt;unsafe&lt;/script&gt;') && !str_contains($html, '<script>unsafe'), 'Unsafe handler text rendered as HTML.');
    $assert(!str_contains($html, 'job-cancel'), 'Uncertain job offered cancellation.');
});

// A synthetic owner also lets the existing application token tests run afterward.
if ((int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    Database::pdo()->prepare("INSERT INTO users (username,email,password_hash,role,created_at,updated_at) VALUES (?,?,?,'Administrator',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        ->execute(['fixture-owner', 'owner@example.test', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
}
echo json_encode(['tests' => $passed + $failed, 'passed' => $passed, 'failed' => $failed, 'assertions' => $assertions], JSON_THROW_ON_ERROR) . "\n";
exit($failed === 0 ? 0 : 1);
