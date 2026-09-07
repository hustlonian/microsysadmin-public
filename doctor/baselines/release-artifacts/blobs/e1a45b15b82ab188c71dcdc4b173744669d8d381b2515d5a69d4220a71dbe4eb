<?php

declare(strict_types=1);

namespace SysAdmin\Doctor;

use SysAdmin\Auth;
use SysAdmin\Doctor\engine\Backup\BackupManager;
use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Discovery\EnvironmentDiscovery;
use SysAdmin\Doctor\engine\Execution\DoctorEngine;
use SysAdmin\Doctor\engine\Execution\ExecutionLock;
use SysAdmin\Doctor\engine\Filesystem\SafeFilesystem;
use SysAdmin\Doctor\engine\Logging\DoctorAuditLogger;
use SysAdmin\Doctor\engine\Logging\DoctorRepository;
use SysAdmin\Doctor\engine\Planning\PolicyEngine;
use SysAdmin\Doctor\engine\Process\ProcessRunner;
use SysAdmin\Doctor\engine\Registry\DoctorRegistry;
use SysAdmin\Doctor\engine\Reporting\ReportBuilder;
use SysAdmin\Doctor\engine\Scoring\HealthScorer;
use SysAdmin\Doctor\engine\Security\SecretRedactor;
use SysAdmin\Doctor\engine\Support\RepairStatus;
use SysAdmin\Doctor\engine\Support\Value\RepairResult;

/**
 * Shared application service used by the CLI and authenticated web interface.
 */
final class DoctorApplication
{
    private array $configuration;
    private array $policies;
    private string $runtimeRoot;
    private SecretRedactor $redactor;
    private DoctorRepository $repository;
    private DoctorRegistry $registry;

    public function __construct(?DoctorRepository $repository = null)
    {
        $this->configuration = $this->loadConfiguration('doctor.php');
        $this->policies = $this->loadConfiguration('policies.php');
        $this->runtimeRoot = $this->expandPath((string) ($this->configuration['runtime_root'] ?? '{STORAGE_PATH}/doctor'));
        $this->initializeRuntime();
        $this->redactor = new SecretRedactor();
        $this->registerConfiguredSecrets();
        $this->repository = $repository ?? new DoctorRepository();
        $this->registry = new DoctorRegistry(APP_ROOT . '/doctor/modules');
        $this->registry->discover();
    }

    public function configuration(): array
    {
        return [
            'enabled' => (bool) ($this->configuration['enabled'] ?? false),
            'runtime_root' => $this->runtimeRoot,
            'report_formats' => (array) ($this->configuration['report_formats'] ?? []),
            'policies' => array_keys((array) ($this->policies['policies'] ?? [])),
        ];
    }

    public function dashboard(bool $includeSensitive = false, bool $includeAudit = false): array
    {
        $latest = $this->repository->recentExecutions(20);
        $last = $latest[0] ?? null;
        $detail = $includeSensitive && is_array($last) ? $this->repository->execution((string) $last['execution_id']) : null;
        if ($includeAudit && is_array($detail)) {
            $detail['audit_trail'] = $this->repository->auditEvents((string) $detail['execution_id'], 200);
        }
        return [
            'configuration' => $this->configuration(),
            'registry' => $this->registry->summary(),
            'modules' => $this->moduleCatalog(),
            'inventory' => $this->registry->inventory(),
            'executions' => $latest,
            'latest' => $detail,
        ];
    }

    public function discover(?string $projectPath = null): array
    {
        $project = $this->projectPath($projectPath);
        $process = new ProcessRunner(
            $this->redactor,
            null,
            (int) ($this->configuration['maximum_output_bytes'] ?? 1048576),
            (int) ($this->configuration['maximum_timeout'] ?? 900),
        );
        return (new EnvironmentDiscovery($process))->discover($project)->toArray();
    }

    public function inventory(?string $module = null): array
    {
        return array_values(array_filter(
            $this->registry->inventory(),
            static fn (array $entry): bool => $module === null || ($entry['module'] ?? null) === $module
        ));
    }

