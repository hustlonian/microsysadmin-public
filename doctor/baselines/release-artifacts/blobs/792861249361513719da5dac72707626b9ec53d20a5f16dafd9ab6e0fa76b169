<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Scoring;

use SysAdmin\Doctor\engine\Support\CheckStatus;
use SysAdmin\Doctor\engine\Support\Value\CheckResult;
use SysAdmin\Doctor\engine\Support\Value\HealthScore;

final class HealthScorer
{
    private const SEVERITY_WEIGHT = ['INFO' => 1, 'LOW' => 2, 'MEDIUM' => 5, 'HIGH' => 10, 'CRITICAL' => 20];
    private const STATUS_FACTOR = ['PASS' => 1.0, 'WARNING' => 0.65, 'FAIL' => 0.0, 'ERROR' => 0.0, 'UNKNOWN' => 0.4, 'SKIPPED' => 1.0];

    /** @param array<int, string> $knownModules */
    public function __construct(private readonly array $knownModules = [])
    {
    }

    /** @param array<int, CheckResult> $checks */
    public function calculate(array $checks): HealthScore
    {
        $modules = [];
        foreach ($this->knownModules as $module) {
            if (is_string($module) && $module !== '') {
                $modules[$module] = ['possible' => 0.0, 'earned' => 0.0, 'critical_failures' => 0, 'warnings' => 0];
            }
        }
        $counts = array_fill_keys(array_map(static fn (CheckStatus $status): string => $status->value, CheckStatus::cases()), 0);
        $possible = 0.0;
        $earned = 0.0;
        foreach ($checks as $check) {
            $module = $check->metadata->module();
            $severity = strtoupper((string) $check->metadata->get('severity', 'MEDIUM'));
            $weight = self::SEVERITY_WEIGHT[$severity] ?? 5;
            $factor = self::STATUS_FACTOR[$check->status->value] ?? 0.0;
            $counts[$check->status->value]++;
            $modules[$module] ??= ['possible' => 0.0, 'earned' => 0.0, 'critical_failures' => 0, 'warnings' => 0];
            if ($check->status !== CheckStatus::SKIPPED) {
                $possible += $weight;
                $earned += $weight * $factor;
                $modules[$module]['possible'] += $weight;
                $modules[$module]['earned'] += $weight * $factor;
            }
            if ($severity === 'CRITICAL' && $check->failed()) {
                $modules[$module]['critical_failures']++;
            }
            if ($check->status === CheckStatus::WARNING) {
                $modules[$module]['warnings']++;
            }
        }
        $percentage = $possible > 0 ? round(($earned / $possible) * 100, 2) : 100.0;
        $moduleScores = [];
        foreach ($modules as $module => $score) {
            $score['percentage'] = $score['possible'] > 0 ? round(($score['earned'] / $score['possible']) * 100, 2) : 100.0;
            $moduleScores[$module] = $score;
        }
        return new HealthScore([
            'percentage' => $percentage,
            'grade' => $this->grade($percentage),
            'counts' => $counts,
            'critical_failures' => array_sum(array_column($moduleScores, 'critical_failures')),
            'warnings' => $counts['WARNING'],
            'manual_attention_items' => $counts['FAIL'] + $counts['ERROR'] + $counts['UNKNOWN'],
            'modules' => $moduleScores,
            'calculated_at' => gmdate(DATE_ATOM),
        ]);
    }

    private function grade(float $percentage): string
    {
        return match (true) {
            $percentage >= 97 => 'A+', $percentage >= 93 => 'A', $percentage >= 90 => 'A-',
            $percentage >= 87 => 'B+', $percentage >= 83 => 'B', $percentage >= 80 => 'B-',
            $percentage >= 77 => 'C+', $percentage >= 73 => 'C', $percentage >= 70 => 'C-',
            $percentage >= 60 => 'D', default => 'F',
        };
    }
}
