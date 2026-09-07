# Commercial foundation implementation manifest

No Git repository is present, so this manifest records the implementation scope. Runtime backups and generated fixture/state files are excluded.

## Root and configuration

`.htaccess`, `VERSION`, `CHANGELOG.md`, `README.md`, `install.sh`, `uninstall.sh`, `diagnose.sh`, `repair.sh`, `update.sh`, `rollback.sh`, `health-check.sh`, `build-release.sh`, `config/config.php`, `config/config.sample.php`, `config/commands.php`, `config/navigation.php`.

## New commercial and architecture classes

`AdministrationService.php`, `AgentCredentialService.php`, `AgentManager.php`, `AgentRequestAuthenticator.php`, `AgentSigner.php`, `ApiTokenService.php`, `AuditLogService.php`, `CommercialBootstrap.php`, `CommercialContext.php`, `DemoMachineAgent.php`, `DemoMode.php`, `DisabledHubClient.php`, `EnvironmentConfig.php`, `EnvironmentDetector.php`, `EventBus.php`, `FilesystemUpdateProvider.php`, `HealthCheckService.php`, `HttpHubClient.php`, `HttpUpdateProvider.php`, `HubManager.php`, `HubSharingPolicy.php`, `InProcessAgentClient.php`, `JobQueue.php`, `JobWorker.php`, `LicenseManager.php`, `LocalLicenseService.php`, `LocalMachineAgent.php`, `MachineIdentity.php`, `MfaRecoveryService.php`, `MigrationRunner.php`, `OrganizationAuthorizer.php`, `OutboundUrlPolicy.php`, `ProcessExecutor.php`, `RequestContext.php`, `SecurityProfile.php`, `ServiceControlManager.php`, `SignedHttpAgentClient.php`, `SystemIntegrationManager.php`, `UpdateEntitlementPolicy.php`, `UpdateManager.php`, `WebhookService.php`, `WebhookSigner.php`.

## Contracts and drivers

All files in `classes/Contracts/` and `classes/Drivers/`: agent client/machine agent, metrics, services, files, databases, Git, Composer, Artisan, backups, tasks, scripts, logs, sites, terminal, notifications, audit, machine identity, licensing, update provider/service, and hub contracts; XAMPP, systemd, and inspection service drivers.

## Existing runtime classes modified

`Application.php`, `AuditLogger.php`, `Auth.php`, `BackupService.php`, `CommandRunner.php`, `CronService.php`, `Database.php`, `FileVault.php`, `GitService.php`, `HostingService.php`, `LaravelService.php`, `LaravelSiteProvisioner.php`, `LogService.php`, `MediaService.php`, `MysqlService.php`, `NotificationService.php`, `PathGuard.php`, `SandboxService.php`, `ScriptService.php`, `SitePageService.php`, `SystemProbe.php`, `bootstrap.php`, `helpers.php`; models `BackupJob.php`, `ScriptRun.php`, and `User.php`.

## HTTP, UI, and assets

New: `controllers/AgentController.php`, `controllers/ProductApiController.php`, `api/administration.php`, `views/pages/administration.php`, `views/pages/demo.php`.

Modified: `controllers/ApiController.php`, `controllers/DashboardController.php`, `controllers/PageController.php`, `views/layout.php`, `views/login.php`, `views/pages/backups.php`, `views/pages/media.php`, `views/pages/notifications.php`, `views/pages/services.php`, `views/pages/settings.php`, `views/pages/sites.php`, `views/pages/system.php`, `views/pages/users.php`, and `assets/css/sysadmin.css`.

## Database, worker, installer, and packaging

`database/migrations/202607270001_commercial_foundation.php`; `scripts/agent-credential.php`, `scripts/demo.php`, `scripts/diagnose-cli.php`, `scripts/health-check-cli.php`, `scripts/install-production.php`, `scripts/job-worker.php`, `scripts/repair-cli.php`, `scripts/release-manifest.php`, `scripts/render-system-integration.php`, `scripts/rollback-cli.php`, `scripts/runtime.sh`, `scripts/update-cli.php`, `scripts/service-control`; `cron/runner.php`; `packaging/release-allowlist.txt`, `packaging/release-excludes.txt`, and all files under `packaging/cron`, `packaging/logrotate`, and `packaging/systemd`; `tools/mock-hub/router.php`.

## Tests and documentation

`tests/run.php`, `tests/isolated-install.sh`, `tests/isolated-commercial-probe.php`, `tests/verify-isolated-rollback.php`; `docs/openapi.yaml`, `docs/SCALABILITY-AUDIT.md`, `docs/COMMERCIAL-SCALABILITY-AUDIT.md`, `docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, `docs/INSTALLATION.md`, `docs/QUICKSTART.md`, `docs/ENVIRONMENT.md`, `docs/ENVIRONMENT-DETECTION.md`, `docs/SUPPORTED-ENVIRONMENTS.md`, `docs/SECURITY.md`, `docs/SECURITY-PROFILES.md`, `docs/AGENT-API.md`, `docs/HUB-CONNECTION.md`, `docs/LICENSING.md`, `docs/ENTITLEMENTS.md`, `docs/UPDATES.md`, `docs/ROLLBACK.md`, `docs/BACKUPS.md`, `docs/TROUBLESHOOTING.md`, `docs/BUILDING-RELEASES.md`, `docs/RELEASE-PROCESS.md`, `docs/API.md`, `docs/WEBHOOKS.md`, `docs/BACKGROUND-JOBS.md`, `docs/COMMERCIAL-DEPLOYMENT.md`, `docs/KNOWN-LIMITATIONS.md`, `docs/UPGRADING-FROM-CURRENT.md`, `docs/SCALABILITY-READINESS-REPORT.md`, `docs/COMMERCIAL-READINESS-REPORT.md`, and this manifest.
