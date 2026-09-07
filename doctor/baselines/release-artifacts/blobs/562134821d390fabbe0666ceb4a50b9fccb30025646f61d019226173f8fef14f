<?php

declare(strict_types=1);

namespace SysAdmin;

use PDO;

final class CommercialBootstrap
{
    public static function seed(): void
    {
        $pdo = Database::pdo();
        $identityService = new MachineIdentity();
        $identity = $identityService->get();
        $now = date('Y-m-d H:i:s');

        $organization = $pdo->query('SELECT * FROM organizations ORDER BY id LIMIT 1')->fetch();
        if (!$organization) {
            $organizationUuid = MachineIdentity::uuid();
            $statement = $pdo->prepare(
                'INSERT INTO organizations (uuid, name, slug, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$organizationUuid, 'Local Organization', 'local', 'active', $now, $now]);
            $organization = [
                'id' => (int) $pdo->lastInsertId(),
                'uuid' => $organizationUuid,
            ];
            $identityService->setOrganizationUuid($organizationUuid);
        } elseif (empty($identity['organization_uuid'])) {
            $identityService->setOrganizationUuid((string) $organization['uuid']);
        }

        $organizationId = (int) $organization['id'];
        self::seedPlans($pdo, $now);

        $machine = self::upsertMachine($pdo, $organizationId, $identityService->get(), $now);
        self::scopeExistingRows($pdo, $organizationId, (int) $machine['id']);
        self::seedMemberships($pdo, $organizationId, $now);
        self::seedDevelopmentLicense($pdo, $organizationId, (int) $machine['id'], $now);

        $support = $pdo->prepare(
            'INSERT IGNORE INTO support_status (organization_id, level, active_until, metadata, updated_at)
             VALUES (?, ?, NULL, NULL, ?)'
        );
        $support->execute([$organizationId, (bool) config_value('licensing.development_mode', false) ? 'development' : 'community', $now]);
    }

    private static function upsertMachine(PDO $pdo, int $organizationId, array $identity, string $now): array
    {
        $statement = $pdo->prepare('SELECT * FROM machines WHERE machine_uuid = ? LIMIT 1');
        $statement->execute([(string) $identity['machine_uuid']]);
        $machine = $statement->fetch();

        $values = [
            $organizationId,
            (string) $identity['installation_uuid'],
            (string) $identity['display_name'],
            (string) $identity['hostname'],
            (string) $identity['operating_system'],
            (string) $identity['architecture'],
            (string) $identity['microsysadmin_version'],
            (string) $identity['php_version'],
            (string) $identity['xampp_root'],
            json_encode($identity['document_roots'], JSON_UNESCAPED_SLASHES),
            self::sqlDate((string) $identity['last_update_at']),
            (string) $identity['license_status'],
            (string) $identity['connection_status'],
            $now,
            $now,
        ];

        if ($machine) {
            $update = $pdo->prepare(
                'UPDATE machines SET organization_id = ?, installation_uuid = ?, display_name = ?,
                 hostname = ?, operating_system = ?, architecture = ?, microsysadmin_version = ?,
                 php_version = ?, xampp_root = ?, document_roots = ?, last_update_at = ?,
                 license_status = ?, connection_status = ?, last_seen_at = ?, updated_at = ?
                 WHERE machine_uuid = ?'
            );
            $update->execute([...$values, (string) $identity['machine_uuid']]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO machines (
                    organization_id, installation_uuid, display_name, hostname, operating_system,
                    architecture, microsysadmin_version, php_version, xampp_root, document_roots,
                    last_update_at, license_status, connection_status, last_seen_at, created_at,
                    machine_uuid, installed_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                ...$values,
                (string) $identity['machine_uuid'],
                self::sqlDate((string) $identity['installed_at']),
                $now,
            ]);
        }

        $statement->execute([(string) $identity['machine_uuid']]);
        return $statement->fetch() ?: throw new \RuntimeException('Unable to persist machine record.');
    }

    private static function seedMemberships(PDO $pdo, int $organizationId, string $now): void
    {
        $users = $pdo->query('SELECT id, role FROM users')->fetchAll();
        $insert = $pdo->prepare(
            'INSERT INTO organization_members (
                organization_id, user_id, organization_role, status, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE organization_role = VALUES(organization_role), status = VALUES(status), updated_at = VALUES(updated_at)'
        );
        foreach ($users as $user) {
            $organizationRole = match ((string) $user['role']) {
                'Administrator' => 'owner',
                'Operator' => 'member',
                default => 'viewer',
            };
            $insert->execute([$organizationId, (int) $user['id'], $organizationRole, 'active', $now, $now]);
        }
    }

    private static function seedPlans(PDO $pdo, string $now): void
    {
        $plans = [
            'community' => ['Community', [
                'machine.max' => '1', 'machines.max' => '1', 'users.max' => '1',
                'terminal.enabled' => 'false', 'code_runner.enabled' => 'false',
                'notifications.enabled' => 'false',
                'backups.scheduled' => 'false', 'audit.extended' => 'false',
                'hub.enabled' => 'false', 'branding.enabled' => 'false',
                'support.priority' => 'false', 'updates.until' => 'community',
            ]],
            'developer' => ['Developer', [
                'machine.max' => '1', 'machines.max' => '1', 'users.max' => '1',
                'terminal.enabled' => 'true', 'code_runner.enabled' => 'true',
                'notifications.enabled' => 'true',
                'backups.scheduled' => 'true', 'audit.extended' => 'false',
                'hub.enabled' => 'false', 'branding.enabled' => 'false',
                'support.priority' => 'false', 'updates.until' => '+1 year',
            ]],
            'studio' => ['Studio', [
                'machine.max' => '5', 'machines.max' => '5', 'users.max' => 'unlimited',
                'terminal.enabled' => 'true', 'code_runner.enabled' => 'true',
                'notifications.enabled' => 'true',
                'backups.scheduled' => 'true', 'audit.extended' => 'true',
                'hub.enabled' => 'true', 'branding.enabled' => 'false',
                'support.priority' => 'false', 'updates.until' => '+1 year',
            ]],
            'agency' => ['Agency', [
                'machine.max' => 'configurable', 'machines.max' => 'configurable', 'users.max' => 'unlimited',
                'terminal.enabled' => 'true', 'code_runner.enabled' => 'true',
                'notifications.enabled' => 'true',
                'backups.scheduled' => 'true', 'audit.extended' => 'true',
                'hub.enabled' => 'true', 'branding.enabled' => 'true',
                'support.priority' => 'true', 'updates.until' => '+1 year',
            ]],
            'development' => ['Development', [
                'machine.max' => 'unlimited', 'machines.max' => 'unlimited', 'users.max' => 'unlimited',
                'terminal.enabled' => 'true', 'code_runner.enabled' => 'true',
                'notifications.enabled' => 'true',
                'backups.scheduled' => 'true', 'audit.extended' => 'true',
                'hub.enabled' => 'true', 'branding.enabled' => 'true',
                'support.priority' => 'true', 'updates.until' => 'unlimited',
            ]],
        ];

        $descriptions = [
            'machine.max' => ['integer', 'Maximum licensed machines.'],
            'machines.max' => ['integer', 'Maximum licensed machines (canonical commercial key).'],
            'users.max' => ['integer', 'Maximum MicroSysAdmin users.'],
            'terminal.enabled' => ['boolean', 'Interactive terminal availability.'],
            'code_runner.enabled' => ['boolean', 'Code Runner availability.'],
            'notifications.enabled' => ['boolean', 'SMTP notification availability.'],
            'backups.scheduled' => ['boolean', 'Scheduled backup availability.'],
            'audit.extended' => ['boolean', 'Extended audit retention and reporting.'],
            'hub.enabled' => ['boolean', 'Optional hub enrollment and heartbeat.'],
            'branding.enabled' => ['boolean', 'Organization branding controls.'],
            'support.priority' => ['boolean', 'Priority commercial support.'],
            'updates.until' => ['date', 'Commercial update entitlement.'],
        ];

        foreach ($descriptions as $key => [$type, $description]) {
            $statement = $pdo->prepare(
                'INSERT INTO entitlements (entitlement_key, value_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value_type = VALUES(value_type), description = VALUES(description), updated_at = VALUES(updated_at)'
            );
            $statement->execute([$key, $type, $description, $now, $now]);
        }

        foreach ($plans as $code => [$name, $entitlements]) {
            $statement = $pdo->prepare(
                'INSERT INTO plans (code, name, description, active, created_at, updated_at)
                 VALUES (?, ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), active = 1, updated_at = VALUES(updated_at)'
            );
            $statement->execute([$code, $name, $name . ' edition', $now, $now]);

            $planId = (int) $pdo->query('SELECT id FROM plans WHERE code = ' . $pdo->quote($code))->fetchColumn();
            foreach ($entitlements as $key => $value) {
                $entitlementStatement = $pdo->prepare('SELECT id FROM entitlements WHERE entitlement_key = ?');
                $entitlementStatement->execute([$key]);
                $entitlementId = (int) $entitlementStatement->fetchColumn();
                $valueStatement = $pdo->prepare(
                    'INSERT INTO plan_entitlements (plan_id, entitlement_id, entitlement_value, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE entitlement_value = VALUES(entitlement_value), updated_at = VALUES(updated_at)'
                );
                $valueStatement->execute([$planId, $entitlementId, $value, $now, $now]);
            }
        }
    }

    private static function seedDevelopmentLicense(PDO $pdo, int $organizationId, int $machineId, string $now): void
    {
        if (!(bool) config_value('licensing.development_mode', false)) {
            return;
        }

        $planId = (int) $pdo->query("SELECT id FROM plans WHERE code = 'development'")->fetchColumn();
        $statement = $pdo->prepare(
            "SELECT id FROM licenses WHERE organization_id = ? AND source = 'development' LIMIT 1"
        );
        $statement->execute([$organizationId]);
        $licenseId = (int) $statement->fetchColumn();
        if ($licenseId < 1) {
            $insert = $pdo->prepare(
                'INSERT INTO licenses (
                    uuid, organization_id, plan_id, status, source, starts_at,
                    last_verified_at, metadata, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                MachineIdentity::uuid(), $organizationId, $planId, 'active', 'development',
                $now, $now, json_encode(['local_development' => true]), $now, $now,
            ]);
            $licenseId = (int) $pdo->lastInsertId();
        }

        $assign = $pdo->prepare(
            'INSERT IGNORE INTO machine_licenses (machine_id, license_id, assigned_at) VALUES (?, ?, ?)'
        );
        $assign->execute([$machineId, $licenseId, $now]);
    }

    private static function scopeExistingRows(PDO $pdo, int $organizationId, int $machineId): void
    {
        foreach (['users', 'audit_logs', 'backup_jobs', 'script_runs', 'scheduled_tasks', 'hosting_sites'] as $table) {
            $pdo->exec("UPDATE `{$table}` SET organization_id = {$organizationId} WHERE organization_id IS NULL");
        }
        foreach (['audit_logs', 'backup_jobs', 'script_runs', 'scheduled_tasks', 'hosting_sites', 'metric_samples'] as $table) {
            $pdo->exec("UPDATE `{$table}` SET machine_id = {$machineId} WHERE machine_id IS NULL");
        }
    }

    private static function sqlDate(string $value): string
    {
        $time = strtotime($value);
        return date('Y-m-d H:i:s', $time === false ? time() : $time);
    }
}
