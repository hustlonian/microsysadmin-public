# MicroSysAdmin Doctor

MicroSysAdmin Doctor is the shared diagnostics and repair-library platform used by the authenticated web workspace and CLI. Both surfaces call `DoctorApplication`; controllers and views contain no diagnosis or repair logic.

The first released library batch contains 20 independently versioned modules and 200 permanent repair records. Every record has a separate check, evidence-backed diagnosis, repair or report-only definition, preview plan, independent verification probe, fixture contract, documentation page, and machine-readable inventory entry. Unsafe or ambiguous conditions stay report-only.

## Lifecycle

Each execution discovers the environment, filters unsupported units, runs checks, records sanitized evidence, diagnoses causes, builds policy decisions and plan hashes, creates required recovery data, executes only authorized actions, verifies independently, automatically rolls back supported failed verification, rescans, scores before and after, and writes JSON, text, HTML, and PDF-ready HTML reports.

Runtime files are deliberately outside the HTTP-accessible library at `storage/doctor/`:

- `storage/doctor/backups/` contains scoped recovery manifests and content snapshots.
- `storage/doctor/reports/` contains generated reports.
- `storage/doctor/locks/` contains the global mutation lock.
- Database tables preserve executions, exact-plan approvals, reports, and sanitized audit events.

Direct HTTP access to Doctor source, manifests, inventories, fixtures, and configuration is denied. `/microsysadmin/doctor/` renders only through the normal authenticated MicroSysAdmin layout and role permissions.

## CLI

```bash
doctor/bin/doctor discover
doctor/bin/doctor inventory
doctor/bin/doctor scan --module=mysql
doctor/bin/doctor diagnose
doctor/bin/doctor plan --id=LARAVEL-0004
doctor/bin/doctor repair --safe
doctor/bin/doctor approve --execution=doc_... --id=LARAVEL-0004 --plan-hash=...
doctor/bin/doctor repair --id=LARAVEL-0004 --approval-execution=doc_...
doctor/bin/doctor rollback --execution=doc_...
doctor/bin/doctor report --execution=doc_...
doctor/bin/doctor health
doctor/bin/doctor list:modules
doctor/bin/doctor list:checks
doctor/bin/doctor list:diagnoses
doctor/bin/doctor list:repairs
doctor/bin/doctor describe:repair LARAVEL-0004
```

Use `--dry-run`, `--json`, `--no-interaction`, `--approval-policy`, `--project-path`, `--web-user`, `--environment`, `--module`, `--id`, `--include`, `--exclude`, `--severity`, `--risk`, and `--execution` as appropriate. Approval-required GUI execution consumes an approval bound to the exact deterministic plan hash.

## Add one library record

Add independent files beneath the owning module’s `Checks`, `Diagnoses`, and `Repairs` directories; add its fixture, documentation, and `repair-inventory.json` entry. The registry discovers it automatically. Never edit a controller, route, dashboard, engine switch, or central registration array. Permanent IDs are validated for module prefix and uniqueness and are never reused.

For scalable authoring, add a reviewed `repair_generation` blueprint to a diagnosis and run `doctor/bin/generate-repair --diagnosis=/absolute/path/to/diagnosis.json`. The generator emits the repair, exact verification and rollback contracts, documentation, fixture, changelog, commercial metadata, and permanent inventory record. `tools/build-library.php` is a guarded historical bootstrap only; it cannot overwrite the SDK-migrated commercial library unless its explicit legacy flag is supplied.

## Verification

```bash
doctor/tests/run.php
doctor/tests/sdk.php
doctor/tests/time-forward.php
doctor/tests/directory-time-forward.php
doctor/tests/privileged-helper.sh
/opt/lampp/bin/php tests/run.php
doctor/bin/doctor scan --no-reports
```

The isolated Doctor suite tests registry coverage, metadata, fixtures, structured command safety, injection resistance, redaction, timeout enforcement, allowed paths, atomic writes, locking, detection, diagnosis, planning, repair, verification, idempotency, rollback, unsupported environments, scoring, reporting, and audit persistence. It never performs destructive integration tests against the active machine.
