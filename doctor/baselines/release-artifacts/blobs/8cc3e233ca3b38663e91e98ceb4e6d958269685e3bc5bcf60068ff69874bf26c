# Background jobs

The `jobs` table records UUID, organization, machine, type, JSON payload, queued/running/completed/failed/cancelled state, progress, attempts, retry limit, timeout, availability/reservation/completion times, owner, output summary, error, and audit correlation.

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

The worker transactionally reserves one job, updates progress, records output, and applies bounded exponential retry. Current cancellation stops queued work; cooperative cancellation of an already running child process and central enforcement of timeout metadata are future enhancements.