    public function listModules(): array
    {
        return $this->moduleCatalog();
    }

    public function listChecks(?string $module = null): array
    {
        return array_map(static fn ($check): array => $check->metadata()->toArray(), $this->registry->checks($module));
    }

    public function listDiagnoses(?string $module = null): array
    {
        return array_map(static fn ($diagnosis): array => $diagnosis->metadata()->toArray(), $this->registry->diagnoses($module));
    }

    public function listRepairs(?string $module = null): array
    {
        return array_map(static fn ($repair): array => $repair->metadata()->toArray(), $this->registry->repairs($module));
    }

    public function describeRepair(string $repairId): ?array
    {
        $repair = $this->registry->repair(strtoupper(trim($repairId)));
        if ($repair === null) {
            return null;
        }
        $inventory = null;
        foreach ($this->registry->inventory() as $entry) {
            if (($entry['repair_id'] ?? null) === $repair->metadata()->id()) {
                $inventory = $entry;
                break;
            }
        }
        $documentation = $repair->metadata()->get('documentation_reference');
        $document = is_string($documentation) && is_file(APP_ROOT . '/doctor/' . ltrim($documentation, '/'))
            ? (string) file_get_contents(APP_ROOT . '/doctor/' . ltrim($documentation, '/'))
            : null;
        return ['metadata' => $repair->metadata()->toArray(), 'inventory' => $inventory, 'documentation' => $document];
    }

