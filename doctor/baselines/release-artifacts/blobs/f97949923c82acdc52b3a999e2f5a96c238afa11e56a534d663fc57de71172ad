<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\UpdateProviderInterface;
use SysAdmin\Contracts\UpdateServiceInterface;

final class UpdateManager implements UpdateServiceInterface
{
    public function status(): array
    {
        $history = Database::pdo()->query('SELECT * FROM update_history ORDER BY id DESC LIMIT 20')->fetchAll();
        return [
            'current_version' => MachineIdentity::version(),
            'channel' => (string) config_value('updates.channel', 'stable'),
            'provider' => (string) config_value('updates.provider', 'filesystem'),
            'maintenance_mode' => is_file(STORAGE_PATH . '/maintenance.flag'),
            'history' => $history,
            'update_entitlement' => LicenseManager::service()->entitlement('updates.until', false),
        ];
    }

    public function check(): array
    {
        $channel = (string) config_value('updates.channel', 'stable');
        $manifest = $this->provider()->manifest($channel);
        if (empty($manifest['version'])) {
            return [...$manifest, 'current_version' => MachineIdentity::version(), 'channel' => $channel];
        }
        $this->validateManifest($manifest, false);
        $manifest['available'] = version_compare((string) $manifest['version'], MachineIdentity::version(), '>');
        $manifest['current_version'] = MachineIdentity::version();
        return $manifest;
    }

    public function verifyPackage(array $manifest, string $packagePath): array
    {
        $this->validateManifest($manifest, true);
        if (!is_file($packagePath)) {
            throw new \InvalidArgumentException('Update package does not exist.');
        }
        $checksum = hash_file('sha256', $packagePath);
        if (!hash_equals(strtolower((string) $manifest['sha256']), strtolower($checksum))) {
            throw new \RuntimeException('Update package checksum verification failed.');
        }
        $this->verifySignature($manifest);
        (new UpdateEntitlementPolicy(
            LicenseManager::service(),
            (bool) config_value('licensing.development_mode', false)
        ))->assert((string) $manifest['released_at'], (string) $manifest['version']);
        return [
            'verified' => true,
            'version' => $manifest['version'],
            'channel' => $manifest['channel'],
            'checksum' => $checksum,
        ];
    }

    public function createHistory(array $manifest, string $status, ?string $backupPath = null, ?string $message = null): int
    {
        $statement = Database::pdo()->prepare(
            'INSERT INTO update_history (
                machine_id, from_version, to_version, channel, status,
                manifest_checksum, package_checksum, backup_path, message,
                started_at, initiated_by, audit_correlation_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            CommercialContext::machineId(),
            MachineIdentity::version(),
            (string) $manifest['version'],
            (string) $manifest['channel'],
            $status,
            hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES)),
            (string) ($manifest['sha256'] ?? ''),
            $backupPath,
            $message,
            date('Y-m-d H:i:s'),
            Auth::id(),
            RequestContext::auditCorrelationId(),
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public function finishHistory(int $id, string $status, ?string $message = null, ?string $rollbackPath = null): void
    {
        Database::pdo()->prepare(
            'UPDATE update_history SET status = ?, message = ?, rollback_path = ?,
             completed_at = ? WHERE id = ?'
        )->execute([$status, $message, $rollbackPath, date('Y-m-d H:i:s'), $id]);
    }

    private function validateManifest(array $manifest, bool $requirePackageFields): void
    {
        foreach (['version', 'channel', 'released_at'] as $key) {
            if (empty($manifest[$key])) {
                throw new \RuntimeException("Update manifest is missing {$key}.");
            }
        }
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', (string) $manifest['version'])) {
            throw new \RuntimeException('Update manifest version is not semantic versioning.');
        }
        if (!in_array($manifest['channel'], ['stable', 'beta', 'development'], true)) {
            throw new \RuntimeException('Update manifest channel is invalid.');
        }
        if ($requirePackageFields) {
            foreach (['sha256', 'signature'] as $key) {
                if (empty($manifest[$key])) {
                    throw new \RuntimeException("Update manifest is missing {$key}.");
                }
            }
        }
    }

    private function verifySignature(array $manifest): void
    {
        $configuredPublicKey = trim((string) config_value('updates.public_key', ''));
        $algorithm = (string) ($manifest['signature_algorithm'] ?? 'Ed25519');
        $developmentUnsigned = (string) $manifest['channel'] === 'development'
            && (bool) config_value('updates.allow_unsigned_development', false);
        if (
            $developmentUnsigned
            && (
                $algorithm === 'development-unsigned'
                || (string) ($manifest['signature'] ?? '') === 'development-unsigned'
            )
        ) {
            return;
        }
        $signed = implode("\n", [
            (string) $manifest['version'],
            (string) $manifest['channel'],
            strtolower((string) $manifest['sha256']),
            (string) $manifest['released_at'],
        ]);

        $signature = base64_decode((string) ($manifest['signature'] ?? ''), true);
        if ($signature === false) {
            throw new \RuntimeException('Update signature is invalid.');
        }
        if ($algorithm === 'RSA-SHA256') {
            $publicKey = openssl_pkey_get_public($configuredPublicKey);
            $details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
            if ($publicKey === false || !is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                throw new \RuntimeException('The configured update key is not a valid RSA public key.');
            }
            $verified = openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            if ($verified !== 1) {
                throw new \RuntimeException('Update signature verification failed.');
            }
            return;
        }
        if ($algorithm !== 'Ed25519') {
            throw new \RuntimeException('Update signature algorithm is unsupported.');
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException('The Sodium extension is required for Ed25519 update verification.');
        }
        $publicKey = base64_decode($configuredPublicKey, true);
        $publicKeyBytes = defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
            ? (int) constant('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
            : 32;
        if ($publicKey === false || strlen($publicKey) !== $publicKeyBytes) {
            throw new \RuntimeException('The configured Ed25519 update key is invalid.');
        }
        if (!sodium_crypto_sign_verify_detached($signature, $signed, $publicKey)) {
            throw new \RuntimeException('Update signature verification failed.');
        }
    }

    private function provider(): UpdateProviderInterface
    {
        return match ((string) config_value('updates.provider', 'filesystem')) {
            'http', 'github', 'hosted' => new HttpUpdateProvider(),
            default => new FilesystemUpdateProvider(),
        };
    }
}
