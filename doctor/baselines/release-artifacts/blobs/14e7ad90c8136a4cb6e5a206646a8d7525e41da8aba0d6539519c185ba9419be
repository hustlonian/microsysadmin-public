<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Planning;

use SysAdmin\Doctor\engine\Support\AutomationClass;
use SysAdmin\Doctor\engine\Support\RiskLevel;
use SysAdmin\Doctor\engine\Support\Value\DiagnosisResult;
use SysAdmin\Doctor\engine\Support\Value\RepairMetadata;
use SysAdmin\Doctor\engine\Support\Value\RepairPlan;

final readonly class PolicyEngine
{
    public function __construct(private array $configuration)
    {
    }

    public function evaluate(RepairMetadata $metadata, DiagnosisResult $diagnosis, RepairPlan $plan, string $policy): PolicyDecision
    {
        $automation = $metadata->automationClass();
        if (in_array($automation->value, (array) ($this->configuration['never_execute'] ?? []), true) || $metadata->get('destructive', false)) {
            return new PolicyDecision(['allowed' => false, 'requires_approval' => false, 'reason' => 'Repair is report-only, prohibited, or destructive.', 'policy' => $policy]);
        }
        if ($diagnosis->confidence() < (float) ($this->configuration['minimum_diagnosis_confidence'] ?? 0.8)) {
            return new PolicyDecision(['allowed' => false, 'requires_approval' => true, 'reason' => 'Diagnosis confidence is below policy.', 'policy' => $policy]);
        }
        $profile = (array) (($this->configuration['policies'][$policy] ?? null) ?: ($this->configuration['policies']['manual'] ?? []));
        $automatic = in_array($automation->value, (array) ($profile['automatic'] ?? []), true);
        $requiresApproval = !$automatic || in_array($automation->value, (array) ($profile['requires_approval'] ?? []), true);
        if ($automatic && $metadata->risk() !== RiskLevel::LOW) {
            $requiresApproval = true;
        }
        if ($plan->requiresBackup() && $metadata->get('backup_strategy') === 'none') {
            return new PolicyDecision(['allowed' => false, 'requires_approval' => true, 'reason' => 'A required backup strategy is missing.', 'policy' => $policy]);
        }
        return new PolicyDecision(['allowed' => true, 'requires_approval' => $requiresApproval, 'reason' => $requiresApproval ? 'The generated plan requires operator approval.' : 'Active policy permits safe automatic execution.', 'policy' => $policy]);
    }
}
