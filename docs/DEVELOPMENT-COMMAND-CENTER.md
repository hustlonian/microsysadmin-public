# Development Command Center

The existing authenticated AdminLTE dashboard now includes **Development → Command
Center** at `development-command-center`. The existing Dashboard remains intact.
The host is a read-only client of sanitized Software Ops controller projections;
it never opens the workflow database or exposes private controller files.

## Explicit project binding

A reviewed installation configures `development.projects` in the protected local
configuration. No project or organization is automatically enrolled. Each entry
uses a stable project ID and contains:

- `organization_id`: the exact enrolled organization ID;
- `tenant_key`: the exact key in the authoritative projection;
- `name`: the name shown in the authorized project selector;
- `projection_file`: a fixed private, readable JSON file outside the document root;
- `users`: a mapping of explicitly assigned local user IDs to `owner`, `developer`,
  `agent`, or `viewer` project roles.

Every view, partial refresh, JSON endpoint and export rechecks the authenticated
account, active organization, active exact organization membership, user's own
organization ID, and explicit project assignment. Legacy global Administrator
privileges do not bypass these checks. A tenant owner may use a legacy Viewer
account and gains no system settings, user administration or OS privileges.
The legacy CommercialContext fallback is deliberately not used for this feature.

The trusted enrollment configuration is not editable by a tenant through this
feature. Only a platform administrator may provision or change bindings under a
separately reviewed installation. Do not put the full controller checkpoint,
credentials or customer database in a projection directory. Do not symlink the
projection file. Paths and tenant bindings never come from request parameters.

## Data and evidence

The existing sole continuation writer exports a `DEVELOPMENT_COMMAND_CENTER`
projection beside the owner-status report and live Markdown. The project manifest
is hash-bound to the controller. Source generation, timestamp and manifest hash
travel with the data. The writer and its mailbox remain the sole canonical writer.

Digital Empire includes twelve declared workstreams and LG-01 through LG-12.
Imported historical checkpoints require intact acceptance receipts and artifact
hashes. Changed-file lists are read from their actual source commits; unavailable
file lists and missing historical author identities are explicitly unverified.
Historical infrastructure tests never complete a whole workstream or unlock a
new top-level gate. The initial versioned Definition of Done is displayed with
separate commercial, production and fully accepted initial-scope requirements.

Views cover executive status, filterable accomplishments with files/commit/tests/
receipt identity/limitations, current activity, the full authorized controller
roadmap, gate dependencies and exact unmet criteria, and scoped Markdown/JSON
exports. Readiness remains NOT_VERIFIED until independent acceptance supports it.

The reader recomputes freshness and heartbeat age at request time. A heartbeat
older than 60 seconds, in the future or missing is never active. Missing, corrupt,
oversized, mismatched-tenant or symlinked files fail closed. Bounded polling
refreshes the actual scoped server-rendered content every 30 seconds, pauses on
hidden pages or active form input, uses an eight-second timeout and stops after
120 requests. Cached output retains its as-of time and cannot claim live activity.

## Validation and retained limits

Use `tests/job-recovery-isolated.py --development-projection /approved/projection`
with an isolated evidence directory. It creates a fresh network-disabled MariaDB,
synthetic local identities and generated application configuration, then replays
the actual sanitized project projection. Production config is never copied.
`tests/development-command-center.browser.cjs` accepts only a loopback fixture URL.
It checks authenticated routes, views, exports, polling and cross-tenant denial,
and captures desktop/mobile rendering. It does not accept a production release.

This feature enables no gate approvals, deployments, spend or recovery actions.
Owner approval request/actions and independent gate verification stay in the
canonical LockGate domain. A future authenticated mutation bridge must be
separately verified; a host read projection is never mutation authority. New
product code and enrollment have not been deployed by this local development.

## Workflow-domain gate snapshots

An installation may explicitly configure a second private read-only
`domain_projection_file` in the same project binding. The candidate Software Ops
command `development:export-domain <project> --actor=<authorized-user-id>` emits
this `LOCKGATE_DOMAIN` contract from the existing workflow database. It exports no
private artifact paths and confers no host mutation authority. There is no default
enrollment, scheduled producer, shared session, or installed configuration change.
Use reviewed atomic file replacement when authorized to enroll the producer.

The host checks exact tenant/project/scope version, all committed gate definitions,
workstreams and Definition of Done, unique gate identities, source/decision
revisions and independent acceptance results. It shows actual unmet criteria and
workflow provenance in the gate board and scoped JSON export. Missing or invalid
snapshots leave the authorized roadmap visible and explicitly label domain status
NOT_ENROLLED or UNAVAILABLE. Declaration states are not database acceptance.

Snapshots older than 60 seconds or from the future cannot assert current gate
acceptance: the view becomes BLOCKED while retaining the historical stored state.
Expired executor heartbeats also block ACTIVE display. The browser independently
expires visible claims when a refresh fails. Overall product readiness remains
NOT_VERIFIED. This adds read access only; owner decisions remain in the canonical
backend until a separately verified principal/revision/idempotency/audit contract
connects the applications.

For integration tests, `--development-domain` accepts only an explicitly labeled
disposable canonical importer replay, alongside `--development-projection`. Its
real roadmap/DRAFT records are rendered using synthetic accounts and databases;
no test output is written as actual Customer Zero gate acceptance.
