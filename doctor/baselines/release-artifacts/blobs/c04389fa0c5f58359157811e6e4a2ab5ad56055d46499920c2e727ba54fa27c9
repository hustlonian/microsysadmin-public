<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\LicensingServiceInterface;

final class LocalLicenseService implements LicensingServiceInterface
{
    private ?array $cachedStatus = null;

    public function status(): array
    {
        if ($this->cachedStatus !== null) {
            return $this->cachedStatus;
        }

        try {
            $organizationId = CommercialContext::organizationId();
            if ($organizationId === null) {
                return $this->cachedStatus = $this->unavailable('No local organization is configured.');
            }

            $statement = Database::pdo()->prepare(
                'SELECT licenses.*, plans.code AS plan_code, plans.name AS plan_name
                 FROM licenses
                 JOIN plans ON plans.id = licenses.plan_id
                 WHERE licenses.organization_id = ?
                 ORDER BY
                    CASE licenses.status WHEN "active" THEN 0 WHEN "expired" THEN 1 ELSE 2 END,
                    licenses.id DESC
                 LIMIT 1'
            );
            $statement->execute([$organizationId]);
            $license = $statement->fetch();

            if (!$license) {
                $plan = Database::pdo()->query("SELECT * FROM plans WHERE code = 'community' LIMIT 1")->fetch();
                if (!$plan) {
                    return $this->cachedStatus = $this->unavailable('Community plan data is unavailable.');
                }
                $license = [
                    'id' => null,
                    'plan_id' => $plan['id'],
                    'plan_code' => 'community',
                    'plan_name' => 'Community',
                    'status' => 'active',
                    'source' => 'local-default',
                    'expires_at' => null,
                    'updates_until' => null,
                    'last_verified_at' => null,
                    'grace_until' => null,
                ];
            }

            $entitlements = $this->entitlements((int) $license['plan_id'], isset($license['id']) ? (int) $license['id'] : null);
            return $this->cachedStatus = [
                'available' => true,
                'plan' => (string) $license['plan_code'],
                'edition' => (string) $license['plan_name'],
                'status' => (string) $license['status'],
                'source' => (string) $license['source'],
                'expires_at' => $license['expires_at'],
                'updates_until' => $license['updates_until'],
                'last_verified_at' => $license['last_verified_at'],
                'grace_until' => $license['grace_until'],
                'entitlements' => $entitlements,
                'essential_local_access' => true,
                'remote_kill_switch' => false,
            ];
        } catch (\Throwable $exception) {
            AuditLogger::error('license.local_unavailable', ['error' => $exception->getMessage()]);
            return $this->cachedStatus = $this->unavailable('Cached licensing data is unavailable.');
        }
    }

    public function entitlement(string $key, mixed $default = null): mixed
    {
        $status = $this->status();
        return $status['entitlements'][$key] ?? $default;
    }

    public function allows(string $key): bool
    {
        $value = $this->entitlement($key, false);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'enabled', 'unlimited', 'configurable'], true);
    }

    public function assert(string $key): void
    {
        if (!$this->allows($key)) {
            throw new HttpException(403, 'This feature is not included in the current MicroSysAdmin entitlement.');
        }
    }

    private function entitlements(int $planId, ?int $licenseId): array
    {
        $statement = Database::pdo()->prepare(
            'SELECT entitlements.entitlement_key, plan_entitlements.entitlement_value
             FROM plan_entitlements
             JOIN entitlements ON entitlements.id = plan_entitlements.entitlement_id
             WHERE plan_entitlements.plan_id = ?'
        );
        $statement->execute([$planId]);
        $values = [];
        foreach ($statement->fetchAll() as $row) {
            $values[(string) $row['entitlement_key']] = $this->normalize((string) $row['entitlement_value']);
        }

        if ($licenseId !== null && $licenseId > 0) {
            $override = Database::pdo()->prepare(
                'SELECT entitlements.entitlement_key, license_entitlements.entitlement_value
                 FROM license_entitlements
                 JOIN entitlements ON entitlements.id = license_entitlements.entitlement_id
                 WHERE license_entitlements.license_id = ?'
            );
            $override->execute([$licenseId]);
            foreach ($override->fetchAll() as $row) {
                $values[(string) $row['entitlement_key']] = $this->normalize((string) $row['entitlement_value']);
            }
        }

        return $values;
    }

    private function normalize(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => ctype_digit($value) ? (int) $value : $value,
        };
    }

    private function unavailable(string $message): array
    {
        return [
            'available' => false,
            'plan' => 'unavailable',
            'edition' => 'Unavailable',
            'status' => 'unavailable',
            'source' => 'fail-safe',
            'expires_at' => null,
            'updates_until' => null,
            'last_verified_at' => null,
            'grace_until' => null,
            'entitlements' => [
                'machine.max' => 1,
                'machines.max' => 1,
                'users.max' => 1,
                'terminal.enabled' => false,
                'code_runner.enabled' => false,
                'notifications.enabled' => false,
                'backups.scheduled' => false,
                'audit.extended' => false,
                'hub.enabled' => false,
                'branding.enabled' => false,
                'support.priority' => false,
                'updates.until' => false,
            ],
            'essential_local_access' => true,
            'remote_kill_switch' => false,
            'message' => $message,
        ];
    }
}
