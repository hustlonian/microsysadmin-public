<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Registry;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Contracts\RecoveryVerifiableRepair;
use SysAdmin\Doctor\engine\Detection\ProbeExecutor;
use SysAdmin\Doctor\engine\Filesystem\ManagedArtifactCatalog;
use SysAdmin\Doctor\engine\Support\AutomationClass;
use SysAdmin\Doctor\engine\Support\RepairStatus;
use SysAdmin\Doctor\engine\Support\TemplateResolver;
use SysAdmin\Doctor\engine\Support\Value\BackupResult;
use SysAdmin\Doctor\engine\Support\Value\DetectionResult;
use SysAdmin\Doctor\engine\Support\Value\RepairMetadata;
use SysAdmin\Doctor\engine\Support\Value\RepairPlan;
use SysAdmin\Doctor\engine\Support\Value\RepairResult;
use SysAdmin\Doctor\engine\Support\Value\RollbackResult;
use SysAdmin\Doctor\engine\Support\Value\VerificationResult;
use SysAdmin\Doctor\sdk\RepairPrimitiveRegistry;

/**
 * Definition lifecycle adapter.
 *
 * Detection and verification are delegated to ProbeExecutor, backup and
 * restoration to BackupManager, and every mutation to a Repair SDK primitive.
 * This class deliberately contains no repair-specific mutation code.
 */
final class DefinitionRepair implements RecoveryVerifiableRepair
{
    private ?BackupResult $lastBackup = null;

    public function __construct(
        private readonly array $definition,
        private readonly ProbeExecutor $probes = new ProbeExecutor(),
        private readonly TemplateResolver $resolver = new TemplateResolver(),
        private readonly ManagedArtifactCatalog $artifacts = new ManagedArtifactCatalog(),
        private readonly ?RepairPrimitiveRegistry $sdk = null,
    ) {
    }

    public function metadata(): RepairMetadata
    {
        return new RepairMetadata((array) $this->definition['metadata']);
    }

    public function supports(EnvironmentContext $environment): bool
    {
        foreach ((array) $this->metadata()->get('required_commands', []) as $command) {
            if (str_contains((string) $command, '/')) {
                if (!is_executable((string) $command)) {
                    return false;
                }
            } elseif ($environment->command((string) $command) === null) {
                return false;
            }
        }
        return $environment->supportsModule($this->metadata()->module());
    }

    public function detect(DoctorContext $context): DetectionResult
    {
        $probe = $this->probes->run((array) ($this->definition['detection'] ?? []), $context);
        return new DetectionResult([
            'repair_id' => $this->metadata()->id(),
            'detected' => !$probe->healthy(),
            'confidence' => !$probe->healthy() ? (float) ($this->definition['confidence'] ?? 0.9) : 1.0,
            'evidence' => [$probe->toArray()],
            'reason' => (string) $probe->get('message'),
        ]);
    }