    /**
     * Run discovery, checks, diagnoses, planning, optional repairs, verification,
     * rescoring, reporting and the sanitized audit trail as one lifecycle.
     */
    public function run(array $options = []): array
    {
        if (!(bool) ($this->configuration['enabled'] ?? false)) {
            throw new \RuntimeException('MicroSysAdmin Doctor is disabled.');
        }
        $mode = strtolower((string) ($options['mode'] ?? 'scan'));
        if (!in_array($mode, ['scan', 'diagnose', 'plan', 'repair', 'verify', 'report', 'health'], true)) {
            throw new \InvalidArgumentException('Unsupported Doctor execution mode.');
        }
        $project = $this->projectPath(isset($options['project_path']) ? (string) $options['project_path'] : null);
        $executionId = $this->executionId();
        $environment = $this->discover($project);
        $safeOptions = (array) $this->redactor->value($options);
        $userId = isset($options['user_id']) ? (int) $options['user_id'] : (Auth::id() ?: null);
        $this->repository->beginExecution($executionId, $mode, $project, $environment, $safeOptions, $userId);
        [$context, $engine, $reporter] = $this->services($executionId, $project, $environment, $options);
        $started = microtime(true);
        $before = null;
        $after = null;
        try {
            $context->audit->event('doctor.execution.started', 'Doctor execution started.', [
                'mode' => $mode, 'project_path' => $project, 'options' => $safeOptions,
            ]);
            $modules = $this->stringList($options['modules'] ?? $options['module'] ?? []);
            $checkIds = $this->stringList($options['check_ids'] ?? []);
            $repairIds = array_map('strtoupper', $this->stringList($options['repair_ids'] ?? $options['repair_id'] ?? []));
            $checks = $engine->scan($context, $modules, $checkIds);
            $excluded = array_map('strtoupper', $this->stringList($options['exclude'] ?? []));
            $severities = array_map('strtoupper', $this->stringList($options['severity'] ?? []));
            $checks = array_values(array_filter($checks, static function ($check) use ($excluded, $severities): bool {
                return !in_array(strtoupper($check->metadata->id()), $excluded, true)
                    && ($severities === [] || in_array(strtoupper((string) $check->metadata->get('severity')), $severities, true));
            }));
            $before = $engine->score($checks);
            $diagnoses = in_array($mode, ['diagnose', 'plan', 'repair', 'verify', 'report', 'health'], true)
                ? $engine->diagnose($context, $checks)
                : [];
            $policy = (string) ($options['approval_policy'] ?? ($mode === 'repair' ? 'safe' : 'manual'));
            $plans = in_array($mode, ['diagnose', 'plan', 'repair', 'verify', 'report', 'health'], true)
                ? $engine->plans($context, $diagnoses, $policy, $repairIds)
                : [];
            $risks = array_map('strtoupper', $this->stringList($options['risk'] ?? []));
            $plans = array_values(array_filter($plans, static function (array $entry) use ($excluded, $risks): bool {
                $metadata = $entry['repair']->metadata();
                return !in_array(strtoupper($metadata->id()), $excluded, true)
                    && ($risks === [] || in_array(strtoupper((string) $metadata->get('risk_level')), $risks, true));
            }));
            $executions = [];
            if ($mode === 'repair' && empty($options['dry_run'])) {
                $approvalExecutionId = (string) ($options['approval_execution_id'] ?? $executionId);
                $executionPlans = $plans;
                if (!empty($options['approve_generated'])) {
                    if (!$userId) {
                        throw new \RuntimeException('An authenticated operator is required to authorize Fix everything.');
                    }
                    $executionPlans = array_values(array_filter(
                        $plans,
                        static fn (array $entry): bool => $entry['policy']->allowed()
                    ));
                    foreach ($executionPlans as $entry) {
                        if ($entry['policy']->requiresApproval()) {
                            $this->approve(
                                $executionId,
                                $entry['repair']->metadata()->id(),
                                (string) $entry['plan_hash'],
                                (int) $userId,
                            );
                        }
                    }
                }
                $executions = $engine->execute($context, $executionPlans, function (array $entry) use ($approvalExecutionId): bool {
                    $id = $entry['repair']->metadata()->id();
                    return $this->repository->consumeApproval($approvalExecutionId, $id, (string) $entry['plan_hash']);
                });
            }
            $afterChecks = $executions !== [] ? $engine->scan($context, $modules, $checkIds) : $checks;
            if ($executions !== []) {
                $afterChecks = array_values(array_filter($afterChecks, static function ($check) use ($excluded, $severities): bool {
                    return !in_array(strtoupper($check->metadata->id()), $excluded, true)
                        && ($severities === [] || in_array(strtoupper((string) $check->metadata->get('severity')), $severities, true));
                }));
            }
            $resolvedDiagnoses = $executions !== [] ? $engine->diagnose($context, $afterChecks) : $diagnoses;
            $after = $engine->score($afterChecks);
            $serializedRepairs = array_map(static fn (array $item): array => [
                ...$item['repair']->toArray(),
                'backup' => isset($item['backup']) ? $item['backup']->toArray() : null,
                'verification' => isset($item['verification']) && $item['verification'] !== null ? $item['verification']->toArray() : null,
                'rollback' => isset($item['rollback']) && $item['rollback'] !== null ? $item['rollback']->toArray() : null,
            ], $executions);
            $repairSummary = $this->repairSummary($plans, $serializedRepairs, $afterChecks);
            $executionStatus = $repairSummary['failed'] > 0 || $repairSummary['unresolved_failures'] > 0
                ? 'COMPLETED_WITH_ERRORS'
                : ($repairSummary['skipped'] > 0 ? 'COMPLETED_WITH_LIMITATIONS' : 'COMPLETED');
            $workflow = (string) ($options['workflow'] ?? ($mode === 'repair' ? 'repair' : $mode));
            $result = [
                'execution_id' => $executionId,
                'mode' => $mode,
                'workflow' => $workflow,
                'status' => $executionStatus,
                'project_path' => $project,
                'environment' => $environment,
                'modules_executed' => $modules === [] ? array_column($this->moduleCatalog(), 'name') : $modules,
                'checks' => array_map(static fn ($item): array => $item->jsonSerialize(), $checks),
                'diagnoses' => array_map(static fn ($item): array => $item->toArray(), $resolvedDiagnoses),
                'initial_diagnoses' => $executions !== []
                    ? array_map(static fn ($item): array => $item->toArray(), $diagnoses)
                    : [],
                'plans' => array_map(fn (array $item): array => $engine->serializePlan($item), $plans),
                'repairs' => $serializedRepairs,
                'repair_summary' => $repairSummary,
                'notice' => $mode === 'repair' ? $this->repairNotice($repairSummary, $workflow === 'fix_all') : 'Doctor execution completed.',
                'after_checks' => $executions !== [] ? array_map(static fn ($item): array => $item->jsonSerialize(), $afterChecks) : [],
                'before_health_score' => $before->toArray(),
                'after_health_score' => $after->toArray(),
                'duration_ms' => round((microtime(true) - $started) * 1000, 3),
            ];
            $context->audit->event('doctor.execution.completed', 'Doctor execution completed.', [
                'status' => $executionStatus, 'before' => $before->toArray(), 'after' => $after->toArray(),
                'repair_summary' => $repairSummary, 'duration_ms' => $result['duration_ms'],
            ], $executionStatus === 'COMPLETED_WITH_ERRORS' ? 'WARNING' : 'INFO');
            $result['audit_trail'] = $this->repository->auditEvents($executionId);
            $formats = $this->reportFormats($options);
            $reports = [];
            foreach ($formats as $format) {
                $reports[] = $reporter->generate($executionId, $result, $format)->toArray();
            }
            $result['reports'] = $reports;
            $this->repository->completeExecution($executionId, $executionStatus, (array) $this->redactor->value($result), $before->toArray(), $after->toArray());
            return (array) $this->redactor->value($result);
        } catch (\Throwable $exception) {
            $message = $this->redactor->text($exception->getMessage());
            $context->audit->event('doctor.execution.failed', 'Doctor execution failed.', ['error' => $message], 'ERROR');
            $failed = ['execution_id' => $executionId, 'mode' => $mode, 'status' => 'FAILED', 'error' => $message, 'audit_trail' => $this->repository->auditEvents($executionId)];
            $this->repository->completeExecution($executionId, 'FAILED', $failed, $before?->toArray(), $after?->toArray());
            throw $exception;
        }
    }

