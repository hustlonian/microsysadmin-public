<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\UpdateProviderInterface;

final class FilesystemUpdateProvider implements UpdateProviderInterface
{
    public function name(): string
    {
        return 'filesystem';
    }

    public function manifest(string $channel): array
    {
        $root = (string) config_value('updates.manifest_url', '');
        if ($root === '') {
            return ['available' => false, 'message' => 'No filesystem manifest is configured.'];
        }
        $path = is_dir($root) ? rtrim($root, '/') . '/' . $channel . '.json' : $root;
        if (!is_file($path) || !is_readable($path)) {
            return ['available' => false, 'message' => 'Release manifest is not available.'];
        }
        $manifest = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Release manifest is invalid.');
        }
        $manifest['manifest_source'] = $path;
        return $manifest;
    }
}