    public function plan(DoctorContext $context, DetectionResult $detection): RepairPlan
    {
        $actions = (array) $this->resolver->value((array) ($this->definition['actions'] ?? []), $context);
        $files = (array) $this->resolver->value((array) ($this->definition['backup_paths'] ?? []), $context);
        $metadataFiles = (array) $this->resolver->value((array) $this->metadata()->get('files_modified', []), $context);
        $metadataDirectories = (array) $this->resolver->value((array) $this->metadata()->get('directories_modified', []), $context);
        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');
            if (!$this->primitives()->supports($type)) {
                throw new \RuntimeException("Repair definition references unknown SDK primitive {$type}.");
            }
        }
        return new RepairPlan([
            'repair_id' => $this->metadata()->id(),
            'issue' => $this->metadata()->get('problem_description'),
            'cause' => $detection->get('reason'),
            'evidence' => $detection->get('evidence', []),
            'severity' => $this->metadata()->get('severity'),
            'risk' => $this->metadata()->get('risk_level'),
            'automation_class' => $this->metadata()->get('automation_class'),
            'actions' => $actions,
            'affected_paths' => array_values(array_unique([...$files, ...$metadataFiles, ...$metadataDirectories])),
            'backup_paths' => $files,
            'requires_backup' => $this->metadata()->automationClass() === AutomationClass::AUTO_WITH_BACKUP || $files !== [],
            'rollback_supported' => $this->metadata()->rollbackSupported(),
            'verification_method' => $this->metadata()->get('verification_method'),
            'generated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function preflight(DoctorContext $context, RepairPlan $plan): void
    {
        if ($plan->repairId() !== $this->metadata()->id()) { throw new \RuntimeException('Repair plan identity changed.'); }
        foreach ($plan->actions() as $action) {
            $descriptor = $this->primitives()->descriptors()[(string)($action['type'] ?? '')] ?? null;
            if (!$descriptor || ($this->metadata()->rollbackSupported() && ($descriptor['reversible'] ?? false) !== true)) {
                throw new \RuntimeException('Repair contains an action without a supported rollback contract.');
            }
        }
    }

    private function actionPaths(array $action): array
    {
        $paths = array_merge(isset($action['path']) ? [$action['path']] : [], (array)($action['effects'] ?? []));
        $paths = array_values(array_unique(array_filter($paths, static fn($p) => is_string($p) && str_starts_with($p, '/'))));
        // A failed recursive creator can leave parents behind without ever creating its target.
        foreach ($paths as $path) {
            $parent = dirname($path);
            while (!file_exists($parent) && !is_link($parent) && dirname($parent) !== $parent) {
                $paths[] = $parent;
                $parent = dirname($parent);
            }
        }
        $paths = array_values(array_unique($paths));
        usort($paths, static fn(string $a, string $b) => strlen($a) <=> strlen($b));
        return $paths; // Parent preimages first, so reverse restoration removes children first.
    }

    public function backup(DoctorContext $context, RepairPlan $plan): BackupResult
    {
        $this->lastBackup = null;
        $paths = (array)$plan->get('backup_paths', []);
        foreach ($plan->actions() as $action) { $paths = array_merge($paths, $this->actionPaths((array)$action)); }
        $paths = array_values(array_unique($paths));
        $this->lastBackup = $paths === []
            ? $context->backups->none($context->executionId, $this->metadata()->id())
            : $context->backups->capture($context->executionId, $this->metadata()->id(), $paths, 'baseline-' . bin2hex(random_bytes(8)));
        return $this->lastBackup;
    }

    public function repair(DoctorContext $context, RepairPlan $plan): RepairResult
    {
        if ($this->metadata()->automationClass() === AutomationClass::REPORT_ONLY) {
            return new RepairResult(['repair_id' => $this->metadata()->id(), 'status' => RepairStatus::REPORT_ONLY->value, 'message' => 'This finding is report-only.', 'changes' => []]);
        }
        if ($this->metadata()->automationClass() === AutomationClass::PROHIBITED) {
            return new RepairResult(['repair_id' => $this->metadata()->id(), 'status' => RepairStatus::BLOCKED->value, 'message' => 'Automatic execution is prohibited.', 'changes' => []]);
        }
        $changes = []; $attempts = []; $attemptToken = bin2hex(random_bytes(8));
        try {
            foreach ($plan->actions() as $index => $action) {
                $paths = $this->actionPaths((array)$action);
                $stepBackup = $paths === [] ? null : $context->backups->capture($context->executionId, $this->metadata()->id() . '-action-' . $attemptToken . '-' . $index, $paths);
                $descriptor = $this->primitives()->descriptors()[(string)$action['type']];
                $coveredFailure = $paths !== [] && (($descriptor['category'] ?? '') === 'filesystem' || $action['type'] === 'artisan_command');
                $attempts[] = ['index' => $index, 'primitive' => $action['type'], 'state' => 'NOT_STARTED',
                    'backup' => $stepBackup?->toArray(), 'covered_failure' => $coveredFailure, 'change' => null, 'after' => []];
                $context->audit->event('doctor.action.started', 'Repair action intent persisted before mutation.', [
                    'repair_id' => $this->metadata()->id(), 'action_index' => $index, 'primitive' => $action['type'], 'backup' => $stepBackup?->toArray(),
                ], 'WARNING');
                $attempts[$index]['state'] = 'STARTED';
                try {
                    $change = $this->primitives()->execute((array)$action, $context);
                    $changes[] = $change; $attempts[$index]['change'] = $change; $attempts[$index]['state'] = 'COMPLETED';
                } catch (\SysAdmin\Doctor\sdk\PartialActionFailure $error) {
                    $changes[] = $error->change; $attempts[$index]['change'] = $error->change;
                    $attempts[$index]['state'] = 'FAILED_WITH_COMPENSATION_STATE';
                    throw $error;
                } finally {
                    foreach ($paths as $path) { $attempts[$index]['after'][$path] = $context->backups->snapshot($path); }
                }
            }
            return new RepairResult([
                'repair_id' => $this->metadata()->id(),
                'status' => RepairStatus::REPAIRED->value,
                'message' => 'Corrected: ' . rtrim((string) $this->metadata()->get('summary'), '.') . '.',
                'changes' => $changes,
                'action_attempts' => $attempts,
                'backup' => $this->lastBackup?->toArray(),
                'completed_at' => gmdate(DATE_ATOM),
            ]);
        } catch (\Throwable $exception) {
            return new RepairResult([
                'repair_id' => $this->metadata()->id(),
                'status' => RepairStatus::FAILED->value,
                'message' => $context->redactor->text($exception->getMessage()),
                'changes' => $changes,
                'action_attempts' => $attempts,
                'backup' => $this->lastBackup?->toArray(),
                'completed_at' => gmdate(DATE_ATOM),
            ]);
        }
    }

    public function verify(DoctorContext $context, RepairResult $result): VerificationResult
    {
        if (!$result->successful()) {
            return new VerificationResult(['repair_id' => $this->metadata()->id(), 'passed' => false, 'message' => 'Repair execution did not succeed.', 'evidence' => []]);
        }
        $probe = $this->probes->run((array) ($this->definition['verification'] ?? $this->definition['detection'] ?? []), $context);
        return new VerificationResult([
            'repair_id' => $this->metadata()->id(),
            'passed' => $probe->healthy(),
            'message' => $probe->healthy() ? 'Independent verification passed.' : 'Independent verification failed.',
            'evidence' => [$probe->toArray()],
            'verified_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function rollback(DoctorContext $context, RepairResult $result): RollbackResult
    {
        if (!$this->metadata()->rollbackSupported()) {
            return new RollbackResult(['repair_id' => $this->metadata()->id(), 'successful' => false, 'status' => 'UNSUPPORTED', 'message' => 'Rollback is not supported; manual recovery is required.']);
        }
        $errors = []; $observations = [];
        $attempts = (array)$result->get('action_attempts', []);
        if ($attempts === []) {
            foreach ((array)$result->get('changes', []) as $index => $change) {
                $attempts[] = ['index' => $index, 'state' => 'COMPLETED', 'change' => $change, 'backup' => null, 'after' => []];
            }
        }
        foreach (array_reverse($attempts) as $attempt) {
            $stepErrors = [];
            try {
                if (is_array($attempt['change'])) {
                    if (!$this->primitives()->rollback($attempt['change'], $context)) { throw new \RuntimeException('No primitive accepted the compensation.'); }
                } elseif (($attempt['state'] ?? '') !== 'NOT_STARTED' && empty($attempt['covered_failure'])) {
                    throw new \RuntimeException('The failing action has uncertain non-filesystem effects; manual reconciliation required.');
                }
            } catch (\Throwable $error) { $stepErrors[] = $error->getMessage(); }
            if (is_array($attempt['backup'])) {
                try {
                    $backup = new BackupResult($attempt['backup']);
                    if (!$context->backups->restore($backup, (array)$attempt['after'])) { throw new \RuntimeException('Action preimage restoration failed verification.'); }
                } catch (\Throwable $error) { $stepErrors[] = $error->getMessage(); }
            }
            $observations[] = ['action_index' => $attempt['index'], 'verified' => $stepErrors === [], 'errors' => $stepErrors];
            $errors = array_merge($errors, $stepErrors);
            try { $context->audit->event('doctor.action.rollback', 'Reverse action compensation attempted.', [
                'repair_id' => $this->metadata()->id(), 'observation' => end($observations),
            ], $stepErrors === [] ? 'WARNING' : 'ERROR'); }
            catch (\Throwable $error) { $errors[] = 'Rollback audit could not be persisted: ' . $error->getMessage(); }
        }
        $backup = is_array($result->get('backup')) ? new BackupResult($result->get('backup')) : $this->lastBackup;
        if ($backup !== null && $attempts === []) {
            try { $context->backups->restore($backup); } catch (\Throwable $error) { $errors[] = $error->getMessage(); }
        }
        if ($backup !== null && !$context->backups->verify($backup)) { $errors[] = 'Baseline restoration verification failed.'; }
        return new RollbackResult(['repair_id' => $this->metadata()->id(), 'successful' => $errors === [],
            'status' => $errors === [] ? RepairStatus::ROLLED_BACK->value : RepairStatus::ROLLBACK_FAILED->value,
            'message' => $errors === [] ? 'Prior state independently verified.' : $context->redactor->text(implode('; ', $errors)),
            'attempts' => $observations, 'errors' => $context->redactor->value($errors)]);
    }

    public function verifyRollback(DoctorContext $context, RepairResult $result): VerificationResult
    {
        $backup = is_array($result->get('backup')) ? new BackupResult($result->get('backup')) : $this->lastBackup;
        return new VerificationResult(['repair_id' => $this->metadata()->id(), 'passed' => $backup !== null && $context->backups->verify($backup),
            'message' => 'Independent prior-state byte/type/mode/ownership comparison.', 'evidence' => $backup?->get('records', []) ?? []]);
    }

    private function primitives(): RepairPrimitiveRegistry
    {
        return $this->sdk ?? new RepairPrimitiveRegistry($this->artifacts);
    }
}
