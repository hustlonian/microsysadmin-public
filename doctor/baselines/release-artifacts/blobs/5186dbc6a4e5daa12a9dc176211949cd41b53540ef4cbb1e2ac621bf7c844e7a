<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Contracts;

use SysAdmin\Doctor\engine\Context\DoctorContext;

interface RepairPrimitive
{
    /** @return array<int, string> */
    public function types(): array;

    /** @return array<string, mixed> */
    public function descriptor(string $type): array;

    /** @return array<string, mixed> */
    public function execute(array $action, DoctorContext $context): array;

    /**
     * Reverse primitive-owned state that is not covered by BackupManager.
     * Return true when the change belongs to this primitive.
     */
    public function rollback(array $change, DoctorContext $context): bool;
}
