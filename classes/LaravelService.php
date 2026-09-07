<?php

declare(strict_types=1);

namespace SysAdmin;

final class LaravelService implements \SysAdmin\Contracts\ComposerOperationsInterface, \SysAdmin\Contracts\ArtisanOperationsInterface
{
    public function projects(): array
    {
        $projects = [];
        foreach (PathGuard::allowedRoots() as $root) {
            $this->scan($root, 0, $projects);
        }
        return $projects;
    }

    public function runArtisan(string $project, string $command, bool $allowAny = false): array
    {
        return (new CommandRunner())->artisan($project, $command, $allowAny);
    }

    public function runComposer(string $project, string $command): array
    {
        return (new CommandRunner())->composer($project, $command);
    }

    private function scan(string $dir, int $depth, array &$projects): void
    {
        if ($depth > 3 || count($projects) > 80 || !is_readable($dir)) {
            return;
        }
        if (is_file($dir . '/artisan') && is_file($dir . '/composer.json')) {
            $projects[] = [
                'path' => $dir,
                'name' => basename($dir),
                'env' => is_file($dir . '/.env') ? 'present' : 'missing',
                'storage_link' => is_link($dir . '/public/storage') ? 'linked' : 'not linked',
            ];
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry[0] === '.' || in_array($entry, ['vendor', 'node_modules', 'storage'], true)) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->scan($path, $depth + 1, $projects);
            }
        }
    }
}
