# MicroSysAdmin Scalability Audit

Date: 2026-07-27

## Product definition

MicroSysAdmin is a self-hosted Laravel and PHP development-server workspace for Linux. It combines project provisioning, XAMPP control, files, MySQL, Artisan, Composer, Git, backups, logs, automation, system monitoring, and optional terminal access in one browser interface.

MicroSysAdmin is not currently a generic Linux administration suite, a complete cPanel replacement, a hardened public cloud IDE, an operating-system sandbox, an MSP platform, or a model-backed AI sysadmin.

## Executive assessment

Baseline status: **Not Yet Commercially Scalable**

The existing application is a capable single-machine control panel with useful authentication, authorization, auditing, filesystem containment, command allowlists, Laravel provisioning, database tooling, and operational workflows. Its architecture is compact and can be evolved incrementally without a framework rewrite.

It is not commercially scalable at the start of this program because important customer lifecycle and fleet foundations are missing:

- runtime behavior is coupled to one local machine and concrete service classes;
- XAMPP paths and Linux command assumptions are distributed across configuration and implementation;
- there is no stable machine identity or installation identity;
- no versioned authenticated machine-agent contract exists;
- no organization boundary or machine ownership model exists;
- licensing is represented only by settings, not fail-safe entitlements;
- release verification, atomic update, health validation, and rollback are absent;
- long-running work commonly depends on a browser request;
- installation is not yet a complete idempotent production onboarding flow;
- commercial account, MFA recovery, restore, and SMTP workflows are incomplete in the UI;
- the terminal and code runner are real local execution features, not an OS sandbox;
- the public demonstration mode and its isolation boundary do not exist;
- external API tokens, events, signed webhooks, and hub enrollment do not exist;
- automated coverage is insufficient for commercial release guarantees.

## Existing architecture

The current request path is:

```text
Browser
  -> index.php / Application
  -> authentication and page/API controllers
  -> concrete services
  -> Process execution / filesystem / MySQL / XAMPP
```

The target request path is:

```text
MicroSysAdmin Web UI
  -> application services and authorization
  -> LocalMachineAgentInterface
  -> versioned local agent client/transport
  -> local operations adapters
  -> Linux / XAMPP / MySQL / Git / filesystem
```

The target preserves the current local implementation while allowing the agent client to be replaced by an authenticated remote implementation in a future hub-managed product.

## Current strengths to preserve

- PHP 8.2+ lightweight codebase with few dependencies.
- Administrator, Operator, and Viewer roles with server-side permission checks.
- CSRF verification for mutations.
- Secure session defaults, idle/absolute expiration, remember-token hashing, login throttling, and TOTP support.
- Filesystem allowed-root and traversal protections.
- Command and service allowlists.
- Prepared statements for application and row-editing operations.
- Audit logging with sensitive-context redaction.
- Recoverable file and site deletion through trash.
- Broad, working single-machine feature coverage.
- No requirement for a hosted service during normal local operation.

## Architecture findings

### Controller coupling

`ApiController` creates concrete classes such as `SystemProbe`, `CommandRunner`, `FileVault`, `MysqlService`, `GitService`, `LaravelService`, `BackupService`, `CronService`, `ScriptService`, and `SandboxService` directly. This makes it difficult to substitute a remote machine or deterministic demo implementation.

### Environment coupling

The configuration centralizes some values, but implementation and scripts still assume combinations of:

- `/opt/lampp`;
- `/opt/lampp/htdocs`;
- `/tmp`;
- `/usr/bin/composer`;
- XAMPP PHP and MySQL binaries;
- `systemctl`;
- fixed storage subdirectories;
- local shell availability;
- local filesystem and database access.

There is no typed environment object, validation report, installation-time detection record, or administrator-facing environment editor.

### Service control

Service inspection and mutation are coupled to command construction. XAMPP, systemd, and read-only deployments need explicit drivers with advertised capabilities and safe unsupported-action results.

### Machine identity

The application currently derives a settings identifier from hostname, application path, and architecture. That identifier changes when mutable machine properties change and does not satisfy persistent commercial machine identity requirements.

### Data model

The schema contains users, remember tokens, audit logs, settings, backups, script runs, scheduled tasks, hosting sites, rate limits, and metrics. It does not yet model organizations, members, machine groups, licenses, plans, entitlements, invitations, support state, API tokens, webhooks, durable jobs, agent credentials, hub connections, releases, or update history.

Schema evolution is primarily implemented through `schema.sql` plus caught `ALTER TABLE` statements during startup rather than ordered, recorded migration files.

### Long-running operations

