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
        $role = $this->authorize($project);
        $binding = $this->binding($project);
        $unavailable = ['available' => false, 'freshness' => 'UNAVAILABLE', 'project' => ['id' => $project,
            'name' => (string) ($binding['name'] ?? $project)], 'readiness' => 'NOT_VERIFIED',
            'access' => ['role' => $role, 'organization_membership' => 'ACTIVE', 'project_assignment' => 'EXPLICIT',
                'mode' => 'READ_ONLY', 'owner_decisions' => 'NOT_CONNECTED']];
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
            $report['access'] = $unavailable['access'];
            $report = $this->domain($report, $binding);
            $report = $this->ownerReview($report, $binding, $role);
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

    /** Fixed trusted file only; no identity sharing, database writes or mutation proxy. */
    private function domain(array $report, array $binding): array
    {
        $report['domain'] = ['status' => 'NOT_ENROLLED'];
        $path = $binding['domain_projection_file'] ?? null;
        if ($path === null) {
            return $report;
        }
        $report['domain']['status'] = 'UNAVAILABLE';
        if (!is_string($path) || !is_file($path) || is_link($path) || !is_readable($path) || filesize($path) > 2097152) {
            return $report;
        }
        try {
            $domain = json_decode((string) file_get_contents($path), true, 48, JSON_THROW_ON_ERROR);
            if (($domain['schema_version'] ?? null) !== 1 || ($domain['kind'] ?? '') !== 'LOCKGATE_DOMAIN'
                || ($domain['project']['id'] ?? null) !== $report['project']['id']
                || ($domain['project']['tenant_key'] ?? null) !== ($binding['tenant_key'] ?? null)
                || ($domain['scope']['version'] ?? null) !== $report['project']['scope_version']
                || ($domain['source']['kind'] ?? '') !== 'WORKFLOW_DATABASE'
                || !is_int($domain['source']['revision'] ?? null) || $domain['source']['revision'] < 1
                || !preg_match('/\A[a-f0-9]{64}\z/', (string) ($domain['scope']['manifest_hash'] ?? ''))
                || !is_array($domain['gates'] ?? null) || count($domain['gates']) !== count($report['gates'])) {
                return $report;
            }
            if (!is_array($domain['scope']['definition_of_done'] ?? null) || !is_array($domain['scope']['workstreams'] ?? null)
                || $this->hash($domain['scope']['definition_of_done']) !== $this->hash($report['definition_of_done'])
                || $this->hash($domain['scope']['workstreams']) !== $this->hash($report['workstreams'])) {
                return $report;
            }
            $captured = $this->timestamp($domain['source']['captured_at'] ?? null);
            if ($captured === null) {
                return $report;
            }
            $age = time() - $captured;
            $freshness = $age < 0 ? 'CLOCK_SKEW' : ($age > 60 ? 'STALE' : 'RECENT');
            $indexed = [];
            foreach ($domain['gates'] as $gate) {
                if (!is_array($gate) || !is_string($gate['key'] ?? null) || isset($indexed[$gate['key']])) {
                    return $report;
                }
                $indexed[$gate['key']] = $gate;
            }
            $gates = [];
            $states = ['DRAFT','READY','ACTIVE','VERIFYING','VERIFIED','UNLOCKED','BLOCKED','FAILED','AWAITING_APPROVAL','REOPENED','SUPERSEDED'];
            foreach ($report['gates'] as $declared) {
                $gate = $indexed[$declared['id']] ?? [];
                $expected = $declared;
                $expected['milestone'] ??= $declared['id'];
                if (!is_array($gate['definition'] ?? null) || $this->hash($gate['definition']) !== $this->hash($expected)
                    || !in_array($gate['state'] ?? null, $states, true) || !in_array($gate['recorded_state'] ?? null, $states, true)
                    || !is_int($gate['revision'] ?? null) || $gate['revision'] < 1
                    || !preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', (string) ($gate['source_hash'] ?? ''))
                    || !preg_match('/\A[a-z][a-z0-9-]{1,40}\z/', (string) ($gate['environment'] ?? ''))
                    || !is_bool($gate['assessment']['accepted'] ?? null) || !is_array($gate['assessment']['unmet'] ?? null)
                    || count($gate['assessment']['unmet']) > 200) {
                    return $report;
                }
                if (in_array($gate['state'], ['VERIFIED','UNLOCKED'], true) && !$gate['assessment']['accepted']) {
                    return $report;
                }
                foreach ($gate['assessment']['unmet'] as $reason) {
                    if (!is_string($reason) || strlen($reason) > 5000) {
                        return $report;
                    }
                }
                // Only exact criterion assessments leave this adapter. Cached results
                // describe capture-time verification, never current owner acceptance.
                $evidence = [];
                foreach ($declared['criteria'] as $criterion) {
                    $item = $gate['assessment']['evidence'][$criterion['id']] ?? null;
                    if (!is_array($item) || !in_array($item['status'] ?? null,
                        ['PASS','FAIL','UNVERIFIED','TAMPERED','STALE','ARTIFACT_INVALID'], true)
                        || (($item['status'] ?? null) === 'PASS' && empty($item['evidence_id']))
                        || ($gate['assessment']['accepted'] && ($item['status'] ?? null) !== 'PASS')
                        || (!is_null($item['evidence_id'] ?? null) && (!is_string($item['evidence_id'])
                            || !preg_match('/\A[a-zA-Z0-9-]{1,200}\z/', $item['evidence_id'])))) {
                        return $report;
                    }
                    $evidence[$criterion['id']] = ['status' => $item['status'], 'evidence_id' => $item['evidence_id'] ?? null];
                }
                $declared['state'] = $freshness === 'RECENT' ? $gate['state'] : 'BLOCKED';
                if ($declared['state'] === 'ACTIVE') {
                    $heartbeat = $this->timestamp($gate['heartbeat_at'] ?? null);
                    if ($heartbeat === null || time() - $heartbeat < 0 || time() - $heartbeat > 60) {
                        $declared['state'] = 'BLOCKED';
                        $gate['assessment']['unmet'][] = 'Executor heartbeat expired; reconcile before takeover.';
                    }
                }
                $declared['domain'] = ['recorded_state' => $gate['recorded_state'], 'revision' => $gate['revision'],
                    'source_hash' => $gate['source_hash'], 'environment' => $gate['environment'],
                    'unmet' => $gate['assessment']['unmet'], 'heartbeat_at' => $gate['heartbeat_at'] ?? null,
                    'evidence' => $evidence];
                if ($freshness !== 'RECENT') {
                    $declared['domain']['unmet'][] = 'Workflow snapshot is stale or clock-skewed; current acceptance must be rechecked.';
                }
                $gates[] = $declared;
            }
            $report['gates'] = $gates;
            $report['domain'] = ['status' => $freshness, 'captured_at' => $domain['source']['captured_at'],
                'revision' => $domain['source']['revision'], 'manifest_hash' => $domain['scope']['manifest_hash']];
        } catch (\Throwable) {
            // Keep the authorized roadmap declaration visible; never invent live domain acceptance.
        }
        return $report;
    }

    private function hash(array $value): string
    {
        $sort = function (array $items) use (&$sort): array {
            foreach ($items as &$item) {
                if (is_array($item)) { $item = $sort($item); }
            }
            if (!array_is_list($items)) { ksort($items); }
            return $items;
        };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    /** Navigation only: Software Ops independently authenticates and authorizes the owner. */
    private function ownerReview(array $report, array $binding, string $role): array
    {
        $report['owner_review'] = ['status' => 'NOT_CONFIGURED'];
        $review = $binding['owner_review'] ?? null;
        if (!is_array($review) || $role !== 'owner') {
            return $report;
        }
        $origin = $review['base_url'] ?? null;
        $mapping = $review['bindings'][(int) Auth::user()['id']] ?? null;
        // A configured origin cannot contain credentials, a path, redirect, query or script scheme.
        if (!is_string($origin) || !preg_match('~\A(?:https://[a-zA-Z0-9.-]+|http://(?:127\.0\.0\.1|localhost))(?::[1-9][0-9]{0,4})?\z~', $origin)
            || !is_string($mapping) || !preg_match('/\A[a-zA-Z0-9-]{1,80}\z/', $mapping)) {
            $report['owner_review']['status'] = 'INVALID_CONFIGURATION';
            return $report;
        }
        $report['owner_review']['status'] = 'INDEPENDENT_LOGIN_REQUIRED';
        if (($report['domain']['status'] ?? null) !== 'RECENT') {
            $report['owner_review']['status'] = 'FRESH_WORKFLOW_REQUIRED';
            return $report;
        }
        foreach ($report['gates'] as &$gate) {
            if (empty($gate['owner_approval']) || empty($gate['domain'])) { continue; }
            $definition = $gate;
            // Use the original declaration, before host-only display fields are added.
            unset($definition['domain'], $definition['review_url']);
            $definition['state'] = 'DRAFT';
            $definition['milestone'] ??= $gate['id'];
            $gate['review_url'] = $origin . '/development/projects/' . rawurlencode($report['project']['id'])
                . '/owner-review/' . rawurlencode($gate['id']) . '?' . http_build_query([
                    'review_binding' => $mapping, 'revision' => $gate['domain']['revision'],
                    'source_hash' => $gate['domain']['source_hash'], 'environment' => $gate['domain']['environment'],
                    'definition_hash' => $this->hash($definition),
                ]);
        }
        unset($gate);
        return $report;
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
