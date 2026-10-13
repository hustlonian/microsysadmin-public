#!/usr/bin/env php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('STORAGE_PATH', APP_ROOT . '/storage');
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\engine\Backup\BackupManager;
use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Execution\ExecutionLock;
use SysAdmin\Doctor\engine\Execution\DoctorEngine;
use SysAdmin\Doctor\engine\Filesystem\SafeFilesystem;
use SysAdmin\Doctor\engine\Logging\DoctorAuditLogger;
use SysAdmin\Doctor\engine\Logging\DoctorRepository;
use SysAdmin\Doctor\engine\Process\ProcessRunner;
use SysAdmin\Doctor\engine\Planning\PolicyEngine;
use SysAdmin\Doctor\engine\Registry\DefinitionCheck;
use SysAdmin\Doctor\engine\Registry\DefinitionDiagnosis;
use SysAdmin\Doctor\engine\Registry\DefinitionRepair;
use SysAdmin\Doctor\engine\Registry\DoctorRegistry;
use SysAdmin\Doctor\engine\Reporting\ReportBuilder;
use SysAdmin\Doctor\engine\Scoring\HealthScorer;
use SysAdmin\Doctor\engine\Security\SecretRedactor;
use SysAdmin\Doctor\engine\Support\CheckStatus;
use SysAdmin\Doctor\engine\Support\Value\BackupResult;

$tests = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$tests, &$failures): void {
    $tests++;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$expectException = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable) {
        $assert(true, $message);
    }
};

$temporary = sys_get_temp_dir() . '/microsysadmin-doctor-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0770, true);
mkdir($temporary . '/project/public', 0770, true);
mkdir($temporary . '/project/scripts', 0770, true);
mkdir($temporary . '/project/storage/app/public', 0770, true);
mkdir($temporary . '/runtime/backups', 0770, true);
mkdir($temporary . '/runtime/executions', 0770, true);
mkdir($temporary . '/runtime/reports', 0770, true);
mkdir($temporary . '/runtime/locks', 0770, true);
mkdir($temporary . '/runtime/composer-home/cache', 0770, true);

