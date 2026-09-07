<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Primitives;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\sdk\Contracts\RepairPrimitive;

final readonly class ArtisanPrimitive implements RepairPrimitive
{
    private const COMMANDS = ['config:cache', 'config:clear'];

    public function types(): array { return ['artisan_command']; }

    public function descriptor(string $type): array
    {
        return [
            'type' => $type,
            'category' => 'laravel',
            'local_only' => true,
            'structured_arguments' => true,
            'reversible' => true,
            'rollback' => 'BackupManager plus hash-guarded removal of created effect files',
            'safety_class' => 'artisan-command-and-effect-allowlist',
            'allowed_commands' => self::COMMANDS,
            'required_fields' => ['command', 'effects'],
        ];
    }

    public function execute(array $action, DoctorContext $context): array
    {
        $commandName = strtolower(trim((string) ($action['command'] ?? '')));
        if (!in_array($commandName, self::COMMANDS, true)) {
            throw new \RuntimeException('Artisan primitive command is outside the exact allowlist.');
        }
        $project = realpath($context->projectPath);
        $artisan = $project !== false ? realpath($project . '/artisan') : false;
        $php = $context->environment->command('xampp-php') ?? $context->environment->command('php');
        if ($project === false || $artisan === false || $php === null) {
            throw new \RuntimeException('Artisan primitive prerequisites are unavailable.');
        }
        $effects = [];
        foreach (array_values(array_unique(array_map('strval', (array) ($action['effects'] ?? [])))) as $effect) {
            $parent = realpath(dirname($effect));
            if ($parent === false || ($parent !== $project && !str_starts_with($parent, $project . DIRECTORY_SEPARATOR)) || is_link($effect)) {
                throw new \RuntimeException('Artisan primitive effect path is outside the managed project or symbolic.');
            }
            $effects[] = [
                'path' => $parent . DIRECTORY_SEPARATOR . basename($effect),
                'existed' => is_file($effect),
                'before_sha256' => is_file($effect) ? hash_file('sha256', $effect) : null,
            ];
        }
        if ($effects === []) {
            throw new \RuntimeException('Artisan primitive requires at least one exact effect path.');
        }
        $result = $context->process->run([$php, $artisan, $commandName, '--no-interaction'], (int) ($action['timeout'] ?? 120), $project);
        if (!$result->successful()) {
            $detail = trim((string) $result->get('stderr')) ?: trim((string) $result->get('stdout'));
            throw new \RuntimeException('Artisan primitive failed.' . ($detail !== '' ? ' ' . $detail : ''));
        }
        foreach ($effects as &$effect) {
            $effect['exists_after'] = is_file($effect['path']);
            $effect['after_sha256'] = is_file($effect['path']) ? hash_file('sha256', $effect['path']) : null;
            $effect['created'] = !$effect['existed'] && $effect['exists_after'];
        }
        unset($effect);
        return [
            'type' => 'artisan', 'command' => $commandName, 'created' => false,
            'effects' => $effects, 'result' => $result->toArray(),
            'description' => $action['description'] ?? "Executed allowlisted Artisan command {$commandName}.",
        ];
    }

    public function rollback(array $change, DoctorContext $context): bool
    {
        if (($change['type'] ?? '') !== 'artisan') {
            return false;
        }
        foreach ((array) ($change['effects'] ?? []) as $effect) {
            if (!empty($effect['created']) && is_file((string) ($effect['path'] ?? ''))) {
                $context->filesystem->removeFile((string) $effect['path'], (string) ($effect['after_sha256'] ?? ''));
            }
            // Pre-existing effect files are restored by BackupManager before
            // primitive-owned created-file cleanup runs.
        }
        return true;
    }
}
