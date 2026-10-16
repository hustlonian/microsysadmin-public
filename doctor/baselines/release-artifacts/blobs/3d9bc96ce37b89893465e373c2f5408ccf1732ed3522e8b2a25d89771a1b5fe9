# Known limitations

## Release and installation

- Downloadable customer archives intentionally contain no Git metadata. Each beta archive is tied to a recorded source commit, checksum, signed manifest, and annotated release tag in the private source repository.
- A clean disposable install and idempotent rerun passed from the release archive on the current XAMPP host. The same matrix has not run on separate clean distributions or a standard-package Apache/PHP layout.
- systemd, cron, and logrotate assets are rendered portably, but the installed services have not completed cross-distribution or long-running soak tests.
- The current configuration remains under the application/document tree for compatibility; private machine credentials live outside it, but moving all customer configuration outside the web tree is still recommended.
- The ignored configuration on the development host may explicitly enable Development licensing and development-only update fixtures. The `1.1.0-beta.1` customer defaults disable Development licensing and reject unsigned updates; the protected local development configuration is neither tracked nor packaged.

## Updates and recovery

- RSA-SHA256 signature verification, unsigned-update rejection, and an intentionally failed end-to-end update/application rollback are automated-tested. The signed beta has not completed the required install/update/backup/restore/rollback drill on a second clean machine.
- Application rollback does not automatically import the preserved database backup.
- A database migration that removes or transforms customer data must provide a release-specific reverse/data-restore procedure.

## Agent, hub, and organizations

- The default local agent transport is in-process. Signed HTTP transport exists, but not every legacy page has been proven against a remote-agent client.
- The included hub is only a contract mock. There is no hosted fleet console, billing, tenant UI, or production enrollment service.
- Organization-scoped commercial queries and isolation helpers exist for the local app, but the application still assumes one active local organization for login, filesystem discovery, and some host-global resources. It is not yet a hosted multi-tenant application.
- Studio and Agency plan records are product definitions, not proof that multi-machine hosted workflows are production-ready.

## Jobs and integrations

- Job deadlines use GNU coreutils timeout and a separate process group. Interrupted, expired or unsuccessful attempts require effect reconciliation and are never automatically retried. Interactive cancellation of running jobs, deliberately detached processes, cross-UUID request deduplication and external-effect rollback remain unsupported. See [background jobs](BACKGROUND-JOBS.md).
- systemd worker restart behavior has not been soak-tested in this repository.
- Webhook signing is tested; live delivery/retry across external receivers has not completed a production integration test.
- SMTP settings and test-send UI exist, but no customer SMTP matrix was tested.

## Security and demo

- Terminal and Code Runner are machine-level risk tools, not an OS sandbox. They should stay disabled outside trusted Developer/Advanced installs.
- Demo operations are isolated by the fake agent/controller path; product/agent APIs, workspace preview, password changes, real scheduling, terminal, and host mutations are disabled. A public demo still needs a separate Safe-mode machine, demo account policy, reverse proxy limits, and reset supervision.
- Restore validates ZIP integrity and traversal/symlink hazards and creates a pre-restore backup, but large-archive resource exhaustion and every application-specific restore outcome require further testing.
- MFA/recovery/user workflows are implemented but do not yet have browser automation coverage.

## Product scope

There is no cPanel replacement, public cloud IDE, MSP tenant portal, arbitrary deployment pipeline, remote terminal fleet feature, payment collection, hosted license server, or model-backed AI.
