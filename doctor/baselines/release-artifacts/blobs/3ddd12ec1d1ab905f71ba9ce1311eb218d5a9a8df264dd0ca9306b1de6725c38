<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Quality;

use SysAdmin\Doctor\sdk\RepairPrimitiveRegistry;

final readonly class CommercialLibraryAuditor
{
    public function __construct(
        private string $modulesRoot,
        private RepairPrimitiveRegistry $sdk = new RepairPrimitiveRegistry(),
    ) {
    }

    /** @return array<string, mixed> */
    public function audit(): array
    {
        $checks = [];
        $diagnoses = [];
        $diagnosisByRepair = [];
        $checkIdsByRepair = [];
        $repairs = [];
        $indexedTestCases = [];
        $inventory = [];
        $inventorySources = [];
        $modules = [];
        $duplicateIds = [];
        foreach (glob($this->modulesRoot . '/*/module.json') ?: [] as $manifestPath) {
            $manifest = $this->decode($manifestPath);
            $module = (string) ($manifest['name'] ?? '');
            $moduleRoot = dirname($manifestPath);
            $modules[$module] = [
                'display_name' => $manifest['display_name'] ?? ucfirst($module),
                'definitions' => 0,
                'executable' => 0,
                'report_only' => 0,
                'rollback_debt' => 0,
                'quality_defects' => 0,
            ];
            foreach (glob($moduleRoot . '/Checks/*.json') ?: [] as $path) {
                $definition = $this->decode($path);
                $id = (string) ($definition['metadata']['check_id'] ?? '');
                if (isset($checks[$id])) {
                    $duplicateIds[] = ['kind' => 'check', 'id' => $id, 'paths' => [$checks[$id]['path'], $path]];
                } else {
                    $checks[$id] = ['definition' => $definition, 'path' => $path, 'module' => $module];
                }
            }
            foreach (glob($moduleRoot . '/Diagnoses/*.json') ?: [] as $path) {
                $definition = $this->decode($path);
                $id = (string) ($definition['metadata']['diagnosis_id'] ?? '');
                if (isset($diagnoses[$id])) {
                    $duplicateIds[] = ['kind' => 'diagnosis', 'id' => $id, 'paths' => [$diagnoses[$id]['path'], $path]];
                } else {
                    $diagnoses[$id] = ['definition' => $definition, 'path' => $path, 'module' => $module];
                }
                foreach ((array) ($definition['diagnosis']['related_repair_ids'] ?? []) as $repairId) {
                    $diagnosisByRepair[(string) $repairId][] = $id;
                    $checkIdsByRepair[(string) $repairId][] = (string) ($definition['metadata']['check_id'] ?? '');
                }
            }
            foreach (glob($moduleRoot . '/Repairs/*.json') ?: [] as $path) {
                $definition = $this->decode($path);
                $id = (string) ($definition['metadata']['repair_id'] ?? '');
                if (isset($repairs[$id])) {
                    $duplicateIds[] = ['kind' => 'repair', 'id' => $id, 'paths' => [$repairs[$id]['path'], $path]];
                } else {
                    $repairs[$id] = ['definition' => $definition, 'path' => $path, 'module' => $module, 'root' => $moduleRoot];
                }
            }
            $inventoryPath = $moduleRoot . '/' . (string) ($manifest['repair_inventory'] ?? 'repair-inventory.json');
            foreach (is_file($inventoryPath) ? $this->decode($inventoryPath) : [] as $entry) {
                $id = (string) ($entry['repair_id'] ?? '');
                if (isset($inventory[$id])) {
                    $duplicateIds[] = ['kind' => 'inventory', 'id' => $id, 'paths' => [$inventorySources[$id], $inventoryPath]];
                } else {
                    $inventory[$id] = [...$entry, 'module' => $module];
                    $inventorySources[$id] = $inventoryPath;
                }
            }
            $testIndexPath = $moduleRoot . '/Tests/cases.json';
            if (is_file($testIndexPath)) {
                $testIndex = $this->decode($testIndexPath);
                if (array_is_list($testIndex)) {
                    foreach ($testIndex as $position => $testCase) {
                        $repairId = (string) ($testCase['repair_id'] ?? '');
                        if ($repairId !== '') {
                            $indexedTestCases[] = [
                                'repair_id' => $repairId,
                                'module' => $module,
                                'path' => $testIndexPath,
                                'position' => $position,
                            ];
                        }
                    }
                }
            }
        }

        $descriptors = $this->sdk->descriptors();
        $counts = [
            'definitions' => count($repairs), 'checks' => count($checks), 'diagnoses' => count($diagnoses),
            'inventory_records' => count($inventory), 'executable' => 0, 'fully_automatic' => 0,
            'approval_required' => 0, 'report_only' => 0, 'prohibited' => 0,
        ];
        $coverageCounts = [
            'rollback' => 0, 'verification' => 0, 'diagnosis' => 0, 'documentation' => 0,
            'fixture' => 0, 'contract' => 0, 'audit_metadata' => 0, 'changelog' => 0,
            'commercial_metadata' => 0, 'sdk_actions' => 0,
        ];
        $defects = [
            'duplicate_ids' => $duplicateIds, 'orphan_checks' => [], 'orphan_diagnoses' => [],
            'unreachable_repairs' => [], 'unsafe_automation' => [], 'unknown_primitives' => [],
            'missing_documentation' => [], 'missing_fixtures' => [], 'missing_contracts' => [],
            'missing_changelog' => [], 'missing_commercial_metadata' => [], 'rollback_debt' => [],
            'audit_primitive_mismatch' => [], 'orphan_test_cases' => [],
            'duplicate_lifecycle_owners' => [],
        ];
        $actionSignatures = [];
        $commandSignatures = [];
        $probeSignatures = [];
        $lifecycleSignatures = [];

        foreach ($repairs as $id => $record) {
            $definition = (array) $record['definition'];
            $metadata = (array) ($definition['metadata'] ?? []);
            $module = (string) $record['module'];
            $automation = (string) ($metadata['automation_class'] ?? 'REPORT_ONLY');
            $executable = !in_array($automation, ['REPORT_ONLY', 'PROHIBITED'], true);
            $modules[$module]['definitions']++;
            if ($executable) {
                $counts['executable']++;
                $modules[$module]['executable']++;
                if (in_array($automation, ['AUTO_SAFE', 'AUTO_WITH_BACKUP'], true)) {
                    $counts['fully_automatic']++;
                } else {
                    $counts['approval_required']++;
                }
            } elseif ($automation === 'PROHIBITED') {
                $counts['prohibited']++;
            } else {
                $counts['report_only']++;
                $modules[$module]['report_only']++;
            }
            if (isset($diagnosisByRepair[$id])) {
                $coverageCounts['diagnosis']++;
            } else {
                $defects['unreachable_repairs'][] = $id;
            }

            $documentation = (string) ($metadata['documentation_reference'] ?? '');
            $documentationPath = dirname($this->modulesRoot) . '/' . ltrim($documentation, '/');
            if ($documentation !== '' && is_file($documentationPath)) {
                $coverageCounts['documentation']++;
            } else {
                $defects['missing_documentation'][] = $id;
                $modules[$module]['quality_defects']++;
            }
            $fixturePath = $record['root'] . '/Fixtures/' . $id . '.json';
            if (is_file($fixturePath)) {
                $coverageCounts['fixture']++;
                $fixture = $this->decode($fixturePath);
                if ((array) ($fixture['assertions'] ?? []) !== [] || (array) ($fixture['scenarios'] ?? []) !== []) {
                    $coverageCounts['contract']++;
                } else {
                    $defects['missing_contracts'][] = $id;
                }
            } else {
                $defects['missing_fixtures'][] = $id;
                $modules[$module]['quality_defects']++;
            }
            if (is_file($record['root'] . '/Changelog/' . $id . '.md')) {
                $coverageCounts['changelog']++;
            } else {
                $defects['missing_changelog'][] = $id;
                $modules[$module]['quality_defects']++;
            }
            if (is_file($record['root'] . '/Commercial/' . $id . '.json') && (array) ($definition['commercial'] ?? []) !== []) {
                $coverageCounts['commercial_metadata']++;
            } else {
                $defects['missing_commercial_metadata'][] = $id;
                $modules[$module]['quality_defects']++;
            }
            if ((array) ($definition['audit'] ?? []) !== []) {
                $coverageCounts['audit_metadata']++;
            }
            if (!$executable) {
                continue;
            }
            if ((bool) ($metadata['rollback_supported'] ?? false)) {
                $coverageCounts['rollback']++;
            } else {
                $defects['rollback_debt'][] = $id;
                $modules[$module]['rollback_debt']++;
                $modules[$module]['quality_defects']++;
            }
            if ((array) ($definition['verification'] ?? []) !== []) {
                $coverageCounts['verification']++;
            } else {
                $defects['unsafe_automation'][] = $id . ':missing-verification';
                $modules[$module]['quality_defects']++;
            }
            $allSdk = true;
            foreach ((array) ($definition['actions'] ?? []) as $action) {
                $type = (string) ($action['type'] ?? '');
                $signature = json_encode($action, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $actionSignatures[$signature][] = $id;
                if (isset($action['command'])) {
                    $commandSignatures[json_encode($action['command'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)][] = $id;
                    $commandText = implode(' ', array_map('strval', (array) $action['command']));
                    if (preg_match('/composer\s+update|reset\s+--hard|chmod\s+-R\s+0?777|drop\s+database|rm\s+-rf/i', $commandText)) {
                        $defects['unsafe_automation'][] = $id . ':prohibited-command';
                    }
                }
                if (!isset($descriptors[$type])) {
                    $allSdk = false;
                    $defects['unknown_primitives'][] = $id . ':' . $type;
                }
            }
            $declaredAuditPrimitive = (string) ($definition['audit']['primitive'] ?? '');
            $primaryActionPrimitive = (string) (($definition['actions'][0]['type'] ?? ''));
            if ($primaryActionPrimitive !== '' && $declaredAuditPrimitive !== $primaryActionPrimitive) {
                $defects['audit_primitive_mismatch'][] = $id . ':' . $declaredAuditPrimitive . '!=' . $primaryActionPrimitive;
                $modules[$module]['quality_defects']++;
            }
            if ($allSdk && (array) ($definition['actions'] ?? []) !== []) {
                $coverageCounts['sdk_actions']++;
            }
            $probeSignatures[json_encode($definition['verification'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)][] = $id;
            $relatedCheckIds = array_values(array_unique(array_filter((array) ($checkIdsByRepair[$id] ?? []))));
            $supports = count($relatedCheckIds) === 1 && isset($checks[$relatedCheckIds[0]])
                ? (array) ($checks[$relatedCheckIds[0]]['definition']['supports'] ?? [])
                : [];
            $actions = [];
            foreach ((array) ($definition['actions'] ?? []) as $action) {
                unset($action['description']);
                $actions[] = $action;
            }
            $lifecycleSignature = json_encode($this->canonicalize([
                'supports' => $supports,
                'detection' => (array) ($definition['detection'] ?? []),
                'actions' => $actions,
                'verification' => (array) ($definition['verification'] ?? []),
            ]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $lifecycleSignatures[$lifecycleSignature][] = $id;
            if (!empty($metadata['destructive'])) {
                $defects['unsafe_automation'][] = $id . ':destructive';
            }
        }

        $diagnosedChecks = [];
        foreach ($diagnoses as $id => $record) {
            $checkId = (string) ($record['definition']['metadata']['check_id'] ?? '');
            if (!isset($checks[$checkId])) {
                $defects['orphan_diagnoses'][] = $id;
            } else {
                $diagnosedChecks[$checkId] = true;
            }
        }
        foreach (array_keys($checks) as $id) {
            if (!isset($diagnosedChecks[$id])) {
                $defects['orphan_checks'][] = $id;
            }
        }
        foreach ($indexedTestCases as $testCase) {
            if (!isset($repairs[$testCase['repair_id']])) {
                $defects['orphan_test_cases'][] = $testCase;
                $modules[$testCase['module']]['quality_defects']++;
            }
        }

        $defects['duplicate_lifecycle_owners'] = $this->duplicates($lifecycleSignatures);
        foreach ($defects['duplicate_lifecycle_owners'] as $group) {
            foreach ((array) ($group['repair_ids'] ?? []) as $repairId) {
                $module = (string) ($repairs[$repairId]['module'] ?? '');
                if (isset($modules[$module])) {
                    $modules[$module]['quality_defects']++;
                }
            }
        }

        $executableDenominator = max(1, $counts['executable']);
        $definitionDenominator = max(1, $counts['definitions']);
        $coverage = [
            'automation_percent' => $this->percent($counts['executable'], $definitionDenominator),
            'fully_automatic_percent' => $this->percent($counts['fully_automatic'], $definitionDenominator),
            'rollback_percent' => $this->percent($coverageCounts['rollback'], $executableDenominator),
            'verification_percent' => $this->percent($coverageCounts['verification'], $executableDenominator),
            'diagnosis_percent' => $this->percent($coverageCounts['diagnosis'], $definitionDenominator),
            'documentation_percent' => $this->percent($coverageCounts['documentation'], $definitionDenominator),
            'contract_percent' => $this->percent($coverageCounts['contract'], $definitionDenominator),
            'audit_metadata_percent' => $this->percent($coverageCounts['audit_metadata'], $definitionDenominator),
            'changelog_percent' => $this->percent($coverageCounts['changelog'], $definitionDenominator),
            'commercial_metadata_percent' => $this->percent($coverageCounts['commercial_metadata'], $definitionDenominator),
            'sdk_action_percent' => $this->percent($coverageCounts['sdk_actions'], $executableDenominator),
        ];
        $cleanModules = [];
        $workModules = [];
        foreach ($modules as $name => &$module) {
            $module['quality_gate'] = $module['quality_defects'] === 0 ? 'PASS' : 'NEEDS_WORK';
            if ($module['quality_gate'] === 'PASS') {
                $cleanModules[] = $name;
            } else {
                $workModules[$name] = ['quality_defects' => $module['quality_defects'], 'rollback_debt' => $module['rollback_debt']];
            }
        }
        unset($module);
        $duplicateLogic = [
            'repeated_action_signatures' => $this->duplicates($actionSignatures),
            'repeated_command_signatures' => $this->duplicates($commandSignatures),
            'repeated_verification_signatures' => $this->duplicates($probeSignatures),
        ];
        $qualityAverage = array_sum([
            $coverage['rollback_percent'], $coverage['verification_percent'], $coverage['diagnosis_percent'],
            $coverage['documentation_percent'], $coverage['contract_percent'], $coverage['audit_metadata_percent'],
            $coverage['changelog_percent'], $coverage['commercial_metadata_percent'], $coverage['sdk_action_percent'],
        ]) / 9;
        $safetyPenalty = min(25.0,
            count($defects['unsafe_automation']) * 2.0
            + count($defects['unknown_primitives']) * 2.0
            + count($defects['duplicate_ids']) * 5.0
            + count($defects['duplicate_lifecycle_owners']) * 5.0
            + count($defects['orphan_test_cases']) * 2.0
        );
        $qualityScore = round(max(0.0, $qualityAverage - $safetyPenalty), 2);
        $scaleProgress = [
            'definition_target' => 5000,
            'definition_percent' => $this->percent($counts['definitions'], 5000),
            'executable_target' => 2500,
            'executable_percent' => $this->percent($counts['executable'], 2500),
        ];
        $scaleScore = ($scaleProgress['definition_percent'] + $scaleProgress['executable_percent']) / 2;
        $readiness = round(($qualityScore * 0.70) + ($scaleScore * 0.30), 2);

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'counts' => $counts,
            'coverage' => $coverage,
            'coverage_counts' => $coverageCounts,
            'modules' => $modules,
            'modules_completed' => $cleanModules,
            'modules_needing_work' => $workModules,
            'sdk_primitives' => $descriptors,
            'duplicate_logic' => $duplicateLogic,
            'defects' => $defects,
            'quality_score' => $qualityScore,
            'scale_progress' => $scaleProgress,
            'commercial_readiness_score' => $readiness,
        ];
    }

    public function markdown(array $report): string
    {
        $c = (array) $report['counts'];
        $v = (array) $report['coverage'];
        $lines = [
            '# Doctor Commercial Library Audit', '',
            'Generated: ' . (string) $report['generated_at'], '',
            '## Portfolio', '',
            '- Repair definitions: ' . $c['definitions'],
            '- Executable repairs: ' . $c['executable'],
            '- Fully automatic repairs: ' . $c['fully_automatic'],
            '- Approval-required repairs: ' . $c['approval_required'],
            '- Report-only repairs: ' . $c['report_only'],
            '- Permanent inventory records: ' . $c['inventory_records'], '',
            '## Coverage', '',
        ];
        foreach ($v as $key => $value) {
            $lines[] = '- ' . ucwords(str_replace('_', ' ', $key)) . ': ' . number_format((float) $value, 2) . '%';
        }
        $lines = [...$lines, '', '## Module quality', '',
            '- Completed: ' . (implode(', ', (array) $report['modules_completed']) ?: 'none'),
            '- Needing work: ' . (implode(', ', array_keys((array) $report['modules_needing_work'])) ?: 'none'), '',
            '## Technical debt', '',
            '- Duplicate definition or inventory IDs: ' . count((array) ($report['defects']['duplicate_ids'] ?? [])),
            '- Executable repairs missing automatic rollback: ' . count((array) ($report['defects']['rollback_debt'] ?? [])),
            '- Unsafe automation findings: ' . count((array) ($report['defects']['unsafe_automation'] ?? [])),
            '- Unknown SDK primitives: ' . count((array) ($report['defects']['unknown_primitives'] ?? [])),
            '- Audit primitive mismatches: ' . count((array) ($report['defects']['audit_primitive_mismatch'] ?? [])),
            '- Duplicate executable lifecycle owners: ' . count((array) ($report['defects']['duplicate_lifecycle_owners'] ?? [])),
            '- Orphaned indexed test cases: ' . count((array) ($report['defects']['orphan_test_cases'] ?? [])),
            '- Unreachable repairs: ' . count((array) ($report['defects']['unreachable_repairs'] ?? [])), '',
            '## Commercial readiness', '',
            '- Quality score: ' . number_format((float) ($report['quality_score'] ?? 0), 2) . '/100',
            '- Definition target progress: ' . number_format((float) ($report['scale_progress']['definition_percent'] ?? 0), 2) . '%',
            '- Executable target progress: ' . number_format((float) ($report['scale_progress']['executable_percent'] ?? 0), 2) . '%', '',
            '**Commercial readiness score: ' . number_format((float) $report['commercial_readiness_score'], 2) . '/100**', '',
        ];
        return implode("\n", $lines);
    }

    private function duplicates(array $signatures): array
    {
        $duplicates = [];
        foreach ($signatures as $signature => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1) {
                $duplicates[] = ['count' => count($ids), 'repair_ids' => $ids, 'signature' => $signature];
            }
        }
        usort($duplicates, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        return $duplicates;
    }

    private function percent(int $numerator, int $denominator): float
    {
        return round(($numerator / max(1, $denominator)) * 100, 2);
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
            throw new \RuntimeException("Commercial auditor expected JSON array or object in {$path}.");
        }
        return $value;
    }
}
