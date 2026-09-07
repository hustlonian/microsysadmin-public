<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Filesystem\ManagedArtifactCatalog;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Primitives\CommandPrimitive;
use SysAdmin\Doctor\sdk\Primitives\ArtisanPrimitive;
use SysAdmin\Doctor\sdk\Primitives\ComposerPrimitive;
use SysAdmin\Doctor\sdk\Primitives\CrontabPrimitive;
use SysAdmin\Doctor\sdk\Primitives\DoctorPrivilegedPrimitive;
use SysAdmin\Doctor\sdk\Primitives\EnvFilePrimitive;
use SysAdmin\Doctor\sdk\Primitives\FilesystemPrimitive;
use SysAdmin\Doctor\sdk\Primitives\ServicePrimitive;
use SysAdmin\Doctor\sdk\Primitives\TimeSyncPrimitive;
use SysAdmin\Doctor\sdk\Primitives\StaleLockPrimitive;
use SysAdmin\Doctor\sdk\Primitives\KnownDirectoryPrimitive;

final class RepairPrimitiveRegistry
{
    /** @var array<string, RepairPrimitive> */
    private array $handlers = [];
    /** @var array<int, RepairPrimitive> */
    private array $primitives = [];

    public function __construct(?ManagedArtifactCatalog $artifacts = null, array $additional = [])
    {
        $artifacts ??= new ManagedArtifactCatalog();
        foreach ([
            new FilesystemPrimitive($artifacts),
            new ArtisanPrimitive(),
            new ComposerPrimitive(),
            new CommandPrimitive(),
            new ServicePrimitive(),
            new DoctorPrivilegedPrimitive(),
            new CrontabPrimitive(),
            new TimeSyncPrimitive(),
            new EnvFilePrimitive(),
            new StaleLockPrimitive(),
            new KnownDirectoryPrimitive(),
            ...$additional,
        ] as $primitive) {
            if (!$primitive instanceof RepairPrimitive) {
                throw new \InvalidArgumentException('Repair SDK extensions must implement RepairPrimitive.');
            }
            foreach ($primitive->types() as $type) {
                if ($type === '' || isset($this->handlers[$type])) {
                    throw new \RuntimeException("Duplicate or empty Repair SDK primitive type {$type}.");
                }
                $this->handlers[$type] = $primitive;
            }
            $this->primitives[] = $primitive;
        }
        ksort($this->handlers);
    }

    public function supports(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /** @return array<string, array<string, mixed>> */
    public function descriptors(): array
    {
        $descriptors = [];
        foreach ($this->handlers as $type => $handler) {
            $descriptors[$type] = $handler->descriptor($type);
        }
        return $descriptors;
    }

    /** @return array<string, mixed> */
    public function execute(array $action, DoctorContext $context): array
    {
        $type = strtolower(trim((string) ($action['type'] ?? '')));
        $handler = $this->handlers[$type] ?? null;
        if ($handler === null) {
            throw new \RuntimeException("Unsupported Repair SDK primitive {$type}.");
        }
        $result = $handler->execute($action, $context);
        $context->audit->event('doctor.primitive.executed', 'Repair SDK primitive executed.', [
            'primitive' => $type,
            'category' => $handler->descriptor($type)['category'] ?? 'unknown',
            'change' => $result,
        ], 'WARNING');
        return $result;
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        foreach ($this->primitives as $handler) {
            if (!$handler->rollback($change, $context)) {
                continue;
            }
            $context->audit->event('doctor.primitive.rolled_back', 'Repair SDK primitive state was rolled back.', [
                'change_type' => $change['type'] ?? null,
                'path' => $change['path'] ?? null,
            ], 'WARNING');
            return true;
        }
        return false;
    }
}
