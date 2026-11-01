<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Execution;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Planning\PolicyEngine;
use SysAdmin\Doctor\engine\Registry\DoctorRegistry;
use SysAdmin\Doctor\engine\Scoring\HealthScorer;
use SysAdmin\Doctor\engine\Support\RepairStatus;
use SysAdmin\Doctor\engine\Support\Value\CheckResult;
use SysAdmin\Doctor\engine\Support\Value\DiagnosisResult;
use SysAdmin\Doctor\engine\Support\Value\RepairPlan;
use SysAdmin\Doctor\engine\Support\Value\RepairResult;

final readonly class DoctorEngine
{
    public function __construct(
        private DoctorRegistry $registry,
        private PolicyEngine $policy,
        private HealthScorer $scorer,
        private ExecutionLock $lock,
    ) {
    }

    /** @return array<int, CheckResult> */
    public function scan(DoctorContext $context, array $modules = [], array $checkIds = []): array
    {
        $results = [];
        foreach ($this->registry->checks() as $check) {
            if ($modules !== [] && !in_array($check->metadata()->module(), $modules, true)) {
                continue;
            }
            if ($checkIds !== [] && !in_array($check->metadata()->id(), $checkIds, true)) {
                continue;
            }
            try {
                $result = $check->run($context);
            } catch (\Throwable $exception) {
                $result = new CheckResult(
                    $check->metadata(), \SysAdmin\Doctor\engine\Support\CheckStatus::ERROR,
                    $context->redactor->text($exception->getMessage()), [], 0, gmdate(DATE_ATOM)
                );
            }
            if ($result->status === \SysAdmin\Doctor\engine\Support\CheckStatus::SKIPPED) {
                $context->audit->event(
                    'doctor.check.not_applicable',
                    'Doctor check is outside the applicable execution set for this environment.',
                    $result->jsonSerialize(),
                    'INFO',
                );
                continue;
            }
            $results[] = $result;
            $context->audit->event('doctor.check.completed', 'Doctor check completed.', $result->jsonSerialize(), $result->failed() ? 'WARNING' : 'INFO');
        }
        return $results;
    }

    /** @param array<int, CheckResult> $checks @return array<int, DiagnosisResult> */
    public function diagnose(DoctorContext $context, array $checks): array
    {
        $results = [];
        $byId = [];
        foreach ($checks as $check) {
            $byId[$check->metadata->id()] = $check;
        }
        foreach ($this->registry->diagnoses() as $diagnosis) {
            $check = $byId[$diagnosis->metadata()->checkId()] ?? null;
            if ($check === null || !$diagnosis->supports($context->environment)) {
                continue;
            }
            $result = $diagnosis->diagnose($context, $check);
            if ((bool) $result->get('condition_detected', false)) {
                $results[] = $result;
                $context->audit->event('doctor.diagnosis.completed', 'Doctor diagnosis identified a cause.', $result->toArray(), 'WARNING');
            }
        }
        return $results;
    }

    /** @param array<int, DiagnosisResult> $diagnoses */
    public function plans(DoctorContext $context, array $diagnoses, string $policyName = 'manual', array $repairIds = []): array
    {
        $plans = [];
        foreach ($diagnoses as $diagnosis) {
            foreach ($diagnosis->repairIds() as $repairId) {
                if ($repairIds !== [] && !in_array($repairId, $repairIds, true)) {
                    continue;
                }
                $repair = $this->registry->repair($repairId);
                if ($repair === null || !$repair->supports($context->environment)) {
                    continue;
                }
                $detection = $repair->detect($context);
                if (!$detection->detected()) {
                    continue;
                }
                $plan = $repair->plan($context, $detection);
                $decision = $this->policy->evaluate($repair->metadata(), $diagnosis, $plan, $policyName);
                $hash = self::planHash($plan);
                $entry = ['repair' => $repair, 'diagnosis' => $diagnosis, 'detection' => $detection, 'plan' => $plan, 'policy' => $decision, 'plan_hash' => $hash];
                $plans[] = $entry;
                $context->audit->event('doctor.plan.generated', 'Doctor repair plan generated.', $this->serializePlan($entry));
            }
        }
        return $plans;
    }

    public static function planHash(RepairPlan $plan): string
    {
        $data = $plan->toArray(); $identity = [];
        foreach (['repair_id','severity','risk','automation_class','actions','affected_paths','backup_paths','requires_backup','rollback_supported','verification_method'] as $key) {
            $identity[$key] = $data[$key] ?? null;
        }
        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<int, array<string, mixed>> $plans */
    public function execute(DoctorContext $context, array $plans, callable $approval): array
    {
        $results = []; $this->lock->acquire((int)$context->option('lock_wait_seconds', 2));
        try {
            foreach ($plans as $entry) {
                $repair = $entry['repair']; $plan = $entry['plan']; $metadata = $repair->metadata();
                $backup = null; $verification = null; $rollback = null; $started = false; $identity = null;
                $lifecycle = ['PREFLIGHT'];
                try {
                    if ($plan->repairId() !== $metadata->id() || !hash_equals(self::planHash($plan), (string)$entry['plan_hash'])) {
                        throw new \RuntimeException('Exact approved plan identity changed.');
                    }
                    if (in_array($metadata->automationClass()->value, ['REPORT_ONLY','PROHIBITED'], true) || $metadata->get('destructive', false)) {
                        $results[] = $this->notExecuted($context, $metadata->id(), 'BLOCKED', 'Report-only, prohibited or destructive repairs cannot execute.'); continue;
                    }
                    $decision = $this->policy->evaluate($metadata, $entry['diagnosis'], $plan, (string)$entry['policy']->get('policy', 'manual'));
                    if (!$decision->allowed() || !$repair->supports($context->environment)) {
                        $results[] = $this->notExecuted($context, $metadata->id(), 'BLOCKED', (string)$decision->get('reason')); continue;
                    }
                    if ($repair instanceof \SysAdmin\Doctor\engine\Contracts\RecoveryVerifiableRepair) { $repair->preflight($context, $plan); }
                    $candidateIdentity = json_encode([$context->executionId, $context->projectPath, $metadata->id(), $entry['plan_hash']], JSON_THROW_ON_ERROR);
                    if ($this->lock->attempted($candidateIdentity)) {
                        $results[] = $this->notExecuted($context, $metadata->id(), 'SKIPPED', 'Duplicate or unresolved prior execution; preserve its approval and reconcile its journal.'); continue;
                    }
                    if (($decision->requiresApproval() || !$metadata->rollbackSupported()) && !$approval($entry)) {
                        $results[] = $this->notExecuted($context, $metadata->id(), 'SKIPPED', 'Generated exact plan was not approved.'); continue;
                    }
                    $lifecycle[] = 'AUTHORIZED';
                    $identity = json_encode([$context->executionId, $context->projectPath, $metadata->id(), $entry['plan_hash']], JSON_THROW_ON_ERROR);
                    if (!$this->lock->claim($identity)) {
                        $results[] = $this->notExecuted($context, $metadata->id(), 'SKIPPED', 'Duplicate or interrupted execution; reconcile the original durable journal before any new attempt.'); continue;
                    }
                    $context->audit->event('doctor.repair.started', 'Authorized exact repair intent claimed.', ['repair_id' => $metadata->id(), 'plan_hash' => $entry['plan_hash'], 'lifecycle' => $lifecycle], 'WARNING');
                    $backup = $repair->backup($context, $plan);
                    if (!$backup->successful() || ($plan->requiresBackup() && $backup->get('strategy') === 'none-required')) {
                        throw new \RuntimeException('Required backup did not validate.');
                    }
                    $lifecycle[] = 'BACKED_UP';
                    $context->audit->event('doctor.repair.backup', 'Prior state captured before execution.', ['repair_id' => $metadata->id(), 'backup' => $backup->toArray()]);
                    $lifecycle[] = 'EXECUTING'; $started = true;
                    $result = $repair->repair($context, $plan);
                    $lifecycle[] = 'VERIFYING';
                    $verification = $result->successful() ? $repair->verify($context, $result)
                        : new \SysAdmin\Doctor\engine\Support\Value\VerificationResult(['passed' => false, 'message' => 'Repair execution failed; no successful postcondition is claimed.']);
                    if ($result->successful() && $verification->passed()) {
                        $lifecycle[] = 'COMMITTED';
                        $result = new RepairResult([...$result->toArray(), 'status' => RepairStatus::VERIFIED->value, 'message' => 'Repair completed and independent verification passed.']);
                    } else {
                        $lifecycle[] = 'ROLLING_BACK';
                        [$result, $rollback] = $this->recover($context, $repair, $result);
                        $lifecycle[] = $rollback->successful() ? 'ROLLED_BACK' : 'RECOVERY_REQUIRED';
                    }
                } catch (\Throwable $error) {
                    $result = new RepairResult([...isset($result) ? $result->toArray() : [], 'repair_id' => $metadata->id(), 'status' => RepairStatus::FAILED->value,
                        'message' => $context->redactor->text($error->getMessage()), 'backup' => $backup?->toArray(), 'changes' => isset($result) ? $result->get('changes', []) : [],
                        'unknown_effects' => $started && !isset($result)]);
                    if ($started) {
                        $lifecycle[] = 'ROLLING_BACK';
                        [$result, $rollback] = $this->recover($context, $repair, $result);
                        $lifecycle[] = $rollback->successful() ? 'ROLLED_BACK' : 'RECOVERY_REQUIRED';
                    } else { $lifecycle[] = 'FAILED_BEFORE_EXECUTION'; }
                }
                $row = ['repair' => $result, 'verification' => $verification, 'rollback' => $rollback, 'backup' => $backup, 'lifecycle' => $lifecycle];
                $safe = $context->audit->sanitize(['repair' => $result->toArray(), 'verification' => $verification?->toArray(), 'rollback' => $rollback?->toArray(), 'backup' => $backup?->toArray(), 'lifecycle' => $lifecycle]);
                if ($identity !== null) { $this->lock->complete($identity, $safe); }
                try { $context->audit->event('doctor.repair.completed', 'Repair lifecycle finished.', $safe, $result->get('status') === 'VERIFIED' ? 'INFO' : 'ERROR'); }
                catch (\Throwable $error) { $row['audit_error'] = $context->redactor->text($error->getMessage()); } // fsynced journal remains the durable outcome.
                $results[] = $row; unset($result);
            }
        } finally { $this->lock->release(); }
        return $results;
    }

    private function notExecuted(DoctorContext $context, string $id, string $status, string $message): array
    {
        $context->audit->event('doctor.repair.not_executed', 'Repair execution was denied or already recorded.', ['repair_id' => $id, 'status' => $status, 'message' => $message]);
        return ['repair' => new RepairResult(['repair_id' => $id, 'status' => $status, 'message' => $message, 'changes' => []]), 'verification' => null, 'rollback' => null];
    }

    private function recover(DoctorContext $context, \SysAdmin\Doctor\engine\Contracts\RepairInterface $repair, RepairResult $result): array
    {
        try {
            $rollback = $repair->rollback($context, $result);
            if (!$repair instanceof \SysAdmin\Doctor\engine\Contracts\RecoveryVerifiableRepair) { throw new \RuntimeException('Independent rollback verifier unavailable; manual recovery evidence is required.'); }
            $verified = $repair->verifyRollback($context, $result);
            $ok = $rollback->successful() && $verified->passed() && !$result->get('unknown_effects', false);
            $rollback = new \SysAdmin\Doctor\engine\Support\Value\RollbackResult([...$rollback->toArray(), 'successful' => $ok, 'verification' => $verified->toArray()]);
        } catch (\Throwable $error) {
            $rollback = new \SysAdmin\Doctor\engine\Support\Value\RollbackResult(['successful' => false, 'message' => $context->redactor->text($error->getMessage())]);
        }
        return [new RepairResult([...$result->toArray(), 'status' => $rollback->successful() ? RepairStatus::ROLLED_BACK->value : RepairStatus::ROLLBACK_FAILED->value,
            'recovery_required' => !$rollback->successful(), 'failure_message' => $result->get('message'),
            'message' => $rollback->successful() ? 'Repair failed; prior state independently verified after rollback.' : 'Repair failed; rollback incomplete or unverified. Preserve backups and reconcile before retry.']), $rollback];
    }

    public function score(array $checks): \SysAdmin\Doctor\engine\Support\Value\HealthScore
    {
        return $this->scorer->calculate($checks);
    }

    public function serializePlan(array $entry): array
    {
        return [
            'repair_id' => $entry['repair']->metadata()->id(),
            'metadata' => $entry['repair']->metadata()->toArray(),
            'diagnosis' => $entry['diagnosis']->toArray(),
            'detection' => $entry['detection']->toArray(),
            'plan' => $entry['plan']->toArray(),
            'policy' => $entry['policy']->toArray(),
            'plan_hash' => $entry['plan_hash'],
        ];
    }
}
