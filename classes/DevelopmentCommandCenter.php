<?php

declare(strict_types=1);

namespace SysAdmin;

/** Read-only bridge. Trusted configuration binds private projections to exact projects. */
final class DevelopmentCommandCenter
{
    public function projects(): array
    {
        Auth::requireLogin();
        $result = [];
        foreach ((array) config_value('development.projects', []) as $id => $binding) {
            try {
                $role = $this->authorize((string) $id);
                $result[] = ['id' => (string) $id, 'name' => (string) ($binding['name'] ?? $id), 'role' => $role];
            } catch (HttpException) {
                // Unassigned project identifiers and names never leave the server.
            }
        }
        return $result;
    }

    public function authorize(string $project): string
    {
        Auth::requireLogin();
        $binding = $this->binding($project);
        $user = Auth::user();
        $organization = (int) ($binding['organization_id'] ?? 0);
        $stmt = Database::pdo()->prepare(
            "SELECT m.organization_role FROM organization_members m JOIN organizations o ON o.id = m.organization_id
             WHERE m.user_id = ? AND m.organization_id = ? AND m.status = 'active' AND o.status = 'active' LIMIT 1"
        );
        $stmt->execute([(int) $user['id'], $organization]);
        $membership = $stmt->fetchColumn();
        $role = $binding['users'][(int) $user['id']] ?? null;
        if ($organization < 1 || !$membership || (int) ($user['organization_id'] ?? 0) !== $organization
            || !in_array($role, ['owner', 'developer', 'agent', 'viewer'], true)) {
            throw new HttpException(403, 'Project access denied.');
        }
        // A tenant owner never inherits the legacy Administrator's platform bypass.
        return $role;
    }

    public function report(string $project): array
    {
        $this->authorize($project);
        $binding = $this->binding($project);
        $unavailable = ['available' => false, 'freshness' => 'UNAVAILABLE', 'project' => ['id' => $project,
            'name' => (string) ($binding['name'] ?? $project)], 'readiness' => 'NOT_VERIFIED'];
        $path = $binding['projection_file'] ?? null;
        if (!is_string($path) || !is_file($path) || is_link($path) || !is_readable($path)
            || filesize($path) > 2097152) {
            return $unavailable;
        }
        try {
            $report = json_decode((string) file_get_contents($path), true, 48, JSON_THROW_ON_ERROR);
            if (($report['schema_version'] ?? null) !== 1 || ($report['kind'] ?? '') !== 'DEVELOPMENT_COMMAND_CENTER'
                || ($report['project']['id'] ?? null) !== $project
                || ($report['project']['tenant_key'] ?? null) !== ($binding['tenant_key'] ?? null)
                || !preg_match('/\A[a-f0-9]{32}\z/', (string) ($report['source']['generation'] ?? ''))) {
                return $unavailable;
            }
            foreach (['project','source','mission','ownership','last_verified','definition_of_done','pending_approvals','risks'] as $required) {
                if (!isset($report[$required]) || !is_array($report[$required])) {
                    return $unavailable;
                }
            }
            $report = array_intersect_key($report, array_flip(['schema_version','kind','project','source','generated_at',
                'current_agent','mission','ownership','last_verified','history','roadmap','workstreams','gates',
                'definition_of_done','pending_approvals','readiness','lifecycle','risks','next_action']));
            $report['project'] = array_intersect_key($report['project'], array_flip(['id','name','owner','scope_version']));
            $report['source'] = array_intersect_key($report['source'], array_flip(['kind','generation','persisted_at','manifest_sha256']));
            $limits = ['history' => 100, 'roadmap' => 500, 'workstreams' => 100, 'gates' => 200];
            foreach ($limits as $key => $limit) {
                if (!isset($report[$key]) || !is_array($report[$key]) || count($report[$key]) > $limit) {
                    return $unavailable;
                }
            }
            $asOf = $this->timestamp($report['source']['persisted_at'] ?? null);
            if ($asOf === null) {
                return $unavailable;
            }
            $age = time() - $asOf;
            $report['freshness'] = $age < 0 ? 'CLOCK_SKEW' : ($age > 60 ? 'STALE' : 'RECENT');
            $heartbeat = $this->timestamp($report['ownership']['heartbeat_at'] ?? null);
            if ($age < 0 || $age > 60 || !$heartbeat || time() - $heartbeat < 0 || time() - $heartbeat > 60
                || ($report['ownership']['status'] ?? '') !== 'ACTIVE') {
                $report['ownership']['status'] = 'NOT_CONFIRMED_ACTIVE';
            }
            // Counts and historical acceptance never become overall release acceptance.
            $report['readiness'] = 'NOT_VERIFIED';
            $report['available'] = true;
            return $report;
        } catch (\Throwable) {
            return $unavailable;
        }
    }

    public function filter(array $report, array $filters): array
    {
        foreach (['agent', 'workstream', 'milestone'] as $key) {
            if (!empty($filters[$key]) && !is_scalar($filters[$key])) {
                throw new HttpException(422, 'Invalid history filter.');
            }
        }
        $report['history'] = array_values(array_filter($report['history'] ?? [], static function ($entry) use ($filters): bool {
            foreach (['agent','workstream','milestone'] as $key) {
                if (isset($filters[$key]) && (string) $filters[$key] !== '' && ($entry[$key] ?? '') !== $filters[$key]) {
                    return false;
                }
            }
            return true;
        }));
        return $report;
    }

    public function markdown(array $report): string
    {
        $lines = ['# Development Command Center', '', (string) $report['project']['name'],
            'Readiness: NOT_VERIFIED', 'Data status: ' . $report['freshness'],
            'Source revision: ' . ($report['source']['generation'] ?? 'NOT_AVAILABLE'), '', '## Accomplishments'];
        foreach ($report['history'] ?? [] as $entry) {
            $lines[] = '- ' . ($entry['observed_at'] ?? 'Unknown time') . ': ' . $entry['task'] . ' at ' . $entry['commit'] . ' (' . $entry['verification'] . ')';
        }
        $lines[] = "\n## LockGates";
        foreach ($report['gates'] ?? [] as $gate) {
            $lines[] = '- ' . $gate['id'] . ': ' . $gate['name'] . ' — ' . $gate['state'];
        }
        return implode("\n", $lines) . "\n";
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value)) {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : $time;
    }

    private function binding(string $project): array
    {
        if (!preg_match('/\A[a-z][a-z0-9-]{0,79}\z/', $project)) {
            throw new HttpException(404, 'Project not found.');
        }
        $binding = config_value('development.projects.' . $project);
        if (!is_array($binding)) {
            throw new HttpException(404, 'Project not found.');
        }
        return $binding;
    }
}