    public function fixEverything(array $options = []): array
    {
        return $this->run([
            ...$options,
            'mode' => 'repair',
            'workflow' => 'fix_all',
            'approval_policy' => 'manual',
            'approve_generated' => true,
            'modules' => [],
            'check_ids' => [],
            'repair_ids' => [],
        ]);
    }

    public function approve(string $executionId, string $repairId, string $planHash, int $userId): void
    {
        if (!preg_match('/^doc_[A-Za-z0-9_-]{8,60}$/', $executionId) || !preg_match('/^[A-Z]+-[0-9]{4}$/', $repairId) || !preg_match('/^[a-f0-9]{64}$/', $planHash)) {
            throw new \InvalidArgumentException('Invalid Doctor approval request.');
        }
        $this->repository->approve($executionId, $repairId, $planHash, $userId);
        $this->repository->audit($executionId, 'WARNING', 'doctor.plan.approved', 'An exact Doctor repair plan was approved.', [
            'repair_id' => $repairId,
            'plan_hash' => $planHash,
            'approved_by' => $userId,
        ]);
    }

    public function fixFromExecution(string $executionId, string $repairId, string $planHash, array $options = []): array
    {
        $repairId = strtoupper(trim($repairId));
        $planHash = strtolower(trim($planHash));
        if (!preg_match('/^doc_[A-Za-z0-9_-]{8,60}$/', $executionId)
            || !preg_match('/^[A-Z]+-[0-9]{4}$/', $repairId)
            || !preg_match('/^[a-f0-9]{64}$/', $planHash)) {
            throw new \InvalidArgumentException('Invalid Doctor fix request.');
        }
        $source = $this->repository->execution($executionId);
        $result = (array) ($source['result_json'] ?? []);
        if ($source === null
            || !str_starts_with((string) ($source['status'] ?? ''), 'COMPLETED')
            || !str_starts_with((string) ($result['status'] ?? ''), 'COMPLETED')) {
            throw new \InvalidArgumentException('The Doctor source execution is unavailable or incomplete.');
        }
        $selected = null;
        foreach ((array) ($result['plans'] ?? []) as $entry) {
            if (strtoupper((string) ($entry['repair_id'] ?? '')) === $repairId) {
                $selected = (array) $entry;
                break;
            }
        }
        if ($selected === null || !hash_equals((string) ($selected['plan_hash'] ?? ''), $planHash)) {
            throw new \InvalidArgumentException('The exact Doctor repair plan was not found. Run Diagnose again to refresh it.');
        }
        $policy = (array) ($selected['policy'] ?? []);
        $diagnosis = (array) ($selected['diagnosis'] ?? []);
        if (empty($policy['allowed']) || !in_array($repairId, array_map('strtoupper', (array) ($diagnosis['related_repair_ids'] ?? [])), true)) {
            throw new \RuntimeException('This finding does not have an executable automatic repair.');
        }
        $userId = (int) ($options['user_id'] ?? (Auth::id() ?: 0));
        if ($userId < 1) {
            throw new \RuntimeException('An authenticated operator is required to approve this repair.');
        }
        $this->approve($executionId, $repairId, $planHash, $userId);
        return $this->run([
            ...$options,
            'project_path' => (string) ($source['project_path'] ?? APP_ROOT),
            'mode' => 'repair',
            'repair_ids' => [$repairId],
            'approval_policy' => 'manual',
            'approval_execution_id' => $executionId,
        ]);
    }

