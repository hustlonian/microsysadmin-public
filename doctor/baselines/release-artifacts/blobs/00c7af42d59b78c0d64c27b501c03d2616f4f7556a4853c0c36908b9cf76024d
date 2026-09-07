<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;
use SysAdmin\Doctor\sdk\Support\PrivilegedHelperClient;

final readonly class DoctorPrivilegedPrimitive implements RepairPrimitive
{
    private const OPERATIONS = ['enable-opcache', 'renew-local-tls'];

    public function __construct(private PrivilegedHelperClient $helper = new PrivilegedHelperClient())
    {
    }

    public function types(): array { return ['doctor_action']; }

    public function descriptor(string $type): array
    {
        return ['type' => $type, 'category' => 'privileged-local', 'local_only' => true, 'structured_arguments' => true, 'reversible' => true, 'rollback' => 'helper-owned validated backup reference', 'internal_backup' => true, 'safety_class' => 'exact-privileged-helper-allowlist', 'allowed_actions' => self::OPERATIONS, 'required_fields' => ['action', 'path']];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $operation = strtolower(trim((string) ($action['action'] ?? '')));
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \RuntimeException('Doctor privileged action is invalid or unsupported.');
        }
        $xamppRoot = \SysAdmin\EnvironmentConfig::path('xampp_root');
        $expectedPath = match ($operation) {
            'enable-opcache' => $xamppRoot . '/etc/php.ini',
            'renew-local-tls' => $xamppRoot . '/etc/ssl.crt/server.crt',
        };
        if ((string) ($action['path'] ?? '') !== $expectedPath) {
            throw new \RuntimeException('Doctor privileged action path does not match its exact allowlisted target.');
        }
        $result = $this->helper->doctor($context, $operation, [], (int) ($action['timeout'] ?? 90));
        $stdout = (string) ($result['stdout'] ?? '');
        if (preg_match('/^DOCTOR_NO_CHANGE=1$/m', $stdout)) {
            return [
                'type' => 'doctor_action', 'action' => $operation, 'path' => $action['path'] ?? null,
                'created' => false, 'changed' => false, 'result' => $result,
                'description' => $action['description'] ?? 'Validated a scoped privileged Doctor state.',
            ];
        }
        if (!preg_match('/^DOCTOR_BACKUP_PATH=(.+)$/m', $stdout, $pathMatch)
            || !preg_match('/^DOCTOR_BACKUP_SHA256=([a-f0-9]{64})$/m', $stdout, $hashMatch)) {
            throw new \RuntimeException('Privileged Doctor action did not return a validated rollback reference.');
        }
        $backupPath = trim($pathMatch[1]);
        $backupHash = strtolower($hashMatch[1]);
        $backupRoot = \SysAdmin\EnvironmentConfig::path('storage_root', STORAGE_PATH) . '/doctor/backups/privileged/';
        $expectedName = $operation === 'enable-opcache'
            ? '/^php\.ini-[0-9]{8}T[0-9]{6}Z-[0-9]+\.bak$/'
            : '/^server\.crt-[0-9]{8}T[0-9]{6}Z-[0-9]+\.bak$/';
        if (!str_starts_with($backupPath, $backupRoot) || !preg_match($expectedName, basename($backupPath))) {
            throw new \RuntimeException('Privileged Doctor action returned a rollback reference outside its exact managed namespace.');
        }
        return [
            'type' => 'doctor_action', 'action' => $operation, 'path' => $action['path'] ?? null,
            'created' => false, 'changed' => true, 'backup_path' => $backupPath, 'backup_sha256' => $backupHash,
            'result' => $result,
            'description' => $action['description'] ?? 'Executed a scoped privileged Doctor action.',
        ];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'doctor_action') {
            return false;
        }
        if (($change['changed'] ?? true) === false) {
            return true;
        }
        $operation = match ((string) ($change['action'] ?? '')) {
            'enable-opcache' => 'restore-opcache',
            'renew-local-tls' => 'restore-local-tls',
            default => throw new \RuntimeException('Privileged Doctor rollback operation is invalid.'),
        };
        $backupPath = (string) ($change['backup_path'] ?? '');
        $backupHash = strtolower((string) ($change['backup_sha256'] ?? ''));
        if ($backupPath === '' || !preg_match('/^[a-f0-9]{64}$/', $backupHash)) {
            throw new \RuntimeException('Privileged Doctor rollback reference is incomplete.');
        }
        $this->helper->doctor($context, $operation, [$backupPath, $backupHash], 90);
        return true;
    }
}
