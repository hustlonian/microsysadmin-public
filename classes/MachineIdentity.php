<?php

declare(strict_types=1);

namespace SysAdmin;

final class MachineIdentity implements \SysAdmin\Contracts\MachineIdentityInterface
{
    private const FILE_NAME = 'machine-identity.json';

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $path = $this->path();
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && $this->validUuid((string) ($decoded['machine_uuid'] ?? ''))) {
                return $this->withRuntimeInformation($decoded);
            }
        }

        return $this->create();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(?string $displayName = null): array
    {
        $directory = dirname($this->path());
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the private machine identity directory.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('The private machine identity directory is not writable.');
        }

        $now = gmdate(DATE_ATOM);
        $identity = [
            'schema_version' => 1,
            'machine_uuid' => self::uuid(),
            'installation_uuid' => self::uuid(),
            'display_name' => trim((string) $displayName) ?: (gethostname() ?: 'MicroSysAdmin machine'),
            'installed_at' => $now,
            'last_update_at' => $now,
            'organization_uuid' => null,
        ];

        $this->write($identity);
        return $this->withRuntimeInformation($identity);
    }

    public function setOrganizationUuid(?string $organizationUuid): void
    {
        $identity = $this->get();
        $identity['organization_uuid'] = $organizationUuid;
        $this->write($this->persistedFields($identity));
    }

    public function touchUpdated(): void
    {
        $identity = $this->get();
        $identity['last_update_at'] = gmdate(DATE_ATOM);
        $this->write($this->persistedFields($identity));
    }

    public function setDisplayName(string $displayName): void
    {
        $displayName = trim(preg_replace('/\s+/', ' ', $displayName) ?? $displayName);
        if ($displayName === '' || mb_strlen($displayName) > 190) {
            throw new \InvalidArgumentException('Machine display name is invalid.');
        }
        $identity = $this->get();
        $identity['display_name'] = $displayName;
        $this->write($this->persistedFields($identity));
        CommercialBootstrap::seed();
    }

    public function path(): string
    {
        return EnvironmentConfig::path('private_root', '/var/lib/microsysadmin') . '/' . self::FILE_NAME;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function write(array $identity): void
    {
        $path = $this->path();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write machine identity.');
        }
        // Group-readable permits the web/worker service account to read an
        // identity created by the root-run installer while keeping it private
        // from every other local account.
        $groupSource = is_file($path) ? $path : dirname($path);
        $groupId = @filegroup($groupSource);
        if (is_int($groupId)) {
            @chgrp($temporary, $groupId);
        }
        chmod($temporary, 0640);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to install machine identity.');
        }
    }

    private function withRuntimeInformation(array $identity): array
    {
        $identity['hostname'] = gethostname() ?: 'localhost';
        $identity['operating_system'] = (new EnvironmentDetector())->detect()['operating_system'];
        $identity['architecture'] = php_uname('m');
        $identity['microsysadmin_version'] = self::version();
        $identity['php_version'] = PHP_VERSION;
        $identity['xampp_root'] = EnvironmentConfig::path('xampp_root');
        $identity['document_roots'] = EnvironmentConfig::paths('document_roots');
        $identity['license_status'] = $this->setting('license_status', 'unverified');
        $identity['connection_status'] = $this->setting('hub_connection_status', 'disconnected');
        return $identity;
    }

    private function persistedFields(array $identity): array
    {
        return array_intersect_key($identity, array_flip([
            'schema_version', 'machine_uuid', 'installation_uuid', 'display_name',
            'installed_at', 'last_update_at', 'organization_uuid',
        ]));
    }

    private function setting(string $key, string $default): string
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : (string) $value;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function validUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    public static function version(): string
    {
        $path = APP_ROOT . '/VERSION';
        return is_file($path) ? trim((string) file_get_contents($path)) : '0.0.0-dev';
    }
}