    public function execution(string $executionId, bool $includeAudit = true): ?array
    {
        $execution = $this->repository->execution($executionId);
        if ($includeAudit && $execution !== null) {
            $execution['audit_trail'] = $this->repository->auditEvents($executionId);
        }
        return $execution;
    }

    public function reportFile(string $executionId, string $format): array
    {
        if (!preg_match('/^doc_[A-Za-z0-9_-]{8,60}$/', $executionId) || !in_array($format, ['json', 'text', 'html', 'pdf-html'], true)) {
            throw new \InvalidArgumentException('Invalid Doctor report request.');
        }
        $report = $this->repository->report($executionId, $format);
        $path = realpath((string) ($report['report_path'] ?? ''));
        $root = realpath($this->runtimeRoot . '/reports');
        if ($report === null || $path === false || $root === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Doctor report was not found.');
        }
        if (!hash_equals((string) $report['content_hash'], (string) hash_file('sha256', $path))) {
            throw new \RuntimeException('Doctor report integrity validation failed.');
        }
        return [...$report, 'report_path' => $path];
    }

    public function rollbackExecution(string $sourceExecutionId, array $repairIds = [], array $options = []): array
    {
        $source = $this->repository->execution($sourceExecutionId);
        if ($source === null || !is_array($source['result_json'] ?? null)) {
            throw new \InvalidArgumentException('Doctor source execution was not found.');
        }
        $project = $this->projectPath((string) ($source['project_path'] ?? APP_ROOT));
        $executionId = $this->executionId();
        $environment = $this->discover($project);
        $userId = isset($options['user_id']) ? (int) $options['user_id'] : (Auth::id() ?: null);
        $this->repository->beginExecution($executionId, 'rollback', $project, $environment, (array) $this->redactor->value($options), $userId);
        [$context, $engine, $reporter] = $this->services($executionId, $project, $environment, $options);
        $lock = new ExecutionLock($this->runtimeRoot . '/locks/execution.lock');
        $wanted = array_map('strtoupper', $this->stringList($repairIds));
        $rollbacks = [];
        $lock->acquire((int) ($this->configuration['lock_wait_seconds'] ?? 2));
        try {
            foreach ((array) (($source['result_json']['repairs'] ?? [])) as $record) {
                $id = strtoupper((string) ($record['repair_id'] ?? ''));
                if ($id === '' || ($wanted !== [] && !in_array($id, $wanted, true))) {
                    continue;
                }
                $repair = $this->registry->repair($id);
                if ($repair === null || !$repair->metadata()->rollbackSupported()) {
                    $rollbacks[] = ['repair_id' => $id, 'successful' => false, 'status' => 'UNSUPPORTED', 'message' => 'No automatic rollback definition is available.'];
                    continue;
                }
                $context->audit->event('doctor.rollback.started', 'Doctor rollback started.', ['repair_id' => $id, 'source_execution_id' => $sourceExecutionId], 'WARNING');
                $rollback = $repair->rollback($context, new RepairResult((array) $record));
                $rollbacks[] = $rollback->toArray();
                $context->audit->event('doctor.rollback.completed', 'Doctor rollback completed.', $rollback->toArray(), $rollback->successful() ? 'INFO' : 'ERROR');
            }
        } finally {
            $lock->release();
        }
        $checks = $engine->scan($context);
        $score = $engine->score($checks);
        $result = [
            'execution_id' => $executionId, 'mode' => 'rollback',
            'status' => array_filter($rollbacks, static fn (array $item): bool => empty($item['successful'])) === [] ? 'COMPLETED' : 'COMPLETED_WITH_ERRORS',
            'source_execution_id' => $sourceExecutionId, 'project_path' => $project, 'environment' => $environment,
            'rollbacks' => $rollbacks, 'checks' => array_map(static fn ($item): array => $item->jsonSerialize(), $checks),
            'before_health_score' => $source['after_score_json'] ?? $source['before_score_json'] ?? null,
            'after_health_score' => $score->toArray(), 'audit_trail' => $this->repository->auditEvents($executionId),
        ];
        $reports = [];
        foreach ($this->reportFormats($options) as $format) {
            $reports[] = $reporter->generate($executionId, $result, $format)->toArray();
        }
        $result['reports'] = $reports;
        $this->repository->completeExecution($executionId, (string) $result['status'], $result, is_array($result['before_health_score']) ? $result['before_health_score'] : null, $score->toArray());
        return (array) $this->redactor->value($result);
    }

