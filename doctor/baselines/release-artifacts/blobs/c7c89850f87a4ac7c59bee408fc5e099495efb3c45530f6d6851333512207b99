<?php

declare(strict_types=1);

return [
    'default' => 'manual',
    'policies' => [
        'manual' => [
            'automatic' => [],
            'requires_approval' => ['AUTO_SAFE', 'AUTO_WITH_BACKUP', 'APPROVAL_REQUIRED'],
        ],
        'safe' => [
            'automatic' => ['AUTO_SAFE'],
            'requires_approval' => ['AUTO_WITH_BACKUP', 'APPROVAL_REQUIRED'],
        ],
        'safe-with-backup' => [
            'automatic' => ['AUTO_SAFE', 'AUTO_WITH_BACKUP'],
            'requires_approval' => ['APPROVAL_REQUIRED'],
        ],
    ],
    'never_execute' => ['REPORT_ONLY', 'PROHIBITED'],
    'prohibited_actions' => [
        'drop_database', 'delete_unknown_data', 'recursive_world_writable',
        'destructive_git_reset', 'disable_active_access', 'erase_logs',
        'replace_production_app_key', 'generic_composer_update',
    ],
    'require_backup_for' => ['AUTO_WITH_BACKUP'],
    'minimum_diagnosis_confidence' => 0.80,
    'automatic_maximum_risk' => 'LOW',
];
