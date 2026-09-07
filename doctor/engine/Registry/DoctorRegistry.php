<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Registry;

use ReflectionClass;
use SysAdmin\Doctor\engine\Contracts\CheckInterface;
use SysAdmin\Doctor\engine\Contracts\DiagnosisInterface;
use SysAdmin\Doctor\engine\Contracts\ModuleInterface;
use SysAdmin\Doctor\engine\Contracts\RepairInterface;

final class DoctorRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $modules = [];
    /** @var array<string, CheckInterface> */
    private array $checks = [];
    /** @var array<string, DiagnosisInterface> */
    private array $diagnoses = [];
    /** @var array<string, RepairInterface> */
    private array $repairs = [];

    public function __construct(private readonly string $modulesRoot)
    {
    }

    public function discover(): void
    {
        $this->modules = $this->checks = $this->diagnoses = $this->repairs = [];
        foreach (glob($this->modulesRoot . '/*/module.json') ?: [] as $manifestPath) {
            $manifest = $this->decode($manifestPath);
            $module = (string) ($manifest['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $module) || isset($this->modules[$module])) {
                throw new \RuntimeException("Invalid or duplicate Doctor module manifest: {$manifestPath}");
            }
            $directory = dirname($manifestPath);
            $manifest['path'] = $directory;
            $manifest['inventory'] = $this->inventoryFile($directory, $manifest);
            $this->modules[$module] = $manifest;
            $this->discoverPhpUnits($directory, $module, (string) ($manifest['repair_prefix'] ?? ''));
            $this->discoverDefinitionUnits($directory, $module, (string) ($manifest['repair_prefix'] ?? ''));
        }
        ksort($this->modules);
        ksort($this->checks);
        ksort($this->diagnoses);
        ksort($this->repairs);
        $this->validateInventoryCoverage();
    }

    public function modules(): array { return array_values($this->modules); }
    public function checks(?string $module = null): array { return $module === null ? array_values($this->checks) : array_values(array_filter($this->checks, static fn (CheckInterface $item): bool => $item->metadata()->module() === $module)); }
    public function diagnoses(?string $module = null): array { return $module === null ? array_values($this->diagnoses) : array_values(array_filter($this->diagnoses, static fn (DiagnosisInterface $item): bool => (string) $item->metadata()->get('module') === $module)); }
    public function repairs(?string $module = null): array { return $module === null ? array_values($this->repairs) : array_values(array_filter($this->repairs, static fn (RepairInterface $item): bool => $item->metadata()->module() === $module)); }
    public function repair(string $id): ?RepairInterface { return $this->repairs[$id] ?? null; }
    public function check(string $id): ?CheckInterface { return $this->checks[$id] ?? null; }

    public function inventory(): array
    {
        $entries = [];
        foreach ($this->modules as $module) {
            foreach ((array) ($module['inventory'] ?? []) as $entry) {
                $entry['module'] = $module['name'];
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    public function summary(): array
    {
        return [
            'modules' => count($this->modules),
            'checks' => count($this->checks),
            'diagnoses' => count($this->diagnoses),
            'repairs' => count($this->repairs),
            'inventory_entries' => count($this->inventory()),
        ];
    }

    private function discoverPhpUnits(string $directory, string $module, string $prefix): void
    {
        $before = get_declared_classes();
        foreach (['Module.php', 'Checks', 'Diagnoses', 'Repairs', 'Verifications', 'Rollbacks'] as $relative) {
            $path = $directory . DIRECTORY_SEPARATOR . $relative;
            if (is_file($path)) {
                require_once $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $files = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                require_once $file;
            }
        }
        foreach (array_diff(get_declared_classes(), $before) as $class) {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isInstantiable() || $reflection->getConstructor()?->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $instance = $reflection->newInstance();
            if ($instance instanceof ModuleInterface) {
                continue;
            }
            $this->register($instance, $module, $prefix, $class);
        }
    }

    private function discoverDefinitionUnits(string $directory, string $module, string $prefix): void
    {
        foreach (['Checks' => DefinitionCheck::class, 'Diagnoses' => DefinitionDiagnosis::class, 'Repairs' => DefinitionRepair::class] as $folder => $class) {
            foreach (glob($directory . '/' . $folder . '/*.json') ?: [] as $path) {
                $definition = $this->decode($path);
                $instance = new $class($definition);
                $this->register($instance, $module, $prefix, $path);
            }
        }
    }

    private function register(object $instance, string $module, string $prefix, string $source): void
    {
        if ($instance instanceof CheckInterface) {
            $id = $instance->metadata()->id();
            $this->assertUnit($id, $instance->metadata()->module(), $module, $prefix, $source, 'check');
            $this->insert($this->checks, $id, $instance, $source);
        } elseif ($instance instanceof DiagnosisInterface) {
            $id = $instance->metadata()->id();
            $this->assertUnit($id, (string) $instance->metadata()->get('module'), $module, $prefix, $source, 'diagnosis');
            $this->insert($this->diagnoses, $id, $instance, $source);
        } elseif ($instance instanceof RepairInterface) {
            $id = $instance->metadata()->id();
            $this->assertUnit($id, $instance->metadata()->module(), $module, $prefix, $source, 'repair');
            $this->insert($this->repairs, $id, $instance, $source);
        }
    }

    private function assertUnit(string $id, string $declaredModule, string $module, string $prefix, string $source, string $kind): void
    {
        if ($declaredModule !== $module) {
            throw new \RuntimeException("Doctor {$kind} {$id} module mismatch in {$source}.");
        }
        if ($prefix !== '' && !str_starts_with($id, $prefix . '-')) {
            throw new \RuntimeException("Doctor {$kind} {$id} has the wrong permanent prefix in {$source}.");
        }
    }

    private function insert(array &$collection, string $id, object $instance, string $source): void
    {
        if (isset($collection[$id])) {
            throw new \RuntimeException("Duplicate Doctor library ID {$id} in {$source}.");
        }
        $collection[$id] = $instance;
    }

    private function inventoryFile(string $directory, array $manifest): array
    {
        $name = (string) ($manifest['repair_inventory'] ?? 'repair-inventory.json');
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        return is_file($path) ? $this->decode($path) : [];
    }

    private function validateInventoryCoverage(): void
    {
        $inventoryIds = [];
        foreach ($this->inventory() as $entry) {
            $id = (string) ($entry['repair_id'] ?? '');
            if ($id === '' || isset($inventoryIds[$id])) {
                throw new \RuntimeException('Doctor inventory contains a missing or duplicate repair ID.');
            }
            $inventoryIds[$id] = true;
            $status = (string) ($entry['implementation_status'] ?? '');
            if (in_array($status, ['IMPLEMENTED', 'TESTED', 'RELEASED', 'REPORT_ONLY'], true) && !isset($this->repairs[$id])) {
                throw new \RuntimeException("Implemented Doctor inventory item {$id} has no discovered repair unit.");
            }
        }
        foreach (array_keys($this->repairs) as $id) {
            if (!isset($inventoryIds[$id])) {
                throw new \RuntimeException("Discovered Doctor repair {$id} is absent from its module inventory.");
            }
        }
    }

    private function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Doctor JSON document must contain an object or list: {$path}");
        }
        return $decoded;
    }
}
