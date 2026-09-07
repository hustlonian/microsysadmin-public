<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Generation;

use SysAdmin\Doctor\engine\Support\AutomationClass;
use SysAdmin\Doctor\engine\Support\RiskLevel;
use SysAdmin\Doctor\sdk\RepairPrimitiveRegistry;

/**
 * Diagnosis-driven generator for complete executable repair packages.
 *
 * A diagnosis supplies one narrowly reviewed `repair_generation` blueprint.
 * Everything else—required metadata, exact verification, rollback contract,
 * audit schema, documentation, fixtures, contract tests, commercial metadata,
 * inventory entry, and changelog fragment—is produced deterministically.
 */
final readonly class RepairGenerator
{
    public function __construct(private RepairPrimitiveRegistry $primitives = new RepairPrimitiveRegistry())
    {
    }

    /** @return array<string, mixed> */
    public function generate(string $diagnosisPath, string $outputModulesRoot, bool $overwrite = false): array
    {
        $diagnosisPath = realpath($diagnosisPath) ?: $diagnosisPath;
        if (!is_file($diagnosisPath) || is_link($diagnosisPath)) {
            throw new \InvalidArgumentException('Repair Generator diagnosis JSON is unavailable.');
        }
        $diagnosis = $this->decode($diagnosisPath);
        $diagnosisMetadata = (array) ($diagnosis['metadata'] ?? []);
        $diagnosisBody = (array) ($diagnosis['diagnosis'] ?? []);
        $generation = (array) ($diagnosis['repair_generation'] ?? []);
        $module = (string) ($diagnosisMetadata['module'] ?? '');
        $checkId = (string) ($diagnosisMetadata['check_id'] ?? '');
        $diagnosisId = (string) ($diagnosisMetadata['diagnosis_id'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $module)
            || !preg_match('/^[A-Z]+-C[0-9]{4}$/', $checkId)
            || !preg_match('/^[A-Z]+-D[0-9]{4}$/', $diagnosisId)
            || $generation === []) {
            throw new \InvalidArgumentException('Diagnosis is missing valid module, lifecycle IDs, or repair_generation blueprint.');
        }

        $sourceModule = dirname(dirname($diagnosisPath));
        $checkPath = $this->definitionPath($sourceModule . '/Checks', $checkId);
        $check = $this->decode($checkPath);
        if (($check['metadata']['module'] ?? null) !== $module) {
            throw new \RuntimeException('Diagnosis and check modules do not match.');
        }
        $repairId = strtoupper((string) ($generation['repair_id'] ?? ($diagnosisBody['related_repair_ids'][0] ?? '')));
        if (!preg_match('/^[A-Z]+-[0-9]{4}$/', $repairId)
            || !in_array($repairId, array_map('strtoupper', (array) ($diagnosisBody['related_repair_ids'] ?? [])), true)) {
            throw new \InvalidArgumentException('Generated repair requires one permanent ID related by the diagnosis.');
        }

        $action = (array) ($generation['action'] ?? []);
        $primitiveType = strtolower((string) ($action['type'] ?? ''));
        $descriptors = $this->primitives->descriptors();
        $descriptor = $descriptors[$primitiveType] ?? null;
        if (!is_array($descriptor)) {
            throw new \InvalidArgumentException("Repair Generator does not know primitive {$primitiveType}.");
        }
        foreach ((array) ($descriptor['required_fields'] ?? []) as $field) {
            if (!array_key_exists((string) $field, $action) || $action[$field] === '' || $action[$field] === []) {
                throw new \InvalidArgumentException("Primitive {$primitiveType} requires {$field}.");
            }
        }
        if (isset($descriptor['allowed_actions'])
            && !in_array((string) ($action['action'] ?? ''), (array) $descriptor['allowed_actions'], true)) {
            throw new \InvalidArgumentException("Primitive {$primitiveType} action is outside its generation allowlist.");
        }
        if (isset($descriptor['allowed_commands'])
            && !in_array((string) ($action['command'] ?? ''), (array) $descriptor['allowed_commands'], true)) {
            throw new \InvalidArgumentException("Primitive {$primitiveType} command is outside its generation allowlist.");
        }
        if (isset($descriptor['allowed_key_values'])) {
            $allowedValues = (array) (((array) $descriptor['allowed_key_values'])[(string) ($action['key'] ?? '')] ?? []);
            if (!in_array((string) ($action['value'] ?? ''), $allowedValues, true)) {
                throw new \InvalidArgumentException("Primitive {$primitiveType} key/value transition is outside its generation allowlist.");
            }
        }
        if (isset($descriptor['allowed_targets'])
            && !in_array((string) ($action['target'] ?? ''), (array) $descriptor['allowed_targets'], true)) {
            throw new \InvalidArgumentException("Primitive {$primitiveType} target is outside its generation allowlist.");
        }
        $this->assertActionSafety($action);

        $automation = AutomationClass::from((string) ($generation['automation_class'] ?? 'APPROVAL_REQUIRED'));
        if (in_array($automation, [AutomationClass::REPORT_ONLY, AutomationClass::PROHIBITED], true)) {
            throw new \InvalidArgumentException('Executable Repair Generator accepts only executable automation classes.');
        }
        $risk = RiskLevel::from((string) ($generation['risk_level'] ?? 'LOW'));
        $backupPaths = array_values(array_unique(array_map('strval', (array) ($generation['backup_paths'] ?? []))));
        $rollbackAction = (array) ($generation['rollback_action'] ?? []);
        $primitiveReversible = ($descriptor['reversible'] ?? false) === true;
        if (!$primitiveReversible && $backupPaths === [] && $rollbackAction === []) {
            throw new \InvalidArgumentException('Generated executable repair requires automatic primitive rollback, a backup path, or an exact compensating rollback action.');
        }
        if ($automation === AutomationClass::AUTO_WITH_BACKUP && $backupPaths === [] && empty($descriptor['internal_backup'])) {
            throw new \InvalidArgumentException('AUTO_WITH_BACKUP generation requires at least one exact backup path.');
        }

        $checkMetadata = (array) ($check['metadata'] ?? []);
        $probe = (array) ($check['probe'] ?? []);
        if ($probe === [] || !isset($probe['type'])) {
            throw new \InvalidArgumentException('Generated repair requires an independently runnable check probe.');
        }
        $detectionProbe = (array) ($generation['detection'] ?? $probe);
        if ($detectionProbe === [] || !isset($detectionProbe['type'])) {
            throw new \InvalidArgumentException('Generated repair detection override must be an independently runnable probe.');
        }
        $title = trim((string) ($generation['name'] ?? $checkMetadata['name'] ?? $repairId));
        $summary = rtrim(trim((string) ($generation['summary'] ?? $checkMetadata['summary'] ?? $title)), '.') . '.';
        $severity = strtoupper((string) ($generation['severity'] ?? $checkMetadata['severity'] ?? 'MEDIUM'));
        $documentation = 'modules/' . $module . '/Docs/' . $repairId . '.md';
        $filesModified = array_values(array_unique(array_map('strval', (array) ($generation['files_modified'] ?? $this->actionPaths($action, false)))));
        $directoriesModified = array_values(array_unique(array_map('strval', (array) ($generation['directories_modified'] ?? ($primitiveType === 'create_directory' ? [$action['path']] : [])))));
        $requiredCommands = array_values(array_unique(array_map('strval', (array) ($generation['required_commands'] ?? $this->requiredCommands($action)))));
        $defaultPolicy = match ($automation) {
            AutomationClass::AUTO_SAFE => 'safe',
            AutomationClass::AUTO_WITH_BACKUP => 'safe-with-backup',
            default => 'manual',
        };
        $problem = (string) ($generation['problem_description'] ?? $diagnosisBody['condition'] ?? $summary);
        $probableCause = (string) ($diagnosisBody['probable_cause'] ?? 'The checked state differs from policy.');
        $confidence = (float) ($diagnosisBody['confidence'] ?? 0.9);
        if ($confidence < 0.8 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Generated executable repair requires diagnosis confidence from 0.80 through 1.00.');
        }

        $repair = [
            'metadata' => [
                'repair_id' => $repairId,
                'module' => $module,
                'name' => $title,
                'summary' => $summary,
                'problem_description' => $problem,
                'symptoms' => array_values((array) ($generation['symptoms'] ?? [$problem])),
                'probable_causes' => array_values((array) ($generation['probable_causes'] ?? [$probableCause])),
                'severity' => $severity,
                'risk_level' => $risk->value,
                'automation_class' => $automation->value,
                'default_execution_policy' => $defaultPolicy,
                'supported_operating_systems' => array_values((array) ($generation['supported_operating_systems'] ?? ['Linux'])),
                'supported_distributions' => array_values((array) ($generation['supported_distributions'] ?? ['Debian', 'Ubuntu', 'RHEL-family', 'XAMPP/LAMPP'])),
                'supported_versions' => array_values((array) ($generation['supported_versions'] ?? ['actively supported releases'])),
                'supported_web_servers' => array_values((array) ($generation['supported_web_servers'] ?? ['Apache', 'Nginx', 'XAMPP/LAMPP'])),
                'supported_php_versions' => array_values((array) ($generation['supported_php_versions'] ?? ['8.2+'])),
                'supported_framework_versions' => array_values((array) ($generation['supported_framework_versions'] ?? ['not framework-dependent'])),
                'required_commands' => $requiredCommands,
                'required_packages' => array_values((array) ($generation['required_packages'] ?? [])),
                'required_permissions' => array_values((array) ($generation['required_permissions'] ?? ['bounded local repair scope'])),
                'required_services' => array_values((array) ($generation['required_services'] ?? [])),
                'dependencies' => array_values((array) ($generation['dependencies'] ?? [])),
                'conflicts' => array_values((array) ($generation['conflicts'] ?? [])),
                'files_read' => array_values(array_unique([...$backupPaths, ...$this->actionPaths($action, true)])),
                'files_modified' => $filesModified,
                'directories_modified' => $directoriesModified,
                'services_affected' => array_values((array) ($generation['services_affected'] ?? [])),
                'packages_affected' => array_values((array) ($generation['packages_affected'] ?? [])),
                'databases_affected' => array_values((array) ($generation['databases_affected'] ?? [])),
                'network_effects' => array_values((array) ($generation['network_effects'] ?? [])),
                'backup_strategy' => (string) ($generation['backup_strategy'] ?? ($backupPaths === [] ? 'primitive-owned exact rollback' : 'exact path snapshot through BackupManager')),
                'rollback_supported' => true,
                'destructive' => false,
                'estimated_duration' => (string) ($generation['estimated_duration'] ?? '<1 minute'),
                'verification_method' => "Re-run the independent {$checkId} probe after execution.",
                'repair_version' => '1.0.0',
                'introduced_in' => (string) ($generation['introduced_in'] ?? '1.0.0'),
                'deprecated' => false,
                'replacement_repair_id' => null,
                'tags' => array_values(array_unique([...array_map('strval', (array) ($checkMetadata['tags'] ?? [])), 'sdk-generated', $primitiveType])),
                'documentation_reference' => $documentation,
                'commercial_tier' => (string) ($generation['commercial_tier'] ?? 'professional'),
                'customer_impact' => (string) ($generation['customer_impact'] ?? 'restores a bounded supported state'),
                'support_issue_family' => (string) ($generation['support_issue_family'] ?? $module),
                'safety_review' => 'generated-contract-required',
            ],
            'confidence' => $confidence,
            'detection' => $detectionProbe,
            'actions' => [[...$action, 'description' => $action['description'] ?? $summary]],
            'backup_paths' => $backupPaths,
            'verification' => $probe,
            'rollback' => [
                'supported' => true,
                'strategy' => (string) ($descriptor['rollback'] ?? 'backup-manager'),
                'primitive' => $primitiveType,
                'compensating_action' => $rollbackAction ?: null,
            ],
            'audit' => [
                'event_namespace' => 'doctor.repair.' . strtolower($repairId),
                'primitive' => $primitiveType,
                'record_plan_hash' => true,
                'record_backup_manifest' => true,
                'record_verification' => true,
                'redaction_required' => true,
            ],
            'commercial' => [
                'tier' => (string) ($generation['commercial_tier'] ?? 'professional'),
                'support_issue_family' => (string) ($generation['support_issue_family'] ?? $module),
                'deterministic' => true,
                'bounded' => true,
                'reversible' => true,
                'independently_verifiable' => true,
            ],
        ];

        $moduleOutput = rtrim($outputModulesRoot, '/') . '/' . $module;
        foreach (['Repairs', 'Docs', 'Fixtures', 'Tests', 'Changelog', 'Commercial'] as $folder) {
            $this->directory($moduleOutput . '/' . $folder);
        }
        $stem = $repairId . '-' . $this->slug($title);
        $existingRepairs = glob($moduleOutput . '/Repairs/' . $repairId . '-*.json') ?: [];
        if (count($existingRepairs) > 1) {
            throw new \RuntimeException('Repair Generator found duplicate existing implementation units for the permanent ID.');
        }
        $outputs = [
            'repair' => $existingRepairs[0] ?? ($moduleOutput . '/Repairs/' . $stem . '.json'),
            'documentation' => $moduleOutput . '/Docs/' . $repairId . '.md',
            'fixture' => $moduleOutput . '/Fixtures/' . $repairId . '.json',
            'contract_test' => $moduleOutput . '/Tests/' . $repairId . '.contract.json',
            'changelog' => $moduleOutput . '/Changelog/' . $repairId . '.md',
            'commercial' => $moduleOutput . '/Commercial/' . $repairId . '.json',
        ];
        foreach ($outputs as $path) {
            if (is_file($path) && !$overwrite) {
                throw new \RuntimeException("Repair Generator refuses to overwrite {$path} without explicit overwrite.");
            }
        }

        $fixture = [
            'repair_id' => $repairId,
            'diagnosis_id' => $diagnosisId,
            'check_id' => $checkId,
            'primitive' => $primitiveType,
            'healthy_fixture' => ['probe_expected' => true],
            'failure_fixture' => ['probe_expected' => false],
            'assertions' => ['detection', 'root-cause', 'exact-plan', 'backup', 'repair', 'verification', 'rollback', 'verification-after-rollback', 'audit', 'idempotency'],
        ];
        $contract = [
            'repair_id' => $repairId,
            'lifecycle' => ['detect-failure', 'diagnose', 'plan', 'backup', 'execute', 'verify-pass', 'rollback', 'verify-failure-again'],
            'primitive_descriptor' => $descriptor,
            'prohibited' => ['shell-string-command', 'unbounded-path', 'missing-verification', 'missing-rollback', 'network-without-declaration'],
        ];
        $commercial = [
            'repair_id' => $repairId,
            'commercial_tier' => $repair['metadata']['commercial_tier'],
            'customer_impact' => $repair['metadata']['customer_impact'],
            'support_issue_family' => $repair['metadata']['support_issue_family'],
            'automation_class' => $automation->value,
            'risk_level' => $risk->value,
            'quality_gate' => 'contract-suite-required',
        ];
        $documentationBody = $this->documentation($repair, $checkId, $diagnosisId, $descriptor);
        $this->write($outputs['repair'], $this->json($repair));
        $this->write($outputs['documentation'], $documentationBody);
        $this->write($outputs['fixture'], $this->json($fixture));
        $this->write($outputs['contract_test'], $this->json($contract));
        $this->write($outputs['changelog'], "# {$repairId}\n\nAdded executable repair: {$summary}\n\n- Primitive: `{$primitiveType}`\n- Automation: `{$automation->value}`\n- Risk: `{$risk->value}`\n- Verification: `{$checkId}`\n- Rollback: automatic\n");
        $this->write($outputs['commercial'], $this->json($commercial));
        $inventoryEntry = $this->inventoryEntry($repair, $descriptor);
        $this->mergeInventory($moduleOutput . '/repair-inventory.json', $inventoryEntry, $overwrite);

        return [
            'repair_id' => $repairId,
            'module' => $module,
            'primitive' => $primitiveType,
            'outputs' => $outputs,
            'inventory_entry' => $inventoryEntry,
            'repair' => $repair,
        ];
    }

    private function assertActionSafety(array $action): void
    {
        $serialized = json_encode($action, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (preg_match('/(?:reset\s+--hard|chmod\s+-R\s+0?777|drop\s+database|composer\s+update|rm\s+-rf)/i', $serialized)) {
            throw new \InvalidArgumentException('Repair Generator rejected a prohibited generic or destructive action.');
        }
        if (($action['type'] ?? '') === 'run_command' && !is_array($action['command'] ?? null)) {
            throw new \InvalidArgumentException('Repair Generator commands must be structured argument arrays.');
        }
        foreach ($action as $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                throw new \InvalidArgumentException('Repair Generator rejected a null-containing argument.');
            }
        }
    }

    private function requiredCommands(array $action): array
    {
        if (($action['type'] ?? '') !== 'run_command' || !is_array($action['command'] ?? null)) {
            return [];
        }
        $command = (string) ($action['command'][0] ?? '');
        return $command !== '' && !str_contains($command, '/') ? [$command] : [];
    }

    private function actionPaths(array $action, bool $read): array
    {
        $paths = [];
        foreach ($read ? ['path', 'source', 'target', 'baseline'] : ['path'] as $key) {
            if (isset($action[$key]) && is_string($action[$key]) && str_starts_with($action[$key], '/')) {
                $paths[] = $action[$key];
            } elseif (isset($action[$key]) && is_string($action[$key]) && str_starts_with($action[$key], '{')) {
                $paths[] = $action[$key];
            }
        }
        return array_values(array_unique($paths));
    }

    private function inventoryEntry(array $repair, array $descriptor): array
    {
        $metadata = (array) $repair['metadata'];
        return [
            'repair_id' => $metadata['repair_id'],
            'title' => $metadata['name'],
            'summary' => $metadata['summary'],
            'status' => 'ACTIVE',
            'priority' => in_array($metadata['severity'], ['CRITICAL', 'HIGH'], true) ? 'P1' : 'P2',
            'frequency' => 'SUPPORT_DERIVED',
            'severity' => $metadata['severity'],
            'detection_confidence' => 'HIGH',
            'automation_feasibility' => 'SDK_GENERATED',
            'verification_feasibility' => 'INDEPENDENT_PROBE',
            'rollback_feasibility' => 'AUTOMATIC',
            'risk' => $metadata['risk_level'],
            'automation_class' => $metadata['automation_class'],
            'implementation_status' => 'IMPLEMENTED',
            'test_status' => 'CONTRACT_REQUIRED',
            'fixture_status' => 'IMPLEMENTED',
            'documentation_status' => 'IMPLEMENTED',
            'commercial_status' => 'QUALITY_GATE_PENDING',
            'primitive' => $descriptor['type'] ?? null,
            'supported_versions' => $metadata['supported_versions'],
            'known_edge_cases' => [],
            'dependencies' => $metadata['dependencies'],
            'conflicts' => $metadata['conflicts'],
            'future_expansion_notes' => 'Promote commercial status after lifecycle contract execution.',
        ];
    }

    private function documentation(array $repair, string $checkId, string $diagnosisId, array $descriptor): string
    {
        $m = (array) $repair['metadata'];
        return "# {$m['repair_id']}: {$m['name']}\n\n"
            . "## Problem\n\n{$m['problem_description']}\n\n"
            . "## Detection and root cause\n\nCheck `{$checkId}` feeds diagnosis `{$diagnosisId}` at confidence {$repair['confidence']}.\n\n"
            . "## Exact repair\n\nUses Repair SDK primitive `{$descriptor['type']}` in category `{$descriptor['category']}`. Arguments are structured and bounded.\n\n"
            . "## Backup and rollback\n\n{$m['backup_strategy']}. Automatic rollback is required.\n\n"
            . "## Independent verification\n\n{$m['verification_method']}\n\n"
            . "## Commercial classification\n\nTier: `{$m['commercial_tier']}`. Risk: `{$m['risk_level']}`. Automation: `{$m['automation_class']}`.\n";
    }

    private function mergeInventory(string $path, array $entry, bool $overwrite): void
    {
        $inventory = is_file($path) ? $this->decode($path) : [];
        $found = false;
        foreach ($inventory as $index => $existing) {
            if (($existing['repair_id'] ?? null) !== $entry['repair_id']) {
                continue;
            }
            if (!$overwrite) {
                throw new \RuntimeException('Repair Generator inventory ID already exists.');
            }
            $inventory[$index] = $entry;
            $found = true;
            break;
        }
        if (!$found) {
            $inventory[] = $entry;
        }
        usort($inventory, static fn (array $a, array $b): int => strcmp((string) ($a['repair_id'] ?? ''), (string) ($b['repair_id'] ?? '')));
        $this->write($path, $this->json($inventory));
    }

    private function definitionPath(string $directory, string $id): string
    {
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $definition = $this->decode($path);
            if (($definition['metadata']['check_id'] ?? null) === $id) {
                return $path;
            }
        }
        throw new \RuntimeException("Repair Generator could not find lifecycle definition {$id}.");
    }

    private function decode(string $path): array
    {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException("Repair Generator JSON must contain an object or list: {$path}");
        }
        return $value;
    }

    private function directory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path))) {
            throw new \RuntimeException("Repair Generator cannot create output directory {$path}.");
        }
    }

    private function write(string $path, string $contents): void
    {
        $temporary = dirname($path) . '/.' . basename($path) . '.generator-' . bin2hex(random_bytes(5));
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($temporary);
            throw new \RuntimeException("Repair Generator cannot write {$path}.");
        }
        chmod($temporary, 0644);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException("Repair Generator cannot install {$path}.");
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function slug(string $value): string
    {
        return substr(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'), 0, 80) ?: 'GeneratedRepair';
    }
}
