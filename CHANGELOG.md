# Changelog

All notable MicroSysAdmin product changes are recorded here. Versions follow Semantic Versioning.

## [Unreleased]

### Added

- Add a diagnosis-driven Repair SDK, exact lifecycle generator, commercial library auditor, permanent inventory tombstones, and time-forward detection/repair/verification/rollback contracts for the Doctor repair portfolio.
- Add identity-guarded managed-file and managed-directory primitives backed by immutable content-addressed release artifacts and exact directory topology contracts.

### Changed

- Consolidate duplicate Doctor advisory and executable lifecycle owners under one canonical repair ID while retaining permanent retired inventory records and recoverable package archives.
- Materialize the production Composer vendor tree from locked local baseline blobs during normal release builds and verify its exact files, directories, hashes, sizes, and modes before packaging or installation.

### Security

- Refuse symbolic, out-of-boundary, ambiguous, or unmanifested targets during locked-vendor reconstruction; never delete an unmanifested file as part of materialization.
- Require every automated Doctor repair to retain exact applicability, independent verification, bounded rollback, audit, documentation, contract, and commercial metadata coverage.

## [1.1.0-beta.2] - 2026-07-31

### Added

- Add a one-command Ubuntu 24.04 installer that selects healthy XAMPP or provisions a complete managed Apache/PHP 8.3/MariaDB stack, creates production configuration and the first Administrator, installs services, and gates success on authentication, licensing, health, and HTTP checks.
- Add secure noninteractive environment inputs, explicit validate/repair/upgrade actions, database restore tooling, verified installer rollback sets, safe uninstall preservation, and detailed redacted installation logs.
- Add an exact clean-clone Ubuntu canary with machine-readable and human-readable evidence for services, permissions, idempotency, backup/restore, signed update, manual/automatic rollback, repair, and secret redaction.
- Add installer failure-mode tests and a dedicated GitHub Actions clean-install job.

### Changed

- Make production system integration install worker, health timer, cron, and 14-day log rotation by default.
- Remove unencrypted temporary SQL dumps immediately after protected ZIP backup creation.
- Keep the current MicroSysAdmin behavior while installing XAMPP deployments at `/opt/lampp/htdocs/microsysadmin`, serving both installation modes at `http://localhost/microsysadmin`, and limiting the simple interactive flow to database credentials by generating a root-only first-Administrator login that must be changed.

### Security

- Reject password command arguments, preserve secrets through protected environment/configuration paths, disable terminal access by default, and prevent MicroSysAdmin from replacing shared XAMPP or unrelated web/database stacks.
- Require verified application and database recovery material before repair/upgrade, restore application and Apache state after late failure, and preserve all customer data during default uninstall.

## [1.1.0-beta.1] - 2026-07-27

### Added

- Add fail-closed terminal Git management, repository recovery, controlled staging, CI policy, and private-release preparation.
- Add reproducible beta packaging, SHA-256 manifests, RSA-SHA256 signing, and independent release verification.
- Add isolated install, idempotency, onboarding, backup, restore, forced-update-failure, rollback, Safe-profile, and demo-isolation coverage.
- Add portable system-integration rendering, installation previews, and a second-machine canary kit.

### Changed

- Default customer installations to the Safe security profile with terminal, code runner, privileged operations, and hub connectivity disabled.
- Embed the beta public verification key while keeping all private signing material outside the repository and web root.
- Correct host service inspection for XAMPP process names that Linux truncates in `/proc/*/comm`.

### Security

- Disable Development licensing and unsigned-update allowances in customer configuration.
- Require signed beta update metadata and reject modified packages or incorrect signatures.
- Keep the browser terminal private and disabled by default.

### Known limitations

- The exact automated Ubuntu 24.04 clean-clone canary is available, but the separately owned external release-acceptance machine checklist remains required before accepting payment or onboarding beta customers.
- Hub connectivity and remote fleet control remain unavailable in this beta.

## [1.1.0-dev] - 2026-07-27

### Added

- Central environment detection and administrator overrides.
- Persistent random machine and installation identity.
- XAMPP, systemd, and inspection-only service drivers.
- Local machine-agent contracts, signed HTTP requests, and replay protection.
- Organization, machine, plan, entitlement, licensing, support, job, event, webhook, API-token, hub, and update-history foundations.
- Community, Developer, Studio, Agency, and local Development editions.
- Secure update, maintenance, health-check, and application rollback tooling.
- Database-backed background jobs and worker service template.
- Scoped product API and signed outbound webhooks.
- Optional outbound hub enrollment and filtered heartbeats.
- Safe, Developer, and Advanced installation profiles.
- Isolated fake-data demonstration mode.
- Administrator interfaces for machine identity, environment, licensing, updates, hub, jobs, API tokens, webhooks, users, MFA, notifications, and restore.
- Commercial architecture, security, installation, update, rollback, API, hub, licensing, and readiness documentation.

### Changed

- Consolidate navigation around Project, Machine, and Administration workspaces.
- Rename misleading AI, deployment, and sandbox labels.
- Move primary host operations behind service and agent boundaries.
- Scope commercial records to the local organization and machine.

### Security

- Preserve essential local access during license-service outages.
- Add no remote kill switch.
- Add HMAC agent authentication, API-token hashing/scopes, webhook signatures, audit correlation, recovery-code hashing, demo isolation, and security-profile restrictions.

[Unreleased]: https://github.com/hustlonian/microsysadmin-public/compare/v1.1.0-beta.2...HEAD
[1.1.0-beta.2]: https://github.com/hustlonian/microsysadmin-public/releases/tag/v1.1.0-beta.2
[1.1.0-beta.1]: https://github.com/hustlonian/microsysadmin-public/releases/tag/v1.1.0-beta.1
[1.1.0-dev]: https://github.com/hustlonian/microsysadmin-public/tree/61cdc5392a55fa9dbd55fc7e3b728b5af7829b45
