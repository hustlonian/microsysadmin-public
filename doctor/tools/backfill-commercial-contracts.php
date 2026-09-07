#!/opt/lampp/bin/php
<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('STORAGE_PATH', APP_ROOT . '/storage');
require APP_ROOT . '/vendor/autoload.php';

use SysAdmin\Doctor\sdk\RepairPrimitiveRegistry;

$sdk = new RepairPrimitiveRegistry();
$descriptors = $sdk->descriptors();
$moduleRoots = [];
$updated = 0;
$ready = 0;
$debt = 0;
$advisory = 0;

$json = static fn (array $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$write = static function (string $path, string $contents): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException("Unable to create commercial-contract directory {$parent}.");
    }
    if (is_link($parent)) {
        throw new RuntimeException("Commercial-contract directory cannot be symbolic: {$parent}.");
    }
    $temporary = $parent . '/.' . basename($path) . '.commercial-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write commercial contract {$path}.");
    }
    chmod($temporary, 0644);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to install commercial contract {$path}.");
    }
};

foreach (glob(APP_ROOT . '/doctor/modules/*/Repairs/*.json') ?: [] as $repairPath) {
    $definition = json_decode((string) file_get_contents($repairPath), true, 512, JSON_THROW_ON_ERROR);
    $metadata = (array) ($definition['metadata'] ?? []);
    $id = (string) ($metadata['repair_id'] ?? '');
    $module = (string) ($metadata['module'] ?? '');
    if (!preg_match('/^[A-Z]+-[0-9]{4}$/', $id) || !preg_match('/^[a-z][a-z0-9-]*$/', $module)) {
        throw new RuntimeException("Invalid repair identity in {$repairPath}.");
    }
    $moduleRoot = dirname(dirname($repairPath));
    $moduleRoots[$module] = $moduleRoot;
    $automation = (string) ($metadata['automation_class'] ?? 'REPORT_ONLY');
    $executable = !in_array($automation, ['REPORT_ONLY', 'PROHIBITED'], true);
    $actionTypes = [];
    $sdkCovered = true;
    foreach ((array) ($definition['actions'] ?? []) as $action) {
        $type = (string) ($action['type'] ?? '');
        $actionTypes[] = $type;
        $sdkCovered = $sdkCovered && isset($descriptors[$type]);
    }
    $rollbackSupported = (bool) ($metadata['rollback_supported'] ?? false);
    $verificationPresent = (array) ($definition['verification'] ?? []) !== [];
    $quality = !$executable
        ? 'advisory-only'
        : ($rollbackSupported && $verificationPresent && $actionTypes !== [] && $sdkCovered ? 'contract-ready' : 'remediation-required');
    if ($quality === 'contract-ready') {
        $ready++;
    } elseif ($quality === 'remediation-required') {
        $debt++;
    } else {
        $advisory++;
    }
    $primaryPrimitive = $actionTypes[0] ?? null;
    $metadata['commercial_tier'] ??= $executable ? 'professional' : 'advisory';
    $metadata['customer_impact'] ??= $executable ? 'restores one bounded supported state' : 'provides evidence and operator guidance';
    $metadata['support_issue_family'] ??= $module;
    $metadata['safety_review'] = $quality;
    $definition['metadata'] = $metadata;
    $definition['rollback'] = [
        ...(array) ($definition['rollback'] ?? []),
        'supported' => $rollbackSupported,
        'strategy' => $rollbackSupported ? (string) ($metadata['backup_strategy'] ?? 'primitive-owned') : 'not-yet-implemented',
        'primitive' => $primaryPrimitive,
        'compensating_action' => $definition['rollback']['compensating_action'] ?? null,
    ];
    $definition['audit'] = [
        ...(array) ($definition['audit'] ?? []),
        'event_namespace' => (string) ($definition['audit']['event_namespace'] ?? ('doctor.repair.' . strtolower($id))),
        'primitive' => $primaryPrimitive,
        'record_plan_hash' => true,
        'record_backup_manifest' => true,
        'record_verification' => true,
        'redaction_required' => true,
    ];
    $definition['commercial'] = [
        ...(array) ($definition['commercial'] ?? []),
        'tier' => $metadata['commercial_tier'],
        'support_issue_family' => $metadata['support_issue_family'],
        'deterministic' => $executable ? $actionTypes !== [] : null,
        'bounded' => $executable ? $sdkCovered : null,
        'reversible' => $executable ? $rollbackSupported : null,
        'independently_verifiable' => $executable ? $verificationPresent : null,
        'quality_status' => $quality,
    ];
    $write($repairPath, $json($definition));

    $changelogPath = $moduleRoot . '/Changelog/' . $id . '.md';
    $classificationStart = '<!-- commercial-contract:start -->';
    $classificationEnd = '<!-- commercial-contract:end -->';
    $classification = implode("\n", [
        $classificationStart,
        '## Current commercial classification',
        '',
        "- Automation: `{$automation}`",
        '- Primitive: `' . ($primaryPrimitive ?? 'none') . '`',
        '- Rollback: `' . ($rollbackSupported ? 'supported' : 'technical-debt') . '`',
        '- Verification: `' . ($verificationPresent ? 'defined' : 'missing') . '`',
        "- Commercial status: `{$quality}`",
        $classificationEnd,
        '',
    ]);
    $changelog = is_file($changelogPath)
        ? (string) file_get_contents($changelogPath)
        : "# {$id}\n\nPermanent repair record classified for the commercial Doctor library.\n\n";
    $pattern = '/' . preg_quote($classificationStart, '/') . '.*?' . preg_quote($classificationEnd, '/') . '\\n?/s';
    if (str_contains($changelog, $classificationStart)) {
        $changelog = (string) preg_replace($pattern, $classification, $changelog, 1);
    } else {
        $changelog = rtrim($changelog) . "\n\n" . $classification;
    }
    $write($changelogPath, $changelog);
    $write($moduleRoot . '/Commercial/' . $id . '.json', $json([
        'repair_id' => $id,
        'commercial_tier' => $metadata['commercial_tier'],
        'customer_impact' => $metadata['customer_impact'],
        'support_issue_family' => $metadata['support_issue_family'],
        'automation_class' => $automation,
        'risk_level' => $metadata['risk_level'] ?? null,
        'sdk_primitives' => $actionTypes,
        'quality_status' => $quality,
        'rollback_supported' => $rollbackSupported,
        'verification_defined' => $verificationPresent,
    ]));
    $updated++;
}

foreach ($moduleRoots as $module => $moduleRoot) {
    $inventoryPath = $moduleRoot . '/repair-inventory.json';
    if (!is_file($inventoryPath)) {
        continue;
    }
    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($inventory as &$entry) {
        $commercialPath = $moduleRoot . '/Commercial/' . (string) ($entry['repair_id'] ?? '') . '.json';
        if (is_file($commercialPath)) {
            $commercial = json_decode((string) file_get_contents($commercialPath), true, 512, JSON_THROW_ON_ERROR);
            $entry['commercial_status'] = strtoupper(str_replace('-', '_', (string) ($commercial['quality_status'] ?? 'unclassified')));
        } else {
            $entry['commercial_status'] = 'RESEARCH_ONLY';
        }
    }
    unset($entry);
    $write($inventoryPath, $json($inventory));
}

echo sprintf(
    "Commercial contracts: %d repair definitions; %d contract-ready executable; %d executable needing rollback/quality remediation; %d advisory-only.\n",
    $updated,
    $ready,
    $debt,
    $advisory,
);
