# MicroSysAdmin product and features

## Product definition

> MicroSysAdmin is a self-hosted Laravel and PHP development-server workspace for Linux. It combines project provisioning, XAMPP control, files, MySQL, Artisan, Composer, Git, backups, logs, automation, system monitoring, and optional terminal access in one browser interface.

MicroSysAdmin brings the recurring work of running Laravel and PHP projects on a Linux development server into one local browser application. It is deliberately narrower than a generic server-control panel: projects and the local PHP/XAMPP toolchain are the organizing concepts.

## Project workspace

- **Overview and discovery:** discovers managed and existing PHP/Laravel sites beneath configured document roots, reports document root, domain, framework status, PHP details, Git state, database linkage, and size.
- **Provisioning:** creates a managed site in an allowed root, validates names and domains, records the site, and emits success/failure events. This is local project provisioning, not a remote deployment pipeline.
- **Pages:** inspects managed project pages and routes through the existing site workbench.
- **Files:** browses allowed roots, previews and edits supported text files, searches files, creates folders/files, uploads, downloads, and moves deletions into a configured trash root. Path guards prevent escaping allowed roots.
- **Environment:** exposes project environment-file tooling to authorized local administrators. Secrets remain local and are excluded from audit, hub, event, and webhook payloads.
- **Artisan:** lists approved Laravel projects and runs Artisan operations. Long operations can be database-queued.
- **Composer:** runs Composer against an approved project using the configured binary and bounded process execution. Long operations can be queued.
- **Git Actions:** shows repository state, branch/history information, diff/status, and approved Git actions. The label does not imply a deployment pipeline.
- **Database:** lists databases and tables, browses rows, and supports approved MySQL operations. SQL import is security-profile gated and staged in the private workspace.
- **Logs and Log Insights:** reads configured application/server logs, filters entries, and provides deterministic pattern summaries. “Insights” are not model-backed AI.
- **Backups:** creates database and folder archives, tracks status/history, verifies restore archives, creates a pre-restore backup, and exposes an administrator restore workflow.
- **Automation:** manages stored scripts and scheduled tasks. Scheduled backups require their entitlement. Execution is audited.

## Machine workspace

- **Overview:** CPU, memory, swap, disk, network, load, uptime, service summaries, temperature where available, process summaries, recent logs, jobs, and projects.
- **Health:** checks application bootstrap, database, configured paths, storage, Apache, MySQL, Composer, Git, scheduler, backup recency, agent, update service, and optional hub.
- **Services:** uses XAMPP, systemd, or inspection-only drivers. Unsupported operations return an explanation instead of falling through to arbitrary shell commands.
- **Processes:** lists bounded process information and supports existing permission-gated process actions.
- **Storage and files:** reports storage usage and provides guarded file tooling.
- **Networking:** reports local network summaries; MicroSysAdmin does not configure a public perimeter.
- **Logs:** reads only configured log sources.
- **Machine backups:** database/folder backup history and restore controls.
- **Scheduled tasks:** local scheduler inventory and managed tasks.
- **Security:** current installation profile, capability availability, users, MFA, audit, session, and diagnostic status.

## Administration workspace

- **Users and roles:** visible user creation, Administrator/Operator/Viewer assignment, disable/enable, password reset with forced change, session revocation, and logout-all. Last-administrator and self-disable protections remain.
- **MFA:** TOTP enrollment, confirmation, one-time recovery codes stored as hashes, regeneration, and removal.
- **Audit:** structured records with organization, machine, request, session, user, safe context, and timestamps. Sensitive fields are redacted.
- **Machine information:** stable random machine UUID and installation UUID, display name, host/OS/architecture, MicroSysAdmin/PHP version, roots, dates, license, organization, and hub connection state.
- **Environment:** centralized runtime paths and connection settings with auto-detection and administrator overrides.
- **Licensing:** Community, Developer, Studio, Agency, and local Development plans represented by entitlements rather than plan-name branches.
- **Updates:** release channel, status/history, signed/checksummed package verification, pre-update backup, maintenance mode, migration, health check, and rollback tooling.
- **Notifications:** SMTP configuration, encrypted stored password, and connection/send testing.
- **Hub connection:** optional enrollment, connection test, credential rotation, data-sharing controls, heartbeat, and disconnect. No inbound public connection is required.
- **API tokens:** scoped, hashed, expiring, revocable, rate-limited tokens for the constrained `/api/v1` surface.
- **Webhooks:** signed optional endpoints, event filters, enable/disable, delivery history, timeouts, and durable retries.
- **Jobs:** queue status, progress, attempts, owner, machine, audit correlation, and output summary.
- **Settings:** timezone application, theme application, security profile, and operating preferences.

## Assistants and code execution

Operations Assistant and Log Insights are deterministic local helpers; they are not marketed as model-backed AI. Code Runner and browser terminal remain high-risk local-development tools. Safe mode disables them. Developer and Advanced modes require deliberate enablement, and agent/API terminal access remains separately disabled by default.

## Commercial and fleet foundation

Available now:

- stable per-installation identity;
- one local organization with member/machine/group/license/plan/entitlement/support schema;
- organization-scoped commercial records and authorization helpers;
- fail-safe local entitlement evaluation with no remote kill switch;
- an in-process agent client plus signed `/agent/v1` transport;
- an outbound hub client contract and filtered heartbeat;
- update-provider, license-provider, hub-client, and operation interfaces;
- database-backed jobs, events, webhooks, API tokens, and audit correlation;
- Safe/Developer/Advanced installation profiles;
- a fake-data demo agent that never calls real host drivers.

Prepared, but requiring deployment validation or a separate service:

- Studio/Agency multi-machine use requires a real hosted hub and a hub user interface;
- remote-agent transport is implemented as a signed client/server contract but the UI defaults to the in-process client;
- production release signing requires an offline RSA-SHA256 or Ed25519 signing key and a configured public key;
- worker/health/scheduler/logrotate assets are rendered from the installed environment and require explicit root installation;
- public demo hosting requires a dedicated Safe-mode installation and reset scheduler.

Not implemented:

- hosted fleet console, billing, payment collection, customer portal, marketplace, inbound remote terminal, arbitrary external command/SQL/file APIs, full deployment pipeline, MSP tenant portal, and model-backed AI.
