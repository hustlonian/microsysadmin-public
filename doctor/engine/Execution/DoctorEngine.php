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
                $planData = $plan->toArray();
                $hashablePlan = [
                    'repair_id' => $planData['repair_id'] ?? null,
                    'severity' => $planData['severity'] ?? null,
                    'risk' => $planData['risk'] ?? null,
                    'automation_class' => $planData['automation_class'] ?? null,
                    'actions' => $planData['actions'] ?? [],
                    'affected_paths' => $planData['affected_paths'] ?? [],
                    'backup_paths' => $planData['backup_paths'] ?? [],
                    'requires_backup' => $planData['requires_backup'] ?? false,
                    'rollback_supported' => $planData['rollback_supported'] ?? false,
                    'verification_method' => $planData['verification_method'] ?? null,
                ];
                $hash = hash('sha256', json_encode($hashablePlan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $entry = ['repair' => $repair, 'diagnosis' => $diagnosis, 'detection' => $detection, 'plan' => $plan, 'policy' => $decision, 'plan_hash' => $hash];
                $plans[] = $entry;
                $context->audit->event('doctor.plan.generated', 'Doctor repair plan generated.', $this->serializePlan($entry));
            }
        }
        return $plans;
    }

    /** @param array<int, array<string, mixed>> $plans */
    public function execute(DoctorContext $context, array $plans, callable $approval): array
    {
        $results = [];
        $this->lock->acquire((int) $context->option('lock_wait_seconds', 2));
        try {
            foreach ($plans as $entry) {
                $repair = $entry['repair'];
                $plan = $entry['plan'];
                $decision = $entry['policy'];
                if (!$decision->allowed()) {
                    $results[] = ['repair' => new RepairResult(['repair_id' => $repair->metadata()->id(), 'status' => RepairStatus::BLOCKED->value, 'message' => $decision->get('reason'), 'changes' => []]), 'verification' => null, 'rollback' => null];
                    continue;
                }
                if ($decision->requiresApproval() && !$approval($entry)) {
                    $results[] = ['repair' => new RepairResult(['repair_id' => $repair->metadata()->id(), 'status' => RepairStatus::SKIPPED->value, 'message' => 'Generated plan was not approved.', 'changes' => []]), 'verification' => null, 'rollback' => null];
                    continue;
                }
                $context->audit->event('doctor.repair.started', 'Doctor repair execution started.', ['repair_id' => $repair->metadata()->id(), 'plan_hash' => $entry['plan_hash']], 'WARNING');
                try {
                    $backup = $repair->backup($context, $plan);
                    if (!$backup->successful()) {
                        throw new \RuntimeException('Required backup did not validate.');
                    }
                    $result = $repair->repair($context, $plan);
                    if (in_array((string) $result->get('status'), [RepairStatus::REPORT_ONLY->value, RepairStatus::BLOCKED->value, RepairStatus::SKIPPED->value], true)) {
                        $results[] = ['repair' => $result, 'verification' => null, 'rollback' => null, 'backup' => $backup];
                        $context->audit->event('doctor.repair.not_executed', 'Doctor finding was not executed.', $result->toArray(), 'INFO');
                        continue;
                    }
                    $verification = $repair->verify($context, $result);
                    $rollback = null;
                    if ($result->successful() && $verification->passed()) {
                        $result = new RepairResult([
                            ...$result->toArray(),
                            'status' => RepairStatus::VERIFIED->value,
                            'message' => rtrim((string) $result->get('message'), '.') . '. Independent verification passed.',
                        ]);
                    } elseif ($result->successful() && !$verification->passed()) {
                        if ($repair->metadata()->rollbackSupported()) {
                            $rollback = $repair->rollback($context, $result);
                            $result = new RepairResult([
                                ...$result->toArray(),
                                'status' => $rollback->successful() ? RepairStatus::ROLLED_BACK->value : RepairStatus::ROLLBACK_FAILED->value,
                                'message' => $rollback->successful()
                                    ? 'Verification failed; the recorded prior state was restored.'
                                    : 'Verification failed and automatic rollback also failed.',
                            ]);
                        } else {
                            $result = new RepairResult([
                                ...$result->toArray(),
                                'status' => RepairStatus::FAILED->value,
                                'message' => 'Planned actions completed, but independent verification failed.',
                            ]);
                        }
                    }
                    $results[] = ['repair' => $result, 'verification' => $verification, 'rollback' => $rollback, 'backup' => $backup];
                    $context->audit->event('doctor.repair.completed', 'Doctor repair execution completed.', [
                        'repair' => $result->toArray(), 'verification' => $verification->toArray(), 'rollback' => $rollback?->toArray(),
                    ], $verification->passed() ? 'INFO' : 'ERROR');
                } catch (\Throwable $exception) {
                    $result = new RepairResult(['repair_id' => $repair->metadata()->id(), 'status' => RepairStatus::FAILED->value, 'message' => $context->redactor->text($exception->getMessage()), 'changes' => []]);
                    $results[] = ['repair' => $result, 'verification' => null, 'rollback' => null];
                    $context->audit->event('doctor.repair.failed', 'Doctor repair execution failed.', $result->toArray(), 'ERROR');
                }
            }
        } finally {
            $this->lock->release();
        }
        return $results;
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