Provisioning, backups, restores, media conversion, Composer, Git, imports, exports, and some notifications can run synchronously inside web requests. Script and terminal workers exist, but there is no general durable queue with retries, ownership, progress, cancellation, and recovery after process failure.

### Updates

There is no signed release manifest, update-provider interface, preflight, maintenance mode, pre-update backup, versioned migration runner, post-update health gate, or automatic/manual rollback history.

### Installation and onboarding

The web installer can write configuration and initialize the database. It does not yet complete all production responsibilities: strong first-administrator creation, environment detection/override, machine identity, maintenance tasks, health checks, log rotation, agent setup, optional hub enrollment, and final diagnostics.

### Commercial workflows

Backend support exists for portions of MFA, user creation/reset, session revocation, and backup restore. Several are not complete visible workflows. MFA recovery codes, user disabling, SMTP configuration, restore integrity/pre-backup, and optional managed-site database cleanup are missing.

### Navigation and product claims

Several menu entries are alternate modes of the same workbench. `AI Tools` are currently rule-based helpers rather than model-backed AI. `Deploy` is a Git workbench label rather than a deployment pipeline. These labels should be consolidated and renamed honestly.

### Security profiles and demo

Terminal and code execution run with the web-server operating-system account. There is no stored Safe/Developer/Advanced installation profile enforcing feature gates. There is no isolated fake-data demo provider.

### Observability

Audit logging exists, but there is no common request correlation ID propagated through web requests, commands, jobs, backups, provisioning, updates, agent calls, and hub heartbeats. Health checks are not aggregated into a versioned, privacy-filtered service.

## Commercial risk register

| Risk | Severity | Baseline mitigation | Required commercial mitigation |
|---|---:|---|---|
| Browser terminal executes real shell commands | Critical | Authentication and role permission | Security profiles, explicit enablement, private-network guidance, no public API exposure |
| Long-running request interruption | High | Operation-specific timeouts | Durable database queue and worker |
| Update failure can strand installation | High | Manual file/database recovery | Signed manifests, snapshots, health gate, rollback |
| Machine identity changes with environment | High | Derived settings identifier | Random persistent machine and installation UUIDs |
| Remote/fleet evolution requires controller changes | High | None | Agent interfaces, versioned contract, client abstraction |
| Licensing outage could block operations if implemented poorly | High | No enforcement yet | Cached fail-safe entitlements and essential-access guarantee |
| Cross-organization data leakage in future hub | High | Single-tenant local app | Organization-scoped schema and authorization |
| Demo exposes host operations | Critical | No demo exists | Fake adapters and mutation-denying demo profile |
| Environment assumptions break customer installs | High | Current XAMPP config works | Detection, validated overrides, central environment object |
| Restore damages existing data | High | Administrator permission | Integrity checks, pre-restore backup, durable job, audit |

## Compatibility requirements

The migration must preserve:

- existing users and roles;
- settings;
- audit history;
- scheduled tasks and scripts;
- managed sites;
- backup history and archives;
- metric samples;
- current `/opt/lampp` operation;
- current configuration values.

The owner must not be required to reinstall. Migration tooling must back up configuration and the MicroSysAdmin database before applying schema or release changes.

## Planned incremental phases

1. Add ordered migration infrastructure and preserve the existing schema.
2. Create a centralized environment configuration and detection service with legacy fallbacks.
3. Generate persistent machine and installation UUIDs outside the public directory.
4. Add operation interfaces and XAMPP/systemd/inspection service-control drivers.
5. Add a local agent facade, signed `/agent/v1` requests, structured envelopes, and a local client.
6. Add organization, machine, license, plan, entitlement, API token, job, webhook, hub, and update models.
7. Enforce entitlements and security profiles through centralized policy services.
8. Add fail-safe licensing, disabled-by-default hub connectivity, and filtered outbound heartbeats.
9. Add signed update/rollback tooling and an idempotent production installer.
10. Move long operations to a durable database worker.
11. Complete commercial account, recovery, restore, SMTP, and settings workflows.
12. Add safe external API endpoints, events, signed webhooks, demo adapters, tests, and documentation.

## Audit acceptance criteria

The final readiness report must not award `Commercially Scalable` based on class or table presence. At minimum, it must verify:

- installation on more than the development layout;
- persisted identity across update simulation;
- actual controller use of the local agent boundary;
- authenticated and replay-resistant agent messages;
- organization isolation;
- fail-safe entitlement behavior;
- verified update and rollback;
- durable job recovery;
- demo isolation;
- usable self-service onboarding;
- migration of an existing installation;
- automated tests for the critical paths.
