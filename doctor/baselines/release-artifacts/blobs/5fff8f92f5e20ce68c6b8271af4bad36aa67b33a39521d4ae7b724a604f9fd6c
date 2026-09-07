<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Registry;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Contracts\DiagnosisInterface;
use SysAdmin\Doctor\engine\Support\Value\CheckResult;
use SysAdmin\Doctor\engine\Support\Value\DiagnosisMetadata;
use SysAdmin\Doctor\engine\Support\Value\DiagnosisResult;

final readonly class DefinitionDiagnosis implements DiagnosisInterface
{
    public function __construct(private array $definition)
    {
    }

    public function metadata(): DiagnosisMetadata { return new DiagnosisMetadata((array) $this->definition['metadata']); }
    public function supports(EnvironmentContext $environment): bool { return $environment->supportsModule((string) $this->metadata()->get('module')); }

    public function diagnose(DoctorContext $context, CheckResult $check): DiagnosisResult
    {
        $definition = (array) ($this->definition['diagnosis'] ?? []);
        $failed = $check->failed() || $check->status->value === 'WARNING';
        return new DiagnosisResult([
            'diagnosis_id' => $this->metadata()->id(),
            'check_id' => $check->metadata->id(),
            'condition_detected' => $failed,
            'condition' => (string) ($definition['condition'] ?? $check->message),
            'evidence' => array_map(static fn ($item): array => $item->toArray(), $check->evidence),
            'expected_state' => $definition['expected_state'] ?? null,
            'actual_state' => $failed ? $check->message : 'healthy',
            'probable_cause' => $failed ? (string) ($definition['probable_cause'] ?? 'The checked condition differs from policy.') : 'none',
            'confidence' => $failed ? (float) ($definition['confidence'] ?? 0.9) : 0.0,
            'related_repair_ids' => $failed ? array_values((array) ($definition['related_repair_ids'] ?? [])) : [],
            'automation_allowed' => $failed && (bool) ($definition['automation_allowed'] ?? false),
            'automation_reason' => $failed ? (string) ($definition['automation_reason'] ?? 'Operator review is required.') : 'No repair is required.',
            'diagnosed_at' => gmdate(DATE_ATOM),
        ]);
    }
}
