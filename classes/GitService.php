<?php

declare(strict_types=1);

namespace SysAdmin;

final class GitService implements \SysAdmin\Contracts\GitOperationsInterface
{
    private const REPOSITORY_CACHE_TTL = 30;

    public function repositories(): array
    {
        $cached = $this->readRepositoryCache();
        if ($cached !== null) {
            return $cached;
        }

        $repos = [];
        foreach (PathGuard::allowedRoots() as $root) {
            $this->scanRepos($root, 0, $repos);
        }

        usort($repos, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $repos = array_values(array_unique($repos, SORT_REGULAR));
        $this->writeRepositoryCache($repos);
        return $repos;
    }

    public function summary(): array
    {
        return array_slice(array_map(function (array $repo): array {
            return [
                'path' => $repo['path'],
                'branch' => $repo['branch'],
                'dirty' => $repo['dirty'],
            ];
        }, $this->repositories()), 0, 8);
    }

    public function detail(string $path): array
    {
        $runner = new CommandRunner();
        $status = $runner->git($path, ['status', '--short']);
        $branch = $runner->git($path, ['branch', '--show-current']);
        $log = $runner->git($path, ['log', '--oneline', '-8']);

        return [
            'path' => PathGuard::assertAllowedDirectory($path),
            'branch' => trim($branch['output']) ?: 'detached',
            'status' => trim($status['output']),
            'commits' => trim($log['output']),
        ];
    }

    public function action(string $path, string $action, string $message = ''): array
    {
        $runner = new CommandRunner();
        $result = match ($action) {
            'fetch' => $runner->git($path, ['fetch', '--all', '--prune'], 120),
            'pull' => $runner->git($path, ['pull', '--ff-only'], 120),
            'push' => $runner->git($path, ['push'], 120),
            'diff' => $runner->git($path, ['diff', '--stat'], 60),
            'commit' => $this->commit($runner, $path, $message),
            default => throw new \InvalidArgumentException('Git action is not whitelisted.'),
        };
        $this->invalidateRepositoryCache();
        return $result;
    }

    private function commit(CommandRunner $runner, string $path, string $message): array
    {
        $message = trim($message);
        if ($message === '' || strlen($message) > 200) {
            throw new \InvalidArgumentException('Commit message is required and must be under 200 characters.');
        }
        $runner->git($path, ['add', '-A'], 60);
        return $runner->git($path, ['commit', '-m', $message], 120);
    }

    private function scanRepos(string $dir, int $depth, array &$repos): void
    {
        if ($depth > 3 || count($repos) > 80 || !is_readable($dir)) {
            return;
        }
        if (is_dir($dir . '/.git')) {
            $detail = $this->quickStatus($dir);
            $repos[] = $detail;
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry[0] === '.' || in_array($entry, ['vendor', 'node_modules', 'storage'], true)) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->scanRepos($path, $depth + 1, $repos);
            }
        }
    }

    private function quickStatus(string $path): array
    {
        try {
            $runner = new CommandRunner();
            $branch = trim($runner->git($path, ['branch', '--show-current'], 5, false)['output']);
            $status = trim($runner->git($path, ['status', '--short', '--untracked-files=no'], 5, false)['output']);
            return ['path' => $path, 'branch' => $branch ?: 'detached', 'dirty' => $status !== '', 'status' => $status];
        } catch (\Throwable $exception) {
            return ['path' => $path, 'branch' => 'unknown', 'dirty' => false, 'status' => $exception->getMessage()];
        }
    }

    private function readRepositoryCache(): ?array
    {
        $path = $this->repositoryCachePath();
        if (!is_readable($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (
            !is_array($data)
            || !isset($data['created_at'], $data['repositories'])
            || !is_array($data['repositories'])
            || time() - (int) $data['created_at'] > self::REPOSITORY_CACHE_TTL
        ) {
            return null;
        }

        return $data['repositories'];
    }

    private function writeRepositoryCache(array $repositories): void
    {
        $json = json_encode([
            'created_at' => time(),
            'repositories' => $repositories,
        ], JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents($this->repositoryCachePath(), $json, LOCK_EX);
        }
    }

    private function invalidateRepositoryCache(): void
    {
        @file_put_contents($this->repositoryCachePath(), '{"created_at":0,"repositories":[]}', LOCK_EX);
    }

    private function repositoryCachePath(): string
    {
        return STORAGE_PATH . '/cache/git-repositories.json';
    }
}
