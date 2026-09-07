<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/classes/bootstrap.php';

if (SysAdmin\DemoMode::enabled()) {
    $demo = SysAdmin\DemoMode::data();
    echo 'Demo fixtures checked; next reset: ' . $demo['reset_at'] . PHP_EOL;
    exit;
}

$pdo = SysAdmin\Database::pdo();
$taskStatement = $pdo->prepare(
    'SELECT * FROM scheduled_tasks WHERE enabled = 1 AND machine_id = ? ORDER BY id'
);
$taskStatement->execute([SysAdmin\CommercialContext::machineId()]);
$tasks = $taskStatement->fetchAll();
$now = new DateTimeImmutable('now');

foreach ($tasks as $task) {
    if (!cron_matches((string) $task['schedule'], $now)) {
        continue;
    }
    if (!empty($task['last_run_at']) && substr((string) $task['last_run_at'], 0, 16) === $now->format('Y-m-d H:i')) {
        continue;
    }
    $result = (new SysAdmin\ScriptService())->run((string) $task['script_name']);
    $stmt = $pdo->prepare(
        'UPDATE scheduled_tasks SET last_run_at = ?, updated_at = ?
         WHERE id = ? AND machine_id = ?'
    );
    $stmt->execute([
        $now->format('Y-m-d H:i:s'),
        $now->format('Y-m-d H:i:s'),
        $task['id'],
        SysAdmin\CommercialContext::machineId(),
    ]);
    echo $task['name'] . ': ' . (($result['ok'] ?? false) ? 'ok' : 'failed') . PHP_EOL;
}

function cron_matches(string $expression, DateTimeImmutable $time): bool
{
    $parts = preg_split('/\s+/', trim($expression));
    if (count($parts) !== 5) {
        return false;
    }
    $values = [
        (int) $time->format('i'),
        (int) $time->format('G'),
        (int) $time->format('j'),
        (int) $time->format('n'),
        (int) $time->format('w'),
    ];
    foreach ($parts as $i => $part) {
        if (!cron_part_matches($part, $values[$i])) {
            return false;
        }
    }
    return true;
}

function cron_part_matches(string $part, int $value): bool
{
    foreach (explode(',', $part) as $piece) {
        $piece = trim($piece);
        if ($piece === '*') {
            return true;
        }
        if (str_contains($piece, '/')) {
            [$range, $step] = explode('/', $piece, 2);
            $step = max(1, (int) $step);
            if ($range === '*' && $value % $step === 0) {
                return true;
            }
        }
        if (str_contains($piece, '-')) {
            [$start, $end] = array_map('intval', explode('-', $piece, 2));
            if ($value >= $start && $value <= $end) {
                return true;
            }
        }
        if ((string) $value === $piece) {
            return true;
        }
    }
    return false;
}