    private function services(string $executionId, string $project, array $environmentData, array $options): array
    {
        $audit = new DoctorAuditLogger($this->repository, $this->redactor, $executionId);
        $process = new ProcessRunner(
            $this->redactor,
            $audit,
            (int) ($this->configuration['maximum_output_bytes'] ?? 1048576),
            (int) ($this->configuration['maximum_timeout'] ?? 900),
        );
        $writeRoots = $this->configuredRoots((array) ($this->configuration['allowed_roots'] ?? []));
        $writeRoots = array_values(array_unique([...$writeRoots, $project, $this->runtimeRoot]));
        $readRoots = $this->configuredRoots((array) ($this->configuration['read_only_roots'] ?? []));
        $filesystem = new SafeFilesystem($writeRoots, $readRoots);
        $backups = new BackupManager($filesystem, $this->runtimeRoot . '/backups');
        $context = new DoctorContext(
            $executionId,
            new \SysAdmin\Doctor\engine\Context\EnvironmentContext($environmentData),
            $project,
            [
                ...$options,
                'lock_wait_seconds' => (int) ($this->configuration['lock_wait_seconds'] ?? 2),
                'doctor_runtime_root' => $this->runtimeRoot,
            ],
            $process,
            $filesystem,
            $backups,
            $audit,
            $this->redactor,
        );
        $engine = new DoctorEngine(
            $this->registry,
            new PolicyEngine($this->policies),
            new HealthScorer(array_column($this->moduleCatalog(), 'name')),
            new ExecutionLock($this->runtimeRoot . '/locks/execution.lock'),
        );
        $reportRoot = $this->runtimeRoot . '/reports';
        if (isset($options['report_path']) && trim((string) $options['report_path']) !== '') {
            $reportRoot = $this->expandPath(trim((string) $options['report_path']));
            if (!is_dir($reportRoot)) {
                $filesystem->createDirectory($reportRoot, 0750);
            }
            $reportRoot = $filesystem->normalize($reportRoot, true, true);
        }
        $reporter = new ReportBuilder($filesystem, $this->redactor, $this->repository, $reportRoot);
        return [$context, $engine, $reporter];
    }