try {
    $redactor = new SecretRedactor();
    $redactor->register('known-secret-value');
    $assert($redactor->text('token known-secret-value') === 'token [REDACTED]', 'Known secrets are redacted from text.');
    $assert($redactor->value(['password' => 'abc', 'PASS' => 10]) === ['password' => '[REDACTED]', 'PASS' => 10], 'Sensitive keys are redacted without corrupting PASS status counts.');
    $assert(!str_contains(json_encode($redactor->value(['url' => 'mysql://user:secret@localhost/db'])), 'secret@'), 'Credentials embedded in URLs are redacted.');
    $assert($redactor->command(['mysql', '-psecret-value']) === ['mysql', '-p[REDACTED]'], 'Secrets attached to command options are redacted.');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE doctor_audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, execution_id TEXT, level TEXT, event_type TEXT, message TEXT, context_json TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE doctor_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, execution_id TEXT, format TEXT, report_path TEXT, content_hash TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE doctor_executions (execution_id TEXT PRIMARY KEY, mode TEXT, status TEXT, project_path TEXT, environment_json TEXT, options_json TEXT, result_json TEXT, before_score_json TEXT, after_score_json TEXT, initiated_by INTEGER, started_at TEXT, completed_at TEXT)');
    $repository = new DoctorRepository($pdo);
    $largeResult = ['execution_id' => 'doc_large_result', 'evidence' => str_repeat('repeatable Doctor evidence ', 100000)];
    $repository->beginExecution('doc_large_result', 'scan', $temporary . '/project', ['os' => 'test'], [], null);
    $repository->completeExecution('doc_large_result', 'COMPLETED', $largeResult);
    $storedLargeResult = (string) $pdo->query("SELECT result_json FROM doctor_executions WHERE execution_id = 'doc_large_result'")->fetchColumn();
    $assert(str_starts_with($storedLargeResult, 'gz64:') && strlen($storedLargeResult) < 1048576, 'Large Doctor results are compressed below conservative database packet limits.');
    $assert(($repository->execution('doc_large_result')['result_json'] ?? null) === $largeResult, 'Compressed Doctor results decode losslessly through the repository.');
    $audit = new DoctorAuditLogger($repository, $redactor, 'doc_test_execution');
    $runner = new ProcessRunner($redactor, $audit, 65536, 5);
    $filesystem = new SafeFilesystem([$temporary, sys_get_temp_dir()], [APP_ROOT]);
    $backups = new BackupManager($filesystem, $temporary . '/runtime/backups');
    $environment = new EnvironmentContext([
        'os_id' => 'ubuntu', 'os_family' => 'debian', 'php_version' => PHP_VERSION,
        'laravel_detected' => true, 'commands' => ['php' => PHP_BINARY],
    ]);
    $context = new DoctorContext('doc_test_execution', $environment, $temporary . '/project', [], $runner, $filesystem, $backups, $audit, $redactor);

    $restoreTargets = [$temporary . '/project/restore-first.txt', $temporary . '/project/restore-second.txt'];
    foreach ($restoreTargets as $path) {
        file_put_contents($path, 'original contents');
        chmod($path, 0600);
    }
    $restoreBackup = $backups->capture('doc_restore_integrity', 'TEST-9001', $restoreTargets)->toArray();
    $secondSnapshot = $restoreBackup['records'][1]['backup_path'];
    $invalidBackups = [
        'corrupt file' => static function (array &$data) use ($secondSnapshot): void {
            file_put_contents($secondSnapshot, 'corrupted contents');
        },
        'missing file' => static function (array &$data) use ($secondSnapshot): void {
            unlink($secondSnapshot);
        },
        'symbolic file' => static function (array &$data) use ($secondSnapshot, $restoreTargets): void {
            unlink($secondSnapshot);
            symlink($restoreTargets[0], $secondSnapshot);
        },
        'missing checksum' => static function (array &$data): void {
            unset($data['records'][1]['backup_sha256']);
        },
        'invalid checksum' => static function (array &$data): void {
            $data['records'][1]['backup_sha256'] = 'invalid';
        },
        'changed original checksum' => static function (array &$data): void {
            $data['records'][1]['metadata']['sha256'] = str_repeat('0', 64);
        },
        'missing snapshot reference' => static function (array &$data): void {
            $data['records'][1]['backup_path'] = null;
        },
        'invalid destination' => static function (array &$data): void {
            $data['records'][1]['metadata']['path'] = '/microsysadmin-outside-allowed-roots.txt';
        },
        'unsuccessful capture' => static function (array &$data): void {
            $data['successful'] = false;
        },
    ];
    foreach ($invalidBackups as $label => $invalidate) {
        if (is_link($secondSnapshot)) {
            unlink($secondSnapshot);
        }
        file_put_contents($secondSnapshot, 'original contents');
        foreach ($restoreTargets as $path) {
            file_put_contents($path, 'repaired contents');
            chmod($path, 0640);
        }
        $candidate = $restoreBackup;
        $invalidate($candidate);
        $expectException(static fn () => $backups->restore(new BackupResult($candidate)), "Restore rejects {$label}.");
        foreach ($restoreTargets as $path) {
            clearstatcache(true, $path);
            $assert(file_get_contents($path) === 'repaired contents' && (fileperms($path) & 0777) === 0640,
                "Restore leaves every target untouched after {$label}.");
        }
    }
    file_put_contents($secondSnapshot, 'original contents');
    $assert($backups->restore(new BackupResult($restoreBackup)), 'A persisted valid backup remains restorable.');
    foreach ($restoreTargets as $path) {
        clearstatcache(true, $path);
        $assert(file_get_contents($path) === 'original contents' && (fileperms($path) & 0777) === 0600,
            'Valid restore recovers exact bytes and captured permissions.');
    }
    $assert($backups->restore($backups->none('doc_restore_integrity', 'TEST-9002')), 'A no-backup-required result remains a valid no-op.');

    $argumentMarker = $temporary . '/must-not-exist';
    $command = $runner->run([PHP_BINARY, '-r', 'echo $argv[1];', '; touch ' . $argumentMarker], 3);
    $assert($command->successful() && str_contains((string) $command->get('stdout'), '; touch '), 'Structured command arguments are passed literally.');
    $assert(!file_exists($argumentMarker), 'Structured command arguments cannot inject a shell command.');
    $timed = $runner->run([PHP_BINARY, '-r', 'usleep(2000000);'], 1);
    $assert(!$timed->successful() && (bool) $timed->get('timed_out'), 'Process timeouts are enforced and never reported as success.');

    $composerStub = $temporary . '/composer';
    file_put_contents($composerStub, <<<'SH'
#!/bin/sh
[ "${HOME:-}" = "$1" ] &&
    [ "${COMPOSER_HOME:-}" = "$1" ] &&
    [ "${COMPOSER_CACHE_DIR:-}" = "$1/cache" ] &&
    [ "${XDG_CACHE_HOME:-}" = "$1/cache" ] &&
    [ -z "${LD_LIBRARY_PATH:-}" ]
SH);
    chmod($composerStub, 0750);
    $composerHome = $temporary . '/runtime/composer-home';
    $composerCheck = new DefinitionCheck([
        'metadata' => [
            'check_id' => 'TEST-C0001',
            'module' => 'composer',
            'name' => 'Composer isolated runtime environment',
            'summary' => 'Composer receives an isolated writable runtime environment.',
            'severity' => 'HIGH',
            'version' => '1.0.0',
        ],
        'requires_commands' => ['composer'],
        'probe' => [
            'type' => 'command',
            'command' => ['composer', $composerHome],
            'cwd' => '{PROJECT}',
            'environment' => [
                'HOME' => '/untrusted-home',
                'COMPOSER_HOME' => '/untrusted-composer-home',
                'LD_LIBRARY_PATH' => '/opt/lampp/lib',
            ],
        ],
        'failure_status' => 'FAIL',
    ]);
    $composerContext = new DoctorContext(
        'doc_test_execution',
        new EnvironmentContext(['commands' => ['composer' => $composerStub]]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner,
        $filesystem,
        $backups,
        $audit,
        $redactor,
    );
    $assert($composerCheck->run($composerContext)->status === CheckStatus::PASS, 'Composer probes use an isolated writable home and clear incompatible Apache library paths.');

    file_put_contents($composerStub, <<<'SH'
#!/bin/sh
cat <<'OUT'
Checking composer.json: OK
Checking platform settings: OK
Checking git settings: OK
Checking http connectivity to packagist: OK
Checking https connectivity to packagist: OK
Checking disk free space: OK
Could not find Composer's installed.json, this must be a non-standard Composer installation.
OUT
echo "Do not run Composer as root/super user!" >&2
exit 1
SH);
    chmod($composerStub, 0750);
    $composerSelfCheckDefinition = json_decode(
        (string) file_get_contents(APP_ROOT . '/doctor/modules/composer/Checks/COMPOSER-0009-ComposerSelfCheckFailure.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $assert((new DefinitionCheck($composerSelfCheckDefinition))->run($composerContext)->status === CheckStatus::PASS,
        'Composer self-check accepts the reviewed root-session advisory only when every exact diagnostic checkpoint passes.');

    file_put_contents($temporary . '/project/scripts/backup_database.php', "<?php\n");
    $crontabState = $temporary . '/daemon-crontab';
    $crontabStub = $temporary . '/crontab';
    file_put_contents($crontabStub, "#!/bin/sh\nstate=" . escapeshellarg($crontabState) . <<<'SH'

case "${1:-}" in
    -l)
        if [ -f "$state" ]; then cat "$state"; exit 0; fi
        echo "no crontab for daemon" >&2
        exit 1
        ;;
    -r)
        [ ! -f "$state" ] || rm -f -- "$state"
        exit 0
        ;;
    *)
        cp -- "$1" "$state"
        ;;
esac
SH);
    chmod($crontabStub, 0750);
    $backupRepairDefinition = json_decode(
        (string) file_get_contents(APP_ROOT . '/doctor/modules/backups/Repairs/BACKUP-0009-DatabaseBackupScheduleMissing.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $backupRepair = new DefinitionRepair($backupRepairDefinition);
    $assert($backupRepair->metadata()->get('risk_level') === 'LOW'
        && $backupRepair->metadata()->get('default_execution_policy') === 'safe-with-backup',
        'Backup schedule repair is classified for zero-interaction safe-with-backup execution.');
    $backupContext = new DoctorContext(
        'doc_test_execution',
        new EnvironmentContext(['commands' => ['crontab' => $crontabStub, 'xampp-php' => PHP_BINARY]]),
        $temporary . '/project',
        ['doctor_runtime_root' => $temporary . '/runtime'],
        $runner,
        $filesystem,
        $backups,
        $audit,
        $redactor,
    );
    $backupDetection = $backupRepair->detect($backupContext);
    $backupPlan = $backupRepair->plan($backupContext, $backupDetection);
    $backupRepair->backup($backupContext, $backupPlan);
    $backupResult = $backupRepair->repair($backupContext, $backupPlan);
    $assert($backupResult->successful() && str_contains((string) file_get_contents($crontabState), '# MicroSysAdmin Doctor BACKUP-0009'), 'Backup schedule repair preserves and installs one exact marked crontab entry.');
    $assert($backupRepair->verify($backupContext, $backupResult)->passed(), 'Backup schedule repair passes independent crontab verification.');
    $assert($backupRepair->rollback($backupContext, $backupResult)->successful() && !file_exists($crontabState), 'Backup schedule rollback restores the prior absence of a service-account crontab.');

    $expectException(static fn () => $filesystem->atomicWrite('/etc/microsysadmin-doctor-test', 'no'), 'Writes outside configured roots are rejected.');
    $filesystem->atomicWrite($temporary . '/atomic.txt', "before\n", 0640);
    $filesystem->atomicWrite($temporary . '/atomic.txt', "after\n", 0640);
    $assert(file_get_contents($temporary . '/atomic.txt') === "after\n", 'Atomic writes install exactly the requested content.');

    $lockA = new ExecutionLock($temporary . '/runtime/locks/execution.lock');
    $lockB = new ExecutionLock($temporary . '/runtime/locks/execution.lock');
    $lockA->acquire(0);
    $expectException(static fn () => $lockB->acquire(0), 'Concurrent Doctor execution is rejected by the global lock.');
    $lockA->release();
    $lockB->acquire(0);
    $lockB->release();
    $assert(true, 'Execution lock is reusable after release.');

    $registry = new DoctorRegistry(APP_ROOT . '/doctor/modules');
    $registry->discover();
    $summary = $registry->summary();
    $assert(($summary['modules'] ?? 0) >= 21
        && ($summary['checks'] ?? 0) >= 1224
        && ($summary['diagnoses'] ?? 0) >= 1224
        && ($summary['repairs'] ?? 0) >= 1224
        && ($summary['inventory_entries'] ?? 0) >= 1677,
        'Automatic registry discovery loads the original modules plus the complete generated artifact-integrity cohort.');
    $repairIds = [];
    $fullyAutomated = 0;
    foreach ($registry->repairs() as $repair) {
        $metadata = $repair->metadata()->toArray();
        $id = (string) $metadata['repair_id'];
        $assert(!isset($repairIds[$id]), "Permanent repair ID {$id} is unique.");
        $repairIds[$id] = true;
        $assert(is_file(APP_ROOT . '/doctor/' . $metadata['documentation_reference']), "Repair {$id} documentation exists.");
        $fixture = APP_ROOT . '/doctor/modules/' . $metadata['module'] . '/Fixtures/' . $id . '.json';
        $assert(is_file($fixture) && is_array(json_decode((string) file_get_contents($fixture), true)), "Repair {$id} fixture is valid.");
        $source = glob(APP_ROOT . '/doctor/modules/' . $metadata['module'] . '/Repairs/' . $id . '-*.json') ?: [];
        $assert(count($source) === 1, "Repair {$id} has one independent implementation unit.");
        $raw = (string) file_get_contents($source[0]);
        $definition = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $automaticClass = (string) ($definition['metadata']['automation_class'] ?? 'REPORT_ONLY');
        if (in_array($automaticClass, ['AUTO_SAFE', 'AUTO_WITH_BACKUP'], true)) {
            $fullyAutomated++;
        }
        $assert(in_array($automaticClass, ['REPORT_ONLY', 'PROHIBITED'], true) || (array) ($definition['actions'] ?? []) !== [], "Executable repair {$id} has at least one concrete action.");
        foreach ((array) ($definition['actions'] ?? []) as $action) {
            $assert(($action['type'] ?? '') !== 'run_command' || is_array($action['command'] ?? null), "Repair {$id} commands are structured argument arrays.");
            $assert(($action['type'] ?? '') !== 'service_action'
                || (isset(\SysAdmin\ServiceControlManager::LABELS[(string) ($action['service'] ?? '')])
                    && in_array((string) ($action['action'] ?? ''), ['start', 'stop', 'enable', 'disable'], true)),
                "Repair {$id} service actions use the scoped service-control allowlist.");
            $assert(($action['type'] ?? '') !== 'doctor_action'
                || (in_array((string) ($action['action'] ?? ''), ['enable-opcache', 'renew-local-tls'], true)
                    && in_array((string) ($action['path'] ?? ''), ['/opt/lampp/etc/php.ini', '/opt/lampp/etc/ssl.crt/server.crt'], true)
                    && $automaticClass === 'AUTO_WITH_BACKUP'),
                "Repair {$id} privileged Doctor actions are exact, allowlisted, and backup-controlled.");
            $assert(($action['type'] ?? '') !== 'restore_managed_artifact'
                || ($automaticClass === 'AUTO_WITH_BACKUP'
                    && preg_match('/^ARTIFACT-[0-9]{4}$/', (string) ($action['repair_id'] ?? ''))
                    && preg_match('/^[a-f0-9]{64}$/', (string) ($action['sha256'] ?? ''))
                    && str_starts_with((string) ($action['path'] ?? ''), '{APP_ROOT}/')
                    && str_starts_with((string) ($action['baseline'] ?? ''), '{APP_ROOT}/doctor/baselines/release-artifacts/blobs/')),
                "Repair {$id} managed-artifact action is exact, local-only, and backup-controlled.");
            $commandText = implode(' ', array_map('strval', (array) ($action['command'] ?? [])));
            $assert(!preg_match('/(?:^|\s)composer\s+update(?:\s|$)|reset\s+--hard|chmod\s+-R\s+0?777|drop\s+database/i', $commandText), "Repair {$id} executes no prohibited generic destructive command.");
        }
    }
    $artifactManifest = json_decode((string) file_get_contents(APP_ROOT . '/doctor/baselines/release-artifacts/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert($fullyAutomated >= (int) ($artifactManifest['repair_count'] ?? PHP_INT_MAX), 'The live registry contains every generated file and cause-specific directory repair as fully automated.');

    foreach (['BACKUP-0009', 'COMPOSER-0009', 'REDIS-0007', 'SSL-0008'] as $advisoryId) {
        $advisoryPath = glob(APP_ROOT . '/doctor/modules/*/Checks/' . $advisoryId . '-*.json')[0] ?? '';
        $advisory = $advisoryPath !== '' ? json_decode((string) file_get_contents($advisoryPath), true, 512, JSON_THROW_ON_ERROR) : [];
        $assert(($advisory['failure_status'] ?? '') === 'WARNING', "Optional advisory {$advisoryId} cannot create a hard failure.");
    }

    foreach (['APACHE-0002', 'MYSQL-0002', 'XAMPP-0006'] as $serviceRepairId) {
        $serviceDefinitionPath = glob(APP_ROOT . '/doctor/modules/*/Repairs/' . $serviceRepairId . '-*.json')[0] ?? '';
        $serviceDefinition = $serviceDefinitionPath !== ''
            ? json_decode((string) file_get_contents($serviceDefinitionPath), true, 512, JSON_THROW_ON_ERROR)
            : [];
        $assert(($serviceDefinition['detection']['type'] ?? '') === 'service_active', "Repair {$serviceRepairId} uses permission-safe process inspection.");
        $assert(($serviceDefinition['actions'][0]['type'] ?? '') === 'service_action', "Repair {$serviceRepairId} uses the privileged scoped service helper.");
    }

    $repairPath = glob(APP_ROOT . '/doctor/modules/laravel/Repairs/LARAVEL-0004-*.json')[0];
    $checkPath = glob(APP_ROOT . '/doctor/modules/laravel/Checks/LARAVEL-0004-*.json')[0];
    $diagnosisPath = glob(APP_ROOT . '/doctor/modules/laravel/Diagnoses/LARAVEL-0004-*.json')[0];
    $repair = new DefinitionRepair(json_decode((string) file_get_contents($repairPath), true, 512, JSON_THROW_ON_ERROR));
    $check = new DefinitionCheck(json_decode((string) file_get_contents($checkPath), true, 512, JSON_THROW_ON_ERROR));
    $diagnosis = new DefinitionDiagnosis(json_decode((string) file_get_contents($diagnosisPath), true, 512, JSON_THROW_ON_ERROR));
    $healthyFixture = $check->run($context);
    $assert($healthyFixture->status === CheckStatus::PASS, 'Healthy fixture is not falsely marked broken.');
    rmdir($temporary . '/project/storage/app/public');
    rmdir($temporary . '/project/storage/app');
    rmdir($temporary . '/project/storage');
    $failure = $check->run($context);
    $assert($failure->status === CheckStatus::FAIL && $failure->evidence !== [], 'Intended isolated failure is detected with evidence.');
    $diagnosed = $diagnosis->diagnose($context, $failure);
    $assert((bool) $diagnosed->get('condition_detected') && in_array('LARAVEL-0004', $diagnosed->repairIds(), true), 'Diagnosis identifies the specific cause and related repair.');
    $detection = $repair->detect($context);
    $assert($detection->detected(), 'Repair detection confirms the failure independently.');
    $plan = $repair->plan($context, $detection);
    $assert($plan->actions() !== [] && $plan->get('affected_paths') !== [], 'Repair plan previews actions and affected paths.');
    $backup = $repair->backup($context, $plan);
    $assert($backup->successful(), 'Backup/recovery capture succeeds when required or records none-required honestly.');
    $result = $repair->repair($context, $plan);
    $assert($result->successful() && is_dir($temporary . '/project/storage'), 'Repair corrects only the isolated missing directory.');
    $verification = $repair->verify($context, $result);
    $assert($verification->passed(), 'Independent verification passes only after correction.');
    $repeat = $repair->repair($context, $plan);
    $assert($repeat->successful() && is_dir($temporary . '/project/storage'), 'Repeated repair execution is idempotent and harmless.');
    $rollback = $repair->rollback($context, $result);
    $assert($rollback->successful() && !is_dir($temporary . '/project/storage'), 'Supported rollback restores the prior missing-directory state.');
    $assert(!$repair->verify($context, $result)->passed(), 'Verification fails again after rollback restores the prior failure.');

    $engine = new DoctorEngine($registry, new PolicyEngine(require APP_ROOT . '/doctor/config/policies.php'), new HealthScorer(), new ExecutionLock($temporary . '/runtime/locks/engine.lock'));
    $planOne = $engine->plans($context, [$diagnosed], 'manual', ['LARAVEL-0004']);
    usleep(2000);
    $planTwo = $engine->plans($context, [$diagnosed], 'manual', ['LARAVEL-0004']);
    $assert(($planOne[0]['plan_hash'] ?? '') === ($planTwo[0]['plan_hash'] ?? '') && strlen((string) ($planOne[0]['plan_hash'] ?? '')) === 64, 'Exact plan hashes are deterministic across volatile evidence timestamps.');
    $skipped = $engine->execute($context, $planOne, static fn (): bool => false);
    $assert(($skipped[0]['repair']->get('status') ?? '') === 'SKIPPED' && !is_dir($temporary . '/project/storage'), 'An unapproved manual-policy repair is skipped without mutation.');
    $executed = $engine->execute($context, $planOne, static fn (): bool => true);
    $assert(($executed[0]['repair']->get('status') ?? '') === 'VERIFIED' && !empty($executed[0]['verification']?->passed()), 'An approved repair is reported VERIFIED only after independent verification.');

    $unsupported = new DoctorContext('doc_test_execution', new EnvironmentContext(['laravel_detected' => false, 'commands' => []]), $temporary . '/project', [], $runner, $filesystem, $backups, $audit, $redactor);
    $assert($check->run($unsupported)->status === CheckStatus::SKIPPED, 'Unsupported environments are skipped safely.');
    $assert($engine->scan($unsupported, ['laravel'], ['LARAVEL-C0004']) === [], 'Unsupported checks are excluded from the active execution result instead of appearing as unresolved skips.');

    $score = (new HealthScorer())->calculate([$healthyFixture, $failure]);
    $assert($score->percentage() < 100 && isset($score->toArray()['modules']['laravel']), 'Health scoring weights pass/fail results by module.');
    $seededScore = (new HealthScorer(['laravel', 'nginx']))->calculate([$healthyFixture]);
    $assert(($seededScore->toArray()['modules']['nginx']['percentage'] ?? null) === 100.0, 'Known modules with no applicable checks remain visible at 100 percent without synthetic skipped results.');
    $reporter = new ReportBuilder($filesystem, $redactor, $repository, $temporary . '/runtime/reports');
    foreach (['json', 'text', 'html', 'pdf-html'] as $format) {
        $report = $reporter->generate('doc_test_execution', ['execution_id' => 'doc_test_execution', 'checks' => [$failure->jsonSerialize()], 'before_health_score' => $score->toArray()], $format);
        $assert(is_file((string) $report->get('path')) && hash_file('sha256', (string) $report->get('path')) === $report->get('sha256'), strtoupper($format) . ' report is generated and hash-verified.');
    }

    $auditRows = $repository->auditEvents('doc_test_execution');
    $assert($auditRows !== [] && !str_contains(json_encode($auditRows), 'known-secret-value'), 'Sanitized audit events are persisted without registered secrets.');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());
        } elseif ($entry->isDir()) {
            rmdir($entry->getPathname());
        }
    }
    if (is_dir($temporary)) {
        rmdir($temporary);
    }
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("Doctor tests: %d assertions, %d failures.\n", $tests, count($failures)));
    exit(1);
}

echo sprintf("Doctor tests: %d assertions, 0 failures.\n", $tests);
