<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Context;

use SysAdmin\Doctor\engine\Backup\BackupManager;
use SysAdmin\Doctor\engine\Filesystem\SafeFilesystem;
use SysAdmin\Doctor\engine\Logging\DoctorAuditLogger;
use SysAdmin\Doctor\engine\Process\ProcessRunner;
use SysAdmin\Doctor\engine\Security\SecretRedactor;

final readonly class DoctorContext
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public string $executionId,
        public EnvironmentContext $environment,
        public string $projectPath,
        public array $options,
        public ProcessRunner $process,
        public SafeFilesystem $filesystem,
        public BackupManager $backups,
        public DoctorAuditLogger $audit,
        public SecretRedactor $redactor,
    ) {
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
