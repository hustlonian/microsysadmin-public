<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\ScriptRun;

final class ScriptService implements \SysAdmin\Contracts\ScriptOperationsInterface
{
    public function scripts(): array
    {
        $scripts = [];
        foreach (glob(STORAGE_PATH . '/scripts/*.sh') ?: [] as $file) {
            $description = 'Bash script';
            $first = trim((string) strtok((string) file_get_contents($file), "\n"));
            if (str_starts_with($first, '# Description:')) {
                $description = trim(substr($first, strlen('# Description:')));
            }
            $scripts[] = [
                'name' => basename($file),
                'path' => $file,
                'description' => $description,
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
        return $scripts;
    }

    public function run(string $scriptName): array
    {
        $id = ScriptRun::create(basename($scriptName), Auth::id());
        $result = (new CommandRunner())->script(basename($scriptName));
        ScriptRun::finish($id, $result['ok'] ? 'completed' : 'failed', trim($result['output'] . "\n" . $result['error']), (int) $result['exit_code']);
        AuditLogger::info('script.run', ['script' => $scriptName, 'ok' => $result['ok']]);
        return $result + ['run_id' => $id];
    }
}
