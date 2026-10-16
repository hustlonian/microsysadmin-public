# Background jobs

The `jobs` table records UUID, organization, machine, type, JSON payload,
queued/reserved/running/completed/needs_reconciliation/cancelled state, progress,
attempts, retained retry limit, timeout, timestamps, owner, output, error and audit
correlation. Historical failed records remain readable. No schema change is needed.

Current handlers cover:

- database/folder backup and restore;
- media conversion;
- Composer and Artisan;
- Git actions;
- website provisioning;
- staged SQL import;
- scripts;
- notifications;
- maintenance health checks;
- hub heartbeat;
- webhook delivery.

Run one job:

```bash
/opt/lampp/bin/php scripts/job-worker.php --once
```

Run the worker continuously:

```bash
/opt/lampp/bin/php scripts/job-worker.php
```

Render a unit from the active environment:

```bash
php scripts/render-system-integration.php --user=daemon --group=daemon
sudo php scripts/render-system-integration.php --user=daemon --group=daemon --install
```

The worker transactionally reserves a never-attempted job. A child executor claims
that reservation once and holds a database-session advisory lock while it runs.
State changes check machine, UUID, attempt and current state; completion/progress
also check the reservation deadline using the database clock. A stale attempt
cannot overwrite a newer or terminal result. A process exit alone is not proof
of completion: the durable job record must contain it.

Each worker invocation uses the existing GNU coreutils `timeout` command at
`/usr/bin/timeout` or `/bin/timeout`. It creates a separate process group and
sends KILL at the configured deadline, including to nested commands that ignore
TERM. Its timer survives loss of the PHP supervisor. Symfony Process supplies
the outer launch/wait and a bounded fallback timeout. If the utility is absent,
the worker fails before reserving a job. No utility is installed automatically.
See the [GNU timeout manual](https://www.gnu.org/software/coreutils/manual/html_node/timeout-invocation.html).

On each worker tick and cron run, reconciliation holds expired reservations,
abandoned executors and historical queued retries as `needs_reconciliation`.
The current execution lock prevents an active worker from being mistaken for an
abandoned one. Timeouts, handler exceptions and unsuccessful handler results are
also held. The error and audit entry explain the cause. All such outcomes may
already have produced effects; none is automatically retried, even when the
retained `max_attempts` value is greater than one. Cancellation applies only to
queued jobs that have never been attempted.

To reconcile without executing queued work:

```bash
/opt/lampp/bin/php scripts/job-worker.php --reconcile
```

Inspect the job record, audit log, handler-specific receipts and affected target
before deciding whether an effect succeeded, failed or remains uncertain. A new
job requires an independently justified safe action; do not edit the old row
back to queued. The internal `--execute UUID ATTEMPT` entry is for the supervisor,
not the normal worker command or a retry interface. Historical completions and
uncertainty are preserved.

These controls prevent automatic re-execution of the same job; dispatching two
different UUIDs is not request deduplication. Process-group termination cannot
undo external effects, contain deliberately detached processes, or guarantee
termination of a kernel task stuck in uninterruptible I/O. Handlers must keep
their work in the supervised group. Killing the timeout utility itself is also
an uncertain interruption; reconciliation never assumes it stopped all effects.
Interactive cancellation of running work remains unsupported.

Run the generated-data acceptance suite with a separate private MariaDB socket
and TCP disabled (no installed configuration or database is used):

```bash
python3 tests/job-recovery-isolated.py --evidence-dir /tmp/job-recovery-evidence --application-suite
```

The runner retains its source/config/data and evidence for inspection, then
stops only its own MariaDB child. Tests cover success, unsuccessful effects,
nested-command timeout, supervisor death, duplicate execution, live ownership,
stale completion/progress/failure, abandoned workers, expired reservations,
legacy retries, cancellation and invalid bounds.
