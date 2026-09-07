<?php

declare(strict_types=1);

namespace SysAdmin;

final class SearchService
{
    public function global(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['files' => [], 'repositories' => [], 'databases' => [], 'logs' => [], 'scripts' => []];
        }

        $fileVault = new FileVault();
        $files = [];
        foreach ($fileVault->roots() as $root) {
            $files = array_merge($files, $fileVault->search($root, $query, 25));
            if (count($files) >= 25) {
                break;
            }
        }

        $repos = array_values(array_filter((new GitService())->repositories(), static fn (array $repo): bool => stripos($repo['path'], $query) !== false || stripos($repo['branch'], $query) !== false));
        $dbs = array_values(array_filter((new MysqlService())->databases(), static fn (array $db): bool => stripos($db['name'], $query) !== false));
        $logs = [];
        $logService = new LogService();
        foreach (array_keys($logService->files()) as $label) {
            $read = $logService->read($label, $query, 100);
            foreach ($read['rows'] as $row) {
                $logs[] = ['source' => $label, 'line' => $row['line']];
                if (count($logs) >= 20) {
                    break 2;
                }
            }
        }
        $scripts = array_values(array_filter((new ScriptService())->scripts(), static fn (array $script): bool => stripos($script['name'], $query) !== false || stripos($script['description'], $query) !== false));

        return compact('files', 'repos', 'dbs', 'logs', 'scripts');
    }
}
