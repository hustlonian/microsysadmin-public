<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;

final readonly class CrontabPrimitive implements RepairPrimitive
{
    public function types(): array { return ['deduplicate_crontab_entry', 'ensure_crontab_entry', 'ensure_crontab_line']; }

    public function descriptor(string $type): array
    {
        return [
            'type' => $type,
            'category' => 'cron',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => 'complete-crontab-snapshot',
            'internal_backup' => true,
            'safety_class' => 'structured-command-exact-marker',
            'required_fields' => match ($type) {
                'ensure_crontab_entry' => ['schedule', 'command', 'marker'],
                'ensure_crontab_line' => ['schedule', 'script', 'marker'],
                'deduplicate_crontab_entry' => ['marker'],
                default => [],
            },
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $crontab = $context->environment->command('crontab');
        $project = realpath($context->projectPath);
        if (($action['type'] ?? '') === 'deduplicate_crontab_entry') {
            return $this->deduplicate($action, $context, $crontab, $project);
        }
        $php = $context->environment->command('xampp-php') ?? $context->environment->command('php');
        $backupScript = $project !== false ? realpath($project . '/scripts/backup_database.php') : false;
        $schedule = trim((string) ($action['schedule'] ?? ''));
        $marker = trim((string) ($action['marker'] ?? ''));
        if ($crontab === null || $php === null || $project === false) {
            throw new \RuntimeException('The scoped scheduler prerequisites are unavailable.');
        }
        if (($action['type'] ?? '') === 'ensure_crontab_line') {
            $script = realpath((string) ($action['script'] ?? ''));
            if ($backupScript === false || $schedule !== '0 3 * * *'
                || $marker !== 'MicroSysAdmin Doctor BACKUP-0009'
                || $script === false || $script !== $backupScript) {
                throw new \RuntimeException('The database-backup scheduler action is outside the exact Doctor allowlist.');
            }
            $command = [$php, $backupScript];
            $suffix = ' >/dev/null 2>&1';
        } else {
            $command = array_map('strval', (array) ($action['command'] ?? []));
            $fields = preg_split('/\s+/', $schedule) ?: [];
            if (count($fields) !== 5
                || array_filter($fields, static fn (string $field): bool => !preg_match('/^[0-9*\/,\-]+$/', $field)) !== []
                || !preg_match('/^MicroSysAdmin Doctor [A-Z]+-[0-9]{4}$/', $marker)
                || count($command) < 2 || count($command) > 12) {
                throw new \RuntimeException('Structured crontab action has an invalid schedule, marker, or command shape.');
            }
            $allowedPhp = array_values(array_filter([
                $context->environment->command('xampp-php'),
                $context->environment->command('php'),
            ], 'is_string'));
            $commandExecutable = realpath($command[0]);
            if ($commandExecutable === false || !in_array($commandExecutable, array_map('realpath', $allowedPhp), true)) {
                throw new \RuntimeException('Structured crontab action executable is outside the PHP allowlist.');
            }
            $command[0] = $commandExecutable;
            foreach (array_slice($command, 1) as $argument) {
                if ($argument === '' || str_contains($argument, "\0") || str_contains($argument, "\n") || str_contains($argument, "\r")) {
                    throw new \RuntimeException('Structured crontab action contains an unsafe argument.');
                }
                if (str_starts_with($argument, '/')) {
                    $real = realpath($argument);
                    if ($real === false || ($real !== $project && !str_starts_with($real, $project . DIRECTORY_SEPARATOR))) {
                        throw new \RuntimeException('Structured crontab action path is outside the managed project.');
                    }
                }
            }
            $suffix = !empty($action['discard_output']) ? ' >/dev/null 2>&1' : '';
        }
        $line = $schedule . ' ' . implode(' ', array_map('escapeshellarg', $command)) . $suffix . ' # ' . $marker;
        [$before, $hadCrontab] = $this->captureCurrent($crontab, $context);
        $lines = preg_split('/\R/', trim($before)) ?: [];
        $retained = array_values(array_filter($lines, static fn (string $existing): bool => $existing !== '' && !str_contains($existing, '# ' . $marker)));
        $updated = implode("\n", [...$retained, $line]) . "\n";
        return $this->applyUpdate($action, $context, $crontab, $marker, $before, $updated, $hadCrontab);
    }

    private function deduplicate(array $action, DoctorContext $context, ?string $crontab, string|false $project): array
    {
        $marker = trim((string) ($action['marker'] ?? ''));
        if ($crontab === null || $project === false || $marker !== 'MicroSysAdmin Doctor CRON-0004') {
            throw new \RuntimeException('Crontab deduplication is outside the exact Doctor scheduler allowlist.');
        }
        [$before, $hadCrontab] = $this->captureCurrent($crontab, $context);
        if (!$hadCrontab) {
            throw new \RuntimeException('Crontab deduplication requires an existing captured crontab.');
        }
        $lines = preg_split('/\R/', trim($before)) ?: [];
        $updatedLines = [];
        $seen = false;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (str_contains($line, '# ' . $marker)) {
                if ($seen) {
                    continue;
                }
                $seen = true;
            }
            $updatedLines[] = $line;
        }
        $updated = $updatedLines === [] ? '' : implode("\n", $updatedLines) . "\n";
        return $this->applyUpdate($action, $context, $crontab, $marker, $before, $updated, true);
    }

    private function captureCurrent(string $crontab, DoctorContext $context): array
    {
        $current = $context->process->run([$crontab, '-l'], 10);
        $hadCrontab = $current->successful();
        $noCrontab = $current->exitCode() === 1 && str_contains(strtolower((string) $current->get('stderr')), 'no crontab');
        if (!$hadCrontab && !$noCrontab) {
            throw new \RuntimeException('Unable to capture the existing service-account crontab safely.');
        }
        return [$hadCrontab ? rtrim((string) $current->get('stdout')) . "\n" : '', $hadCrontab];
    }

    private function applyUpdate(
        array $action,
        DoctorContext $context,
        string $crontab,
        string $marker,
        string $before,
        string $updated,
        bool $hadCrontab,
    ): array {
        $runtimeRoot = rtrim((string) $context->option('doctor_runtime_root', STORAGE_PATH . '/doctor'), '/');
        $repairMarker = str_replace('MicroSysAdmin Doctor ', '', $marker);
        $snapshotId = substr(hash('sha256', $before), 0, 12) . '-' . bin2hex(random_bytes(4));
        $base = $runtimeRoot . '/executions/' . $context->executionId . '-' . $repairMarker . '-crontab-' . $snapshotId;
        $backupPath = $base . '.before';
        $transferId = substr(hash('sha256', $context->executionId . ':' . $repairMarker), 0, 20);
        $installPath = '/tmp/microsysadmin-doctor-' . $transferId . '.cron';
        $context->filesystem->atomicWrite($backupPath, $before, 0600);
        if (hash_equals($before, $updated)) {
            return ['type' => 'crontab', 'created' => false, 'changed' => false, 'marker' => $marker, 'backup_path' => $backupPath, 'had_crontab' => $hadCrontab, 'before_sha256' => hash('sha256', $before), 'description' => $action['description'] ?? 'Verified the exact managed crontab state.'];
        }
        $context->filesystem->atomicWrite($installPath, $updated, 0600);
        $change = ['type' => 'crontab', 'created' => !$hadCrontab, 'changed' => true, 'marker' => $marker, 'backup_path' => $backupPath, 'had_crontab' => $hadCrontab, 'before_sha256' => hash('sha256', $before), 'after_sha256' => hash('sha256', $updated), 'description' => $action['description'] ?? 'Installed the exact managed crontab state.'];
        try {
            $installed = $context->process->run([$crontab, $installPath], 15);
            if (!$installed->successful()) {
                $detail = trim((string) $installed->get('stderr')) ?: trim((string) $installed->get('stdout'));
                throw new \RuntimeException('Unable to install the scoped managed crontab state.' . ($detail !== '' ? ' ' . $detail : ''));
            }
        } catch (\Throwable $error) {
            throw new \SysAdmin\Doctor\sdk\PartialActionFailure($error->getMessage(), $change, $error);
        } finally { @unlink($installPath); }
        return $change;
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'crontab') {
            return false;
        }
        if (empty($change['changed'])) {
            return true;
        }
        $crontab = $context->environment->command('crontab');
        $backupPath = (string) ($change['backup_path'] ?? '');
        if ($crontab === null || $backupPath === '' || !is_file($backupPath)) {
            throw new \RuntimeException('The captured crontab rollback state is unavailable.');
        }
        $captured = $context->filesystem->read($backupPath);
        if (!hash_equals((string)($change['before_sha256'] ?? ''), hash('sha256', $captured))) {
            throw new \RuntimeException('Crontab backup integrity failed; restoration refused.');
        }
        [$current, $currentlyPresent] = $this->captureCurrent($crontab, $context);
        if ($currentlyPresent === (bool)$change['had_crontab'] && hash_equals($captured, $current)) { return true; }
        $afterSha256 = (string) ($change['after_sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $afterSha256) || !hash_equals($afterSha256, hash('sha256', $current))) {
            throw new \RuntimeException('Crontab changed after repair; rollback refuses to overwrite newer scheduler state.');
        }
        if (!empty($change['had_crontab'])) {
            $transferId = substr(hash('sha256', $context->executionId . ':' . (string) ($change['marker'] ?? 'crontab') . ':rollback'), 0, 20);
            $restorePath = '/tmp/microsysadmin-doctor-' . $transferId . '.cron';
            $context->filesystem->atomicWrite($restorePath, $captured, 0600);
            $result = $context->process->run([$crontab, $restorePath], 15);
            @unlink($restorePath);
        } else {
            $result = $context->process->run([$crontab, '-r'], 15);
        }
        if (!$result->successful()) {
            throw new \RuntimeException('Unable to restore the captured service-account crontab.');
        }
        [$restored, $hasCrontab] = $this->captureCurrent($crontab, $context);
        if ($hasCrontab !== (bool)$change['had_crontab'] || !hash_equals((string)$change['before_sha256'], hash('sha256', $restored))) {
            throw new \RuntimeException('Crontab rollback did not restore the exact captured state.');
        }
        return true;
    }
}
