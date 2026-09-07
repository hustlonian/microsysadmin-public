# Scalability readiness report

> Historical foundation report. See `COMMERCIAL-READINESS-REPORT.md` for the current validation evidence and commercial decision.

Date: 2026-07-27
Version: `1.1.0-dev`
Status: **Commercially Scalable with Conditions**

This status means the code now has a credible commercial foundation for a small, assisted single-machine Developer-edition pilot. It does **not** mean broad self-service, Studio/Agency fleet sales, or a hosted multi-tenant service are ready.

## Evaluation

| Criterion | Result | Evidence and condition |
|---|---|---|
| One installer supports multiple customer environments | Partial | Detection and overrides cover XAMPP and standard binaries/roots. Current host passes repeatable `--check`. Clean installs across distributions and rendered service templates remain required. |
| Hardcoded local assumptions removed | Partial | Runtime operations use `EnvironmentConfig`; `/opt/lampp` remains a compatibility fallback. Packaging templates and some legacy/default paths still require environment rendering. |
| Stable machine identity | Pass | Random v4 machine and installation UUIDs persist in private storage and pass repeated-load tests; identity is not derived from mutable hardware/network fields. |
| Local agent boundary complete | Conditional | Versioned contracts, local agent, in-process client, signed HTTP client, HMAC/replay protection, and structured envelopes work. Primary machine operations are represented, but every legacy UI workflow has not been remote-transport tested. |
| Future hub without full rewrite | Pass architecturally | `HubClientInterface`, enrollment/rotation/test/disconnect, filtered outbound heartbeat, admin UI, and mock contract exist. The production hosted hub is separate and not implemented. |
| Entitlement-based licensing | Pass for local enforcement | Plan/entitlement data and outage/expiry behavior are tested. Terminal, scheduled backups, users, hub, and update dates use entitlements. Issuance, refresh, billing, and fleet reconciliation are absent. |
| Updates and rollback work | Conditional | Semantic/channel/checksum/signature/entitlement validation, backups, maintenance, staging, migrations, health, history, and automatic/manual application rollback are implemented. A real signed end-to-end release/rollback drill is still a launch gate. |
| Background jobs durable | Pass with operational condition | Database reservation, states, progress, retries, owner/machine/audit fields, worker, and service template exist; health-job execution passes. Worker soak testing, central hard timeout, and running cancellation remain. |
| Demo mode isolated | Pass in code | Fake metrics/projects/databases/logs/Git/backups/audit and simulated mutations are routed through the demo driver; unsafe mutation isolation passes. A dedicated public deployment/reset supervisor remains required. |
| Organization data scoped | Conditional | Organization/membership/machine/group/license schema, context, query scope, API scope, and cross-org denial exist. The local app intentionally boots one active organization and is not a complete hosted multi-tenant boundary. |
| Commercial onboarding self-service | Partial | Installer, first Administrator, user/role/disable/reset/session UI, MFA/recovery, environment, SMTP, profile, licensing, updates, hub, API, webhook, and restore screens exist. Cross-environment clean install and browser automation remain. |
| Existing users migrate safely | Pass on current data | Pre-change app/database backups exist. Idempotent migration preserves user, audit, task, script, site, backup, and metric record counts in automated tests. |

## Test evidence

`/opt/lampp/bin/php tests/run.php` result:

```text
18 passed, 0 failed
```

Coverage includes installer idempotency, detection, identity, authorization, organization isolation, entitlements, expired updates, licensing outage behavior, agent authentication/signing/replay, hub abstraction, heartbeat filtering, update verification, rollback syntax, queue execution, API token storage/scope/expiry/revocation, webhook signatures, demo isolation, Safe restrictions, and current-install migration.

PHP lint passes across application PHP files. The current live health check is **degraded only because Apache is stopped**; database, filesystem/configuration, MySQL, Composer, Git, scheduler, backup recency, agent, and optional hub checks otherwise pass.

## Remaining commercial blockers

1. Put the project under source control and build a reproducible release.
2. Run clean-install and upgrade/rollback matrices outside this single host.
3. Perform a real Ed25519-signed release and database recovery drill.
4. Render/install/test standard-path systemd, cron, and logrotate assets.
5. Complete an independent security review and browser workflow automation.
6. Disable development licensing/unsigned updates in the customer artifact.
7. Build the real hosted hub before selling fleet/Agency features.

## Launch recommendation

Offer only single-machine Developer licenses through assisted onboarding. Maximum initial paid customers: **5**. Keep each to one machine, preserve an operator-assisted rollback path, and hold Studio/Agency sales until the hosted and multi-environment gates pass.
