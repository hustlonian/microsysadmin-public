<?php

declare(strict_types=1);

namespace SysAdmin;

final class AdministrationService
{
    public function data(string $mode): array
    {
        $organizationId = CommercialContext::organizationId();
        $organization = null;
        $machines = [];
        if ($organizationId !== null) {
            $statement = Database::pdo()->prepare('SELECT * FROM organizations WHERE id = ? LIMIT 1');
            $statement->execute([$organizationId]);
            $organization = $statement->fetch() ?: null;
            $statement = Database::pdo()->prepare('SELECT * FROM machines WHERE organization_id = ? ORDER BY display_name');
            $statement->execute([$organizationId]);
            $machines = $statement->fetchAll();
        }

        return [
            'mode' => $mode,
            'machine' => (new MachineIdentity())->get(),
            'organization' => $organization,
            'machines' => $machines,
            'environment' => EnvironmentConfig::all(),
            'environmentDiagnostics' => EnvironmentConfig::diagnostics(),
            'detectedEnvironment' => (new EnvironmentDetector())->detect(),
            'license' => LicenseManager::service()->status(),
            'updates' => (new UpdateManager())->status(),
            'hub' => HubManager::client()->status(),
            'hubSharing' => HubSharingPolicy::current(),
            'securityProfile' => SecurityProfile::current(),
            'securityCapabilities' => SecurityProfile::capabilities(),
            'jobs' => (new JobQueue())->recent(100),
            'apiTokens' => (new ApiTokenService())->list(),
            'webhooks' => (new WebhookService())->list(),
            'audit' => AuditLogger::rows(200),
            'health' => (new HealthCheckService())->all(),
        ];
    }
}
