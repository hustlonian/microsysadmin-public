<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Quality;

final readonly class ReportOnlyCandidateRanker
{
    public function __construct(private string $modulesRoot)
    {
    }

    /** @return array<string, mixed> */
    public function rank(): array
    {
        $candidates = [];
        $signatures = [];
        foreach (glob($this->modulesRoot . '/*/Repairs/*.json') ?: [] as $repairPath) {
            $repair = $this->decode($repairPath);
            $metadata = (array) ($repair['metadata'] ?? []);
            if (($metadata['automation_class'] ?? null) !== 'REPORT_ONLY') {
                continue;
            }
            $id = (string) ($metadata['repair_id'] ?? '');
            $module = (string) ($metadata['module'] ?? '');
            $checkPaths = glob($this->modulesRoot . "/{$module}/Checks/{$id}-*.json") ?: [];
            $diagnosisPaths = glob($this->modulesRoot . "/{$module}/Diagnoses/{$id}-*.json") ?: [];
            $check = count($checkPaths) === 1 ? $this->decode($checkPaths[0]) : [];
            $diagnosis = count($diagnosisPaths) === 1 ? $this->decode($diagnosisPaths[0]) : [];
            $probe = (array) ($check['probe'] ?? $repair['detection'] ?? []);
            $signature = $this->probeSignature($probe, (array) ($check['supports'] ?? []));
            $candidate = [
                'repair_id' => $id,
                'module' => $module,
                'name' => (string) ($metadata['name'] ?? $id),
                'severity' => (string) ($metadata['severity'] ?? 'UNKNOWN'),
                'risk' => (string) ($metadata['risk_level'] ?? 'UNKNOWN'),
                'probe' => $probe,
                'probe_signature' => $signature,
                'supports' => (array) ($check['supports'] ?? []),
                'required_commands' => (array) ($check['requires_commands'] ?? []),
                'diagnosis_confidence' => (float) ($diagnosis['diagnosis']['confidence'] ?? 0),
            ];
            $candidates[$id] = $candidate;
            $signatures[$signature][] = $id;
        }

        $classificationCounts = [];
        $moduleCounts = [];
        $overlapGroups = [];
        foreach ($signatures as $signature => $ids) {
            if ($signature !== '' && count($ids) > 1) {
                $overlapGroups[] = ['signature' => $signature, 'repair_ids' => array_values($ids), 'count' => count($ids)];
            }
        }
        foreach ($candidates as $id => &$candidate) {
            $overlap = count($signatures[$candidate['probe_signature']] ?? []) > 1;
            [$classification, $score, $blockers, $nextAction] = $this->classify($candidate, $overlap);
            $candidate['classification'] = $classification;
            $candidate['commercial_priority_score'] = $score;
            $candidate['blockers'] = $blockers;
            $candidate['next_action'] = $nextAction;
            $candidate['overlap_repair_ids'] = $overlap ? array_values($signatures[$candidate['probe_signature']]) : [];
            $classificationCounts[$classification] = ($classificationCounts[$classification] ?? 0) + 1;
            $moduleCounts[$candidate['module']] = ($moduleCounts[$candidate['module']] ?? 0) + 1;
        }
        unset($candidate);
        uasort($candidates, static fn (array $a, array $b): int => [$b['commercial_priority_score'], $a['repair_id']] <=> [$a['commercial_priority_score'], $b['repair_id']]);
        ksort($classificationCounts);
        ksort($moduleCounts);
        usort($overlapGroups, static fn (array $a, array $b): int => [$b['count'], $a['signature']] <=> [$a['count'], $b['signature']]);
        return [
            'generated_at' => gmdate(DATE_ATOM),
            'report_only_count' => count($candidates),
            'classification_counts' => $classificationCounts,
            'module_counts' => $moduleCounts,
            'overlap_groups' => $overlapGroups,
            'candidates' => array_values($candidates),
        ];
    }

    /** @return array{string,int,array<int,string>,string} */
    private function classify(array $candidate, bool $overlap): array
    {
        $name = strtolower((string) $candidate['name']);
        $probe = (array) $candidate['probe'];
        $type = (string) ($probe['type'] ?? '');
        $path = (string) ($probe['path'] ?? $probe['pattern'] ?? '');
        if ($overlap) {
            return ['OVERLAP_CONSOLIDATION_REQUIRED', 10, ['same exact probe is owned by multiple permanent records'], 'Select one canonical repair owner before designing mutation logic.'];
        }
        if (preg_match('/\b(install|upgrade|security advisories|capacity pressure|low .*capacity|exhausted|read-only filesystem)\b/', $name)) {
            return ['PACKAGE_NETWORK_OR_DESTRUCTIVE_BOUNDARY', 5, ['requires package/network selection, data deletion, or host-wide recovery intent'], 'Keep advisory until an exact local source and reversible bounded plan exist.'];
        }
        if (preg_match('/\b(credential|author name|author email|origin remote|mail transport|mail host|sender address|application key)\b/', $name)) {
            return ['BUSINESS_INPUT_REQUIRED', 5, ['required value is customer- or organization-specific'], 'Collect an explicit business value; never synthesize it in zero-interaction mode.'];
        }
        if (preg_match('#(?:/var/mysql|/var/lib/redis|/htdocs|/\.git$)#', $path)) {
            return ['MISSING_DATA_RECOVERY_REQUIRED', 5, ['creating an empty path would hide missing customer or service data'], 'Require recovery evidence or a validated backup source.'];
        }
        if ($type === 'directory_exists' && str_starts_with($path, '/')) {
            return ['PRIVILEGED_DIRECTORY_CONTRACT_REVIEW', 75, ['distribution-specific owner/group/mode and service applicability must be proven'], 'Define one fixed token, exact identity evidence, and empty-directory rollback contract.'];
        }
        if ($type === 'file_exists' || $type === 'json_valid' || $type === 'file_contains') {
            return ['LOCAL_TEMPLATE_OR_SEMANTIC_PATCH_REVIEW', 65, ['exact approved source or semantic patch is not yet declared'], 'Locate a local immutable template or design a field-level semantic primitive.'];
        }
        if (in_array($type, ['path_readable', 'path_writable'], true)) {
            return ['OWNERSHIP_PERMISSION_CONTRACT_REVIEW', 50, ['desired UID, GID, mode, and current-state rollback guard are not yet explicit'], 'Derive ownership from an authoritative service contract before conversion.'];
        }
        if (in_array($type, ['command', 'command_stdout_empty', 'tcp_connect', 'dns_resolves'], true)) {
            return ['ROOT_CAUSE_VARIANT_RESEARCH', 35, ['a failed observation has multiple materially different causes'], 'Split the diagnosis into cause-specific permanent variants before mutation.'];
        }
        return ['MANUAL_RESEARCH_REQUIRED', 20, ['no deterministic reversible primitive contract has been identified'], 'Gather support evidence and define exact expected state before conversion.'];
    }

    private function probeSignature(array $probe, array $supports): string
    {
        // Exact cause ownership depends on the complete declarative probe, not
        // merely its broad type and path. Omitting fields such as `extension`,
        // `contains`, `table`, or command expectations falsely classifies
        // unrelated findings as duplicates. Applicability is part of the same
        // identity: two otherwise identical probes with disjoint environment
        // gates cannot detect the same cause in one execution.
        $identity = [
            'probe' => $this->canonicalize($probe),
            'supports' => $this->canonicalize($supports),
        ];
        return json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function decode(string $path): array
    {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException("Candidate ranker expected an object in {$path}.");
        }
        return $value;
    }
}
