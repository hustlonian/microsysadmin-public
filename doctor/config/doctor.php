<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'runtime_root' => '{STORAGE_PATH}/doctor',
    // Doctor is shared by the web service account and privileged CLI jobs.
    // Setgid keeps newly created runtime entries in the storage service group.
    'runtime_directory_mode' => 02770,
    'execution_retention_days' => 90,
    'report_retention_days' => 180,
    'default_timeout' => 20,
    'maximum_timeout' => 900,
    'maximum_output_bytes' => 1048576,
    'lock_wait_seconds' => 2,
    'default_project_path' => '{APP_ROOT}',
    'allowed_roots' => ['{APP_ROOT}', '{STORAGE_PATH}', '/tmp'],
    'read_only_roots' => ['/etc', '/var/log', '/proc', '/sys', '/opt/lampp'],
    'report_formats' => ['json', 'text', 'html', 'pdf-html'],
    'module_manifest' => 'module.json',
    'repair_inventory' => 'repair-inventory.json',
    'artifact_integrity' => [
        'baseline' => '{APP_ROOT}/doctor/baselines/release-artifacts/manifest.json',
        'enforcement' => 'enabled outside the developer security profile',
        'network_access' => false,
        'external_dependencies' => false,
    ],
];