    private function repairSummary(array $plans, array $repairs, array $afterChecks): array
    {
        $statuses = array_count_values(array_map(
            static fn (array $repair): string => strtoupper((string) ($repair['status'] ?? RepairStatus::FAILED->value)),
            $repairs,
        ));
        $eligible = count(array_filter($plans, static fn (array $entry): bool => $entry['policy']->allowed()));
        $manual = count($plans) - $eligible;
        $remaining = count(array_filter($afterChecks, static function ($check): bool {
            $serialized = $check->jsonSerialize();
            return in_array(strtoupper((string) ($serialized['status'] ?? 'UNKNOWN')), ['WARNING', 'FAIL', 'ERROR', 'UNKNOWN'], true);
        }));
        $unresolvedFailures = count(array_filter($afterChecks, static function ($check): bool {
            $serialized = $check->jsonSerialize();
            return in_array(strtoupper((string) ($serialized['status'] ?? 'UNKNOWN')), ['FAIL', 'ERROR', 'UNKNOWN'], true);
        }));
        return [
            'planned' => count($plans),
            'eligible' => $eligible,
            'manual' => $manual,
            'attempted' => count($repairs),
            'fixed' => (int) ($statuses[RepairStatus::VERIFIED->value] ?? 0),
            'failed' => (int) (($statuses[RepairStatus::FAILED->value] ?? 0)
                + ($statuses[RepairStatus::ROLLBACK_FAILED->value] ?? 0)
                + ($statuses[RepairStatus::ROLLED_BACK->value] ?? 0)),
            'skipped' => (int) (($statuses[RepairStatus::SKIPPED->value] ?? 0)
                + ($statuses[RepairStatus::BLOCKED->value] ?? 0)
                + ($statuses[RepairStatus::REPORT_ONLY->value] ?? 0)),
            'remaining_findings' => $remaining,
            'unresolved_failures' => $unresolvedFailures,
            'statuses' => $statuses,
        ];
    }

    private function repairNotice(array $summary, bool $fixAll): string
    {
        $label = $fixAll ? 'Fix everything' : 'Repair';
        if ((int) $summary['failed'] > 0 || (int) ($summary['unresolved_failures'] ?? 0) > 0) {
            return sprintf(
                '%s did not clear every failure: %d repairs verified, %d repair attempts failed, and %d failures remain.',
                $label,
                (int) $summary['fixed'],
                (int) $summary['failed'],
                (int) ($summary['unresolved_failures'] ?? 0),
            );
        }
        if ((int) $summary['skipped'] > 0) {
            return sprintf(
                '%s finished with limitations: %d verified, %d skipped, and %d advisory findings remain.',
                $label,
                (int) $summary['fixed'],
                (int) $summary['skipped'],
                (int) $summary['manual'],
            );
        }
        if ((int) $summary['eligible'] === 0) {
            return sprintf('No automatic repairs were needed. %d advisory findings remain and 0 failures remain.', (int) $summary['manual']);
        }
        return sprintf(
            '%s completed: %d repairs were verified. %d advisory findings remain and 0 failures remain.',
            $label,
            (int) $summary['fixed'],
            (int) $summary['manual'],
        );
    }

    private function moduleCatalog(): array
    {
        $catalog = [];
        foreach ($this->registry->modules() as $module) {
            $name = (string) $module['name'];
            $catalog[] = [
                'name' => $name,
                'display_name' => (string) ($module['display_name'] ?? ucfirst($name)),
                'version' => (string) ($module['version'] ?? '0.0.0'),
                'stage' => (int) ($module['stage'] ?? 0),
                'enabled' => (bool) ($module['enabled'] ?? true),
                'checks' => count($this->registry->checks($name)),
                'diagnoses' => count($this->registry->diagnoses($name)),
                'repairs' => count($this->registry->repairs($name)),
                'inventory' => count((array) ($module['inventory'] ?? [])),
            ];
        }
        return $catalog;
    }

