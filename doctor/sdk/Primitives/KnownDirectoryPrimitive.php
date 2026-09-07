<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Support\PrivilegedHelperClient;

final readonly class KnownDirectoryPrimitive implements RepairPrimitive
{
    public function __construct(private PrivilegedHelperClient $helper = new PrivilegedHelperClient())
    {
    }

    public function types(): array
    {
        return ['create_known_directory'];
    }

    public function descriptor(string $type): array
    {
        return [
            'type' => $type,
            'category' => 'privileged-filesystem',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => 'remove only the exact helper-created directory while its identity is unchanged and it remains empty',
            'internal_backup' => true,
            'safety_class' => 'fixed-token-empty-directory',
            'required_fields' => ['target', 'path'],
            'allowed_targets' => ['xampp-logs', 'xampp-ssl-key'],
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $target = (string) ($action['target'] ?? '');
        $expectedPath = $this->path($target);
        if ($expectedPath === '' || (string) ($action['path'] ?? '') !== $expectedPath) {
            throw new \RuntimeException('Known-directory action does not match an exact reviewed target.');
        }
        $result = $this->helper->doctor($context, 'create-known-directory', [$target], (int) ($action['timeout'] ?? 60));
        $stdout = (string) ($result['stdout'] ?? '');
        $reportedPath = $this->value($stdout, 'DOCTOR_DIRECTORY_PATH');
        $identity = $this->value($stdout, 'DOCTOR_DIRECTORY_IDENTITY');
        $changed = $this->value($stdout, 'DOCTOR_NO_CHANGE') !== '1';
        if ($reportedPath !== $expectedPath
            || !preg_match('/^[0-9]+:[0-9]+:[0-7]{3,4}:[0-9]+:[0-9]+$/', $identity)
            || !is_dir($expectedPath)
            || is_link($expectedPath)
            || $this->identity($expectedPath) !== $identity) {
            throw new \RuntimeException('Known-directory helper result failed exact identity validation.');
        }
        return [
            'type' => 'known_directory',
            'target' => $target,
            'path' => $expectedPath,
            'identity' => $identity,
            'created' => $changed,
            'description' => $action['description'] ?? 'Created one fixed privileged directory.',
        ];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'known_directory') {
            return false;
        }
        if (empty($change['created'])) {
            return true;
        }
        $target = (string) ($change['target'] ?? '');
        $path = (string) ($change['path'] ?? '');
        $identity = (string) ($change['identity'] ?? '');
        if ($path !== $this->path($target) || $this->identity($path) !== $identity) {
            throw new \RuntimeException('Known-directory rollback refuses a changed target identity.');
        }
        $this->helper->doctor($context, 'remove-known-directory', [$target, $identity], 60);
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Known-directory rollback target remains present.');
        }
        return true;
    }

    private function path(string $target): string
    {
        return match ($target) {
            'xampp-logs' => rtrim(\SysAdmin\EnvironmentConfig::path('xampp_root'), '/') . '/logs',
            'xampp-ssl-key' => rtrim(\SysAdmin\EnvironmentConfig::path('xampp_root'), '/') . '/etc/ssl.key',
            default => '',
        };
    }

    private function value(string $output, string $key): string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $output, $matches) !== 1) {
            return '';
        }
        return trim((string) $matches[1]);
    }

    private function identity(string $path): string
    {
        $stat = lstat($path);
        if ($stat === false || !is_dir($path) || is_link($path)) {
            return '';
        }
        return implode(':', [(int) $stat['dev'], (int) $stat['ino'], sprintf('%o', $stat['mode'] & 07777), (int) $stat['uid'], (int) $stat['gid']]);
    }
}
