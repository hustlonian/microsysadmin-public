<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('STORAGE_PATH', sys_get_temp_dir() . '/doctor-rollback-unused');
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\engine\Backup\BackupManager;
use SysAdmin\Doctor\engine\Context\{DoctorContext, EnvironmentContext};
use SysAdmin\Doctor\engine\Contracts\RecoveryVerifiableRepair;
use SysAdmin\Doctor\engine\Execution\{DoctorEngine, ExecutionLock};
use SysAdmin\Doctor\engine\Filesystem\SafeFilesystem;
use SysAdmin\Doctor\engine\Logging\{DoctorAuditLogger, DoctorRepository};
use SysAdmin\Doctor\engine\Planning\PolicyEngine;
use SysAdmin\Doctor\engine\Process\ProcessRunner;
use SysAdmin\Doctor\engine\Registry\{DefinitionRepair, DefinitionCheck, DoctorRegistry};
use SysAdmin\Doctor\engine\Scoring\HealthScorer;
use SysAdmin\Doctor\engine\Security\SecretRedactor;
use SysAdmin\Doctor\engine\Support\CheckStatus;
use SysAdmin\Doctor\engine\Support\Value\{BackupResult, DetectionResult, DiagnosisResult, RepairMetadata, RepairPlan, RepairResult, RollbackResult, VerificationResult};
use SysAdmin\Doctor\sdk\{RepairPrimitiveRegistry};
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;

final class FailurePrimitive implements RepairPrimitive
{
    public function types(): array { return ['fixture_fail']; }
    public function descriptor(string $type): array { return ['category' => 'filesystem', 'reversible' => true]; }
    public function execute(array $action, DoctorContext $context): array {
        if (!empty($action['parents_only'])) {
            $context->filesystem->createDirectory(dirname($action['path']), 0750);
            throw new RuntimeException('Fixture failed after creating parents but before the target.');
        }
        $context->filesystem->atomicWrite($action['path'], 'partial');
        if (!empty($action['timeout'])) {
            $r = $context->process->run([PHP_BINARY, '-r', 'sleep(10);'], 1);
            if ($r->get('timed_out')) { throw new RuntimeException('Fixture timeout after partial change.'); }
        }
        if (!empty($action['record'])) { throw new \SysAdmin\Doctor\sdk\PartialActionFailure('Captured partial action.', ['type' => 'fixture_compensation', 'path' => $action['path']]); }
        throw new RuntimeException('Fixture exception; known-secret-value');
    }
    public function rollback(array $change, DoctorContext $context): bool { if (($change['type'] ?? '') !== 'fixture_compensation') { return false; } $context->filesystem->atomicWrite($change['path'], 'original-b'); return true; }
}

final class VerificationFixture implements RecoveryVerifiableRepair
{
    public function __construct(private DefinitionRepair $inner, private bool $throwVerification, private bool $lieRollback = false, private bool $drift = false) {}
    public function metadata(): RepairMetadata { return $this->inner->metadata(); }
    public function supports(EnvironmentContext $e): bool { return $this->inner->supports($e); }
    public function detect(DoctorContext $c): DetectionResult { return $this->inner->detect($c); }
    public function plan(DoctorContext $c, DetectionResult $d): RepairPlan { return $this->inner->plan($c, $d); }
    public function preflight(DoctorContext $c, RepairPlan $p): void { $this->inner->preflight($c, $p); }
    public function backup(DoctorContext $c, RepairPlan $p): BackupResult { return $this->inner->backup($c, $p); }
    public function repair(DoctorContext $c, RepairPlan $p): RepairResult { return $this->inner->repair($c, $p); }
    public function verify(DoctorContext $c, RepairResult $r): VerificationResult {
        if ($this->drift) { $c->filesystem->atomicWrite($c->projectPath . '/b', 'unrelated-edit'); return new VerificationResult(['passed' => false]); }
        if ($this->throwVerification) { throw new RuntimeException('Verification exception.'); }
        return $this->inner->verify($c, $r);
    }
    public function rollback(DoctorContext $c, RepairResult $r): RollbackResult {
        return $this->lieRollback ? new RollbackResult(['successful' => true]) : $this->inner->rollback($c, $r);
    }
    public function verifyRollback(DoctorContext $c, RepairResult $r): VerificationResult { return $this->inner->verifyRollback($c, $r); }
}

