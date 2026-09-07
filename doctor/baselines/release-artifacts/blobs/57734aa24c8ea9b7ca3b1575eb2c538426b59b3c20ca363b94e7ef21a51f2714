<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Registry;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Contracts\RepairInterface;
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
final class DefinitionRepair implements RepairInterface
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

    public function backup(DoctorContext $context, RepairPlan $plan): BackupResult
    {
        $paths = array_values(array_filter(
            (array) $plan->get('backup_paths', []),
            static fn (mixed $path): bool => is_string($path) && file_exists($path),
        ));
        $this->lastBackup = $paths === []
            ? $context->backups->none($context->executionId, $this->metadata()->id())
            : $context->backups->capture($context->executionId, $this->metadata()->id(), $paths);
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
        $changes = [];
        try {
            foreach ($plan->actions() as $action) {
                $changes[] = $this->primitives()->execute((array) $action, $context);
            }
            return new RepairResult([
                'repair_id' => $this->metadata()->id(),
                'status' => RepairStatus::REPAIRED->value,
                'message' => 'Corrected: ' . rtrim((string) $this->metadata()->get('summary'), '.') . '.',
                'changes' => $changes,
                'backup' => $this->lastBackup?->toArray(),
                'completed_at' => gmdate(DATE_ATOM),
            ]);
        } catch (\Throwable $exception) {
            return new RepairResult([
                'repair_id' => $this->metadata()->id(),
                'status' => RepairStatus::FAILED->value,
                'message' => $context->redactor->text($exception->getMessage()),
                'changes' => $changes,
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
            return new RollbackResult(['repair_id' => $this->metadata()->id(), 'successful' => false, 'status' => 'UNSUPPORTED', 'message' => 'Rollback is not supported.']);
        }
        try {
            $backup = $this->lastBackup;
            if ($backup === null && is_array($result->get('backup'))) {
                $backup = new BackupResult((array) $result->get('backup'));
            }
            if ($backup !== null) {
                $context->backups->restore($backup);
            }
            foreach (array_reverse((array) $result->get('changes', [])) as $change) {
                $this->primitives()->rollback((array) $change, $context);
            }
            return new RollbackResult(['repair_id' => $this->metadata()->id(), 'successful' => true, 'status' => RepairStatus::ROLLED_BACK->value, 'message' => 'Recorded state was restored.']);
        } catch (\Throwable $exception) {
            return new RollbackResult(['repair_id' => $this->metadata()->id(), 'successful' => false, 'status' => RepairStatus::ROLLBACK_FAILED->value, 'message' => $context->redactor->text($exception->getMessage())]);
        }
    }

    private function primitives(): RepairPrimitiveRegistry
    {
        return $this->sdk ?? new RepairPrimitiveRegistry($this->artifacts);
    }
}
