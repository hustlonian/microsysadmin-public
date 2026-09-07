<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;
use SysAdmin\Doctor\engine\Support\AutomationClass;
use SysAdmin\Doctor\engine\Support\RiskLevel;

final readonly class RepairMetadata extends ArrayValue
{
    private const REQUIRED = [
        'repair_id', 'module', 'name', 'summary', 'problem_description',
        'symptoms', 'probable_causes', 'severity', 'risk_level',
        'automation_class', 'default_execution_policy', 'supported_operating_systems',
        'supported_distributions', 'supported_versions', 'supported_web_servers',
        'supported_php_versions', 'supported_framework_versions', 'required_commands',
        'required_packages', 'required_permissions', 'required_services', 'dependencies',
        'conflicts', 'files_read', 'files_modified', 'directories_modified',
        'services_affected', 'packages_affected', 'databases_affected', 'network_effects',
        'backup_strategy', 'rollback_supported', 'destructive', 'estimated_duration',
        'verification_method', 'repair_version', 'introduced_in', 'deprecated',
        'replacement_repair_id', 'tags', 'documentation_reference',
    ];

    public function __construct(array $data)
    {
        foreach (self::REQUIRED as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException("Repair metadata is missing {$key}.");
            }
        }
        if (!preg_match('/^[A-Z]+-[0-9]{4}$/', (string) $data['repair_id'])) {
            throw new \InvalidArgumentException('Repair metadata has an invalid permanent ID.');
        }
        AutomationClass::from((string) $data['automation_class']);
        RiskLevel::from((string) $data['risk_level']);
        parent::__construct($data);
    }

    public function id(): string { return (string) $this->get('repair_id'); }
    public function module(): string { return (string) $this->get('module'); }
    public function automationClass(): AutomationClass { return AutomationClass::from((string) $this->get('automation_class')); }
    public function risk(): RiskLevel { return RiskLevel::from((string) $this->get('risk_level')); }
    public function rollbackSupported(): bool { return (bool) $this->get('rollback_supported'); }
}
