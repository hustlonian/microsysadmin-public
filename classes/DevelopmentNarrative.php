<?php

declare(strict_types=1);

namespace SysAdmin;

/** Presentation labels only; the controller retains all progress and acceptance authority. */
final class DevelopmentNarrative
{
    public static function describe(string $operation): string
    {
        return match ($operation) {
            'reporting.sanitized_owner_snapshot' => 'Create a private progress report that leaves out credentials and internal data.',
            'reporting.live_development_report' => 'Update the development report automatically when work is checkpointed.',
            'lockgate.domain' => 'Build checks that require evidence and approval before work can advance.',
            'command-center.views.foundation' => 'Build the development overview, history, and roadmap.',
            'command-center.views' => 'Connect and test the development views inside MicroSysAdmin in an isolated environment.',
            'command-center.gates-approvals.backend' => 'Build and verify the backend checks that keep owner approvals tied to the right project and evidence.',
            'command-center.gates-approvals' => 'Build and test the owner approval screens and the evidence behind each decision.',
            'customer-zero.import' => 'Bring the Digital Empire workstreams and their recorded progress into the project system.',
            'command-center.acceptance' => 'Check that the whole development overview is accurate, private, and recoverable after interruption.',
            'microsysadmin.job_recovery.20261013' => 'Make interrupted background jobs recoverable and verify that recovery in isolation.',
            'laptop-recovery.live_inventory_authorization.20261014' => 'Wait for permission to inspect the real database inventory before planning real backups.',
            'laptop-recovery.a1_review' => 'Review the laptop recovery tools and decide the next permitted recovery step.',
            'phase6.codex_primary_publish' => 'Preserve the earlier development source and its publication evidence.',
            '' => 'No work has been recorded for this item.',
            default => 'Recorded work: ' . ucfirst(preg_replace('/[._-]+/', ' ', $operation)),
        };
    }

    public static function summary(array $report): array
    {
        $operation = (string) ($report['mission']['operation'] ?? '');
        $next = (string) ($report['mission']['next_operation'] ?? $report['next_action'] ?? '');
        // The explicit controller next operation leads; roadmap rows are not an inferred schedule.
        return [
            'current' => self::describe($operation),
            'next' => self::describe($next),
            'active' => ($report['ownership']['status'] ?? '') === 'ACTIVE' && ($report['freshness'] ?? '') === 'RECENT',
            'done' => array_slice($report['history'] ?? [], 0, 6),
            'decisions' => array_map(self::describe(...), $report['pending_approvals'] ?? []),
        ];
    }

    public static function story(array $report): array
    {
        if (empty($report['available'])) {
            return ['The development report is unavailable, so there is no reliable update to tell yet.'];
        }
        $summary = self::summary($report);
        $completed = [];
        foreach (array_reverse(array_slice($summary['done'], 0, 3)) as $entry) {
            $completed[] = match ($entry['task']) {
                'reporting.sanitized_owner_snapshot' => 'A private progress report was created so development could be followed without exposing credentials or internal data.',
                'reporting.live_development_report' => 'The reporting system was extended to update the development report automatically as work is recorded.',
                'lockgate.domain' => 'Checks were built to require evidence and approval before work can advance.',
                'command-center.views.foundation' => 'The foundation for the development overview, history, and roadmap was built.',
                'command-center.views' => 'The development views were connected to MicroSysAdmin and tested in an isolated environment.',
                'command-center.gates-approvals.backend' => 'The rules behind owner approvals were built and tested to keep decisions tied to the correct project and evidence.',
                'microsysadmin.job_recovery.20261013' => 'Background job recovery was implemented and checked in isolation, covering work interrupted before it finishes.',
                default => 'A verified checkpoint records work to ' . lcfirst(self::describe($entry['task'])),
            };
        }
        $story = [$completed ? 'The recent verified record tells this story: ' . implode(' ', $completed)
            : 'There are no verified completed checkpoints in this report yet.'];
        $operation = (string) ($report['mission']['operation'] ?? '');
        $story[] = $operation !== ''
            ? 'At the latest update, the focus was to ' . lcfirst($summary['current'])
                . ' The reported contributor was ' . ($report['current_agent'] ?? 'not recorded') . '.'
            : 'The report does not identify the current work yet.';
        $next = (string) ($report['mission']['next_operation'] ?? $report['next_action'] ?? '');
        $story[] = $next !== ''
            ? 'The recorded next step is to ' . lcfirst($summary['next'])
                . ' It remains subject to the required checks and permissions; no delivery date is recorded.'
            : 'A next step has not been recorded yet.';
        $decisions = [];
        foreach ($report['pending_approvals'] ?? [] as $decision) {
            $decisions[] = $decision === 'laptop-recovery.live_inventory_authorization.20261014'
                ? 'Separately, real backup planning is waiting for your permission to inspect the database inventory. That inspection has not been authorized by this report.'
                : 'A separate decision is pending: ' . self::describe($decision);
        }
        if ($decisions) {
            $story[] = implode(' ', $decisions);
        }
        $story[] = 'These are recorded development milestones, not confirmation that the whole product is finished or ready for production.';
        return $story;
    }
}