    private function projectPath(?string $path): string
    {
        $path = trim((string) ($path ?? $this->configuration['default_project_path'] ?? APP_ROOT));
        $path = $this->expandPath($path);
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            throw new \InvalidArgumentException('Doctor project path is unavailable.');
        }
        $roots = [...$this->configuredRoots((array) ($this->configuration['allowed_roots'] ?? [])), ...\SysAdmin\EnvironmentConfig::paths('allowed_filesystem_roots')];
        foreach ($roots as $root) {
            $rootReal = realpath($root);
            if ($rootReal !== false && ($real === $rootReal || str_starts_with($real, rtrim($rootReal, '/') . '/'))) {
                return $real;
            }
        }
        throw new \InvalidArgumentException('Doctor project path is outside configured filesystem roots.');
    }

    private function initializeRuntime(): void
    {
        $mode = (int) ($this->configuration['runtime_directory_mode'] ?? 02770);
        if ($mode < 02700 || $mode > 02770 || ($mode & 0007) !== 0) {
            throw new \RuntimeException('Doctor runtime directory mode is unsafe.');
        }
        foreach ([$this->runtimeRoot, 'executions', 'reports', 'backups', 'logs', 'locks', 'composer-home', 'composer-home/cache'] as $key => $path) {
            $directory = $key === 0 ? $path : $this->runtimeRoot . '/' . $path;
            if (is_link($directory)) {
                throw new \RuntimeException('Doctor runtime storage cannot be symbolic.');
            }
            if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to initialize Doctor runtime storage.');
            }
            $permissions = fileperms($directory);
            if ($permissions === false) {
                throw new \RuntimeException('Unable to inspect Doctor runtime storage.');
            }
            $currentMode = $permissions & 07777;
            if ($currentMode !== $mode && !chmod($directory, $mode)) {
                throw new \RuntimeException('Doctor runtime storage permissions require administrator repair.');
            }
            clearstatcache(true, $directory);
            if (!is_readable($directory) || !is_writable($directory)) {
                throw new \RuntimeException('Doctor runtime storage is not writable by this execution account.');
            }
        }
    }

    private function registerConfiguredSecrets(): void
    {
        foreach (['database.password', 'mail.password', 'security.app_key'] as $key) {
            $value = config_value($key, '');
            if (is_string($value) && $value !== '') {
                $this->redactor->register($value);
            }
        }
    }

    private function reportFormats(array $options): array
    {
        if (!empty($options['no_reports'])) {
            return [];
        }
        $formats = $this->stringList($options['report_formats'] ?? $options['report_format'] ?? ($this->configuration['report_formats'] ?? ['json']));
        $allowed = (array) ($this->configuration['report_formats'] ?? ['json']);
        return array_values(array_unique(array_filter($formats, static fn (string $format): bool => in_array($format, $allowed, true))));
    }

    private function configuredRoots(array $roots): array
    {
        $expanded = [];
        foreach ($roots as $root) {
            $path = $this->expandPath((string) $root);
            if (is_dir($path)) {
                $expanded[] = $path;
            }
        }
        return array_values(array_unique($expanded));
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        return array_values(array_unique(array_filter(array_map('strval', (array) $value), static fn (string $item): bool => $item !== '')));
    }

    private function executionId(): string
    {
        return 'doc_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
    }

    private function expandPath(string $path): string
    {
        return strtr($path, ['{APP_ROOT}' => APP_ROOT, '{STORAGE_PATH}' => STORAGE_PATH]);
    }

    private function loadConfiguration(string $file): array
    {
        $path = APP_ROOT . '/doctor/config/' . $file;
        $config = is_file($path) ? require $path : [];
        if (!is_array($config)) {
            throw new \RuntimeException("Invalid Doctor configuration {$file}.");
        }
        return $config;
    }
}