$root = sys_get_temp_dir() . '/doctor-rollback-' . bin2hex(random_bytes(10)); mkdir($root, 0700);
$assertions = 0; $failures = []; $cases = [];
$check = static function (bool $ok, string $message) use (&$assertions): void { $assertions++; if (!$ok) { throw new RuntimeException($message); } };
foreach (['success','partial','partial_record','parent_failure','exception','timeout','verification','verification_exception','rollback_failed','rollback_drift','report_only','unapproved','tampered_plan','non_reversible'] as $index => $case) {
    try {
        $path = $root . '/' . $case; mkdir($path, 0700); mkdir($path . '/backups', 0700); mkdir($path . '/project', 0700);
        file_put_contents($path . '/project/a', 'original-a'); file_put_contents($path . '/project/b', 'original-b');
        $pdo = new PDO('sqlite:' . $path . '/audit.sqlite'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE doctor_audit_events (id INTEGER PRIMARY KEY, execution_id TEXT, level TEXT, event_type TEXT, message TEXT, context_json TEXT, created_at TEXT)');
        $repo = new DoctorRepository($pdo); $redactor = new SecretRedactor(); $redactor->register('known-secret-value');
        $audit = new DoctorAuditLogger($repo, $redactor, 'doc_fixture'); $fs = new SafeFilesystem([$path]); $backups = new BackupManager($fs, $path . '/backups');
        $context = new DoctorContext('doc_fixture', new EnvironmentContext(['commands' => ['php' => PHP_BINARY]]), $path . '/project', [], new ProcessRunner($redactor, $audit), $fs, $backups, $audit, $redactor);
        $d = json_decode(file_get_contents(APP_ROOT . '/doctor/modules/laravel/Repairs/LARAVEL-0004-StorageDirectoryMissing.json'), true, 512, JSON_THROW_ON_ERROR);
        $d['metadata']['repair_id'] = 'TEST-0001'; $d['metadata']['backup_strategy'] = 'file-snapshot'; $d['metadata']['files_modified'] = ['{PROJECT}/a','{PROJECT}/b']; $d['metadata']['directories_modified'] = [];
        $d['actions'] = [['type' => 'atomic_write', 'path' => '{PROJECT}/a', 'contents' => 'new-a'], ['type' => 'atomic_write', 'path' => '{PROJECT}/b', 'contents' => 'new-b']];
        $d['backup_paths'] = ['{PROJECT}/a','{PROJECT}/b']; $d['verification'] = ['type' => 'file_contains', 'path' => '{PROJECT}/a', 'contains' => 'new-a'];
        if ($case === 'partial') { $d['actions'][1] = ['type' => 'chmod', 'path' => '{PROJECT}/b', 'mode' => 0777]; }
        if (in_array($case, ['partial_record','exception','timeout'], true)) { $d['actions'][1] = ['type' => 'fixture_fail', 'path' => '{PROJECT}/b', 'timeout' => $case === 'timeout', 'record' => $case === 'partial_record']; }
        if (in_array($case, ['verification','rollback_failed'], true)) { $d['verification']['contains'] = 'impossible-postcondition'; }
        if ($case === 'parent_failure') { $d['actions'][1] = ['type' => 'fixture_fail', 'path' => '{PROJECT}/created/nested/file', 'parents_only' => true]; }
        if ($case === 'report_only') { $d['metadata']['automation_class'] = 'REPORT_ONLY'; }
        if ($case === 'non_reversible') { $d['actions'][1] = ['type' => 'run_command', 'command' => [PHP_BINARY, '-r', 'exit(0);']]; }
        $inner = new DefinitionRepair($d, sdk: new RepairPrimitiveRegistry(additional: [new FailurePrimitive()]));
        $repair = in_array($case, ['verification_exception','rollback_failed','rollback_drift'], true) ? new VerificationFixture($inner, $case === 'verification_exception', $case === 'rollback_failed', $case === 'rollback_drift') : $inner;
        $plan = $repair->plan($context, new DetectionResult(['detected' => true]));
        $policy = new PolicyEngine(require APP_ROOT . '/doctor/config/policies.php'); $diagnosis = new DiagnosisResult(['confidence' => 1.0]);
        $entry = ['repair' => $repair, 'plan' => $plan, 'diagnosis' => $diagnosis, 'policy' => $policy->evaluate($repair->metadata(), $diagnosis, $plan, 'manual'), 'plan_hash' => DoctorEngine::planHash($plan)];
        if ($case === 'tampered_plan') { $entry['plan_hash'] = str_repeat('0', 64); }
        $engine = new DoctorEngine(new DoctorRegistry($path), $policy, new HealthScorer(), new ExecutionLock($path . '/engine.lock'));
        $row = $engine->execute($context, [$entry], static fn() => $case !== 'unapproved')[0]; $status = $row['repair']->get('status');
        $expected = match ($case) { 'success' => 'VERIFIED', 'report_only' => 'BLOCKED', 'unapproved' => 'SKIPPED', 'tampered_plan','non_reversible' => 'FAILED', 'rollback_failed','rollback_drift' => 'ROLLBACK_FAILED', default => 'ROLLED_BACK' };
        $check($status === $expected, $case . ': expected ' . $expected . ', got ' . $status . ': ' . $row['repair']->get('message'));
        if (!in_array($case, ['success','rollback_failed','rollback_drift'], true)) { $check(file_get_contents($path . '/project/a') === 'original-a' && file_get_contents($path . '/project/b') === 'original-b', 'Original bytes restored or never changed'); }
        if ($case === 'parent_failure') { $check(!file_exists($path . '/project/created'), 'Partially created ancestor chain restored to absence'); }
        if ($status === 'ROLLED_BACK') {
            $check($row['rollback']->successful() && $row['rollback']->get('verification')['passed'], 'Independent rollback verification required');
            $events = $repo->auditEvents('doc_fixture'); $indices = array_values(array_map(static fn($e) => $e['context']['observation']['action_index'], array_filter($events, static fn($e) => $e['event_type'] === 'doctor.action.rollback')));
            $check($indices === [1,0], 'Reverse action rollback order');
        }
        if ($case === 'rollback_failed') { $check($row['repair']->get('recovery_required') && file_get_contents($path . '/project/a') === 'new-a', 'A lying rollback call never counts as verified restoration'); }
        if ($case === 'rollback_drift') {
            $check(file_get_contents($path . '/project/a') === 'original-a' && file_get_contents($path . '/project/b') === 'unrelated-edit', 'Failed compensation preserves unrelated edits and still restores earlier actions');
            $other = new DoctorContext('doc_new_attempt', $context->environment, $context->projectPath, [], $context->process, $fs, $backups, $audit, $redactor);
            $check($engine->execute($other, [$entry], static fn() => true)[0]['repair']->get('status') === 'SKIPPED', 'Unresolved rollback blocks a new execution identity on the same resource');
        }
        if ($case === 'success') {
            $before = count($repo->auditEvents('doc_fixture'));
            $fresh = new DoctorEngine(new DoctorRegistry($path), $policy, new HealthScorer(), new ExecutionLock($path . '/engine.lock'));
            $again = $fresh->execute($context, [$entry], static function() { throw new RuntimeException('Duplicate must not consume another approval.'); })[0];
            $check($again['repair']->get('status') === 'SKIPPED' && file_get_contents($path . '/project/a') === 'new-a', 'Duplicate execution blocked after fresh controller call');
            $events = $repo->auditEvents('doc_fixture');
            $check(count($events) === $before + 1 && end($events)['event_type'] === 'doctor.repair.not_executed', 'Duplicate is audited without action or backup replay');
        }
        $check(!str_contains(json_encode($repo->auditEvents('doc_fixture')), 'known-secret-value'), 'Audit errors sanitized');
        $check(!str_contains(json_encode($repo->auditEvents('doc_fixture')), '--- before'), 'Configuration diffs excluded from durable audit');
        $cases[] = ['name' => $case, 'status' => 'PASS'];
    } catch (Throwable $error) { $failures[] = $case . ': ' . $error->getMessage(); $cases[] = ['name' => $case, 'status' => 'FAIL']; }
}
// The applicability fix skips a foreign project, but a Doctor-owning project must retain a mandatory config check.
try {
    $path = $root . '/retention'; mkdir($path, 0700); mkdir($path . '/doctor', 0700); mkdir($path . '/doctor/config', 0700);
    $definition = json_decode(file_get_contents(APP_ROOT . '/doctor/modules/backups/Checks/BACKUP-0008-BackupRetentionPolicyMissing.json'), true, 32, JSON_THROW_ON_ERROR);
    $c = new DoctorContext('doc_retention', $context->environment, $path, [], $context->process, new SafeFilesystem([$root]), new BackupManager(new SafeFilesystem([$root]), $root), $context->audit, $context->redactor);
    $probe = new DefinitionCheck($definition);
    $check($probe->run($c)->status === CheckStatus::SKIPPED, 'Foreign project missing optional Doctor config is not applicable');
    file_put_contents($path . '/doctor/DoctorApplication.php', '<?php');
    $check($probe->run($c)->status === CheckStatus::FAIL, 'Applicable Doctor project missing mandatory config remains FAIL');
    file_put_contents($path . '/doctor/config/doctor.php', '<?php return [];');
    $check($probe->run($c)->status === CheckStatus::FAIL, 'Existing config without required retention remains FAIL');
    file_put_contents($path . '/doctor/config/doctor.php', '<?php return ["report_retention_days"=>30];');
    $check($probe->run($c)->status === CheckStatus::PASS, 'Applicable valid retention config passes');
    unlink($path . '/doctor/config/doctor.php'); unlink($path . '/doctor/DoctorApplication.php');
    $mandatory = $definition; unset($mandatory['probe']['skip_if_missing']);
    $check((new DefinitionCheck($mandatory))->run($c)->status === CheckStatus::FAIL, 'Missing config without skip flag never hidden');
} catch (Throwable $error) { $failures[] = 'retention: ' . $error->getMessage(); }
// Exercise the real scheduler primitive against a disposable executable, never the host crontab.
try {
    $path = $root . '/scheduler'; mkdir($path, 0700); mkdir($path . '/executions', 0700);
    $state = $path . '/state'; $flag = $path . '/attempted'; $stub = $path . '/crontab';
    $before = "* * * * * original\n"; file_put_contents($state, $before);
    file_put_contents($stub, "#!/bin/sh\nstate=" . escapeshellarg($state) . "\nflag=" . escapeshellarg($flag) . '
case "$1" in
-l) cat "$state" ;;
*) cp "$1" "$state"; if [ ! -f "$flag" ]; then touch "$flag"; exit 7; fi ;;
esac
');
    chmod($stub, 0700); file_put_contents($path . '/job.php', '<?php');
    $env = new EnvironmentContext(['commands' => ['crontab' => $stub, 'php' => PHP_BINARY]]);
    $fs = new SafeFilesystem([$root, '/tmp']);
    $c = new DoctorContext('doc_scheduler_fixture', $env, $path, ['doctor_runtime_root' => $path], $context->process, $fs, new BackupManager($fs, $path), $context->audit, $context->redactor);
    $primitive = new \SysAdmin\Doctor\sdk\Primitives\CrontabPrimitive();
    try {
        $primitive->execute(['type' => 'ensure_crontab_entry', 'schedule' => '* * * * *', 'marker' => 'MicroSysAdmin Doctor TEST-0001', 'command' => [PHP_BINARY, $path . '/job.php']], $c);
        throw new LogicException('Partial scheduler failure was not reported.');
    } catch (\SysAdmin\Doctor\sdk\PartialActionFailure $error) { $change = $error->change; }
    $check(file_get_contents($state) !== $before, 'Fixture scheduler installed state before failing');
    $check($primitive->rollback($change, $c) && file_get_contents($state) === $before, 'Real scheduler primitive compensates partial installation with actual-state verification');
    $check($primitive->rollback($change, $c), 'Already restored scheduler verified without repeat mutation');
    file_put_contents($change['backup_path'], 'corrupt');
    try { $primitive->rollback($change, $c); throw new LogicException('Corrupt scheduler backup accepted.'); }
    catch (RuntimeException $error) { $check(str_contains($error->getMessage(), 'integrity') && file_get_contents($state) === $before, 'Corrupt scheduler backup rejected before changing scheduler'); }
} catch (Throwable $error) { $failures[] = 'scheduler: ' . $error->getMessage(); }
echo json_encode(['status' => $failures ? 'FAIL' : 'PASS', 'cases' => $cases, 'assertions' => $assertions, 'failures' => $failures, 'fixture_root' => $root], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($failures ? 1 : 0);
