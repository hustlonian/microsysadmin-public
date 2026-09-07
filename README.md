# MicroSysAdmin

> MicroSysAdmin is a self-hosted Laravel and PHP development-server workspace for Linux. It combines project provisioning, XAMPP control, files, MySQL, Artisan, Composer, Git, backups, logs, automation, system monitoring, and optional terminal access in one browser interface.

MicroSysAdmin is a lightweight PHP 8.2+ application. The default deployment manages one Linux machine and remains useful without a network connection, license server, or central hub. Its commercial foundation adds stable machine identity, environment drivers, organization-scoped records, entitlement-based licensing, signed agent requests, durable jobs, update verification, a constrained product API, optional outbound hub heartbeats, and an isolated demonstration driver.

It is not a cPanel replacement, public cloud IDE, operating-system sandbox, MSP platform, or hardened Internet-facing terminal. Keep production MicroSysAdmin behind a private network, VPN, or equivalent access control.

## Install MicroSysAdmin

```bash
git clone https://github.com/hustlonian/microsysadmin-public.git microsysadmin
cd microsysadmin
sudo ./install.sh
```

The interactive installer asks only for database credentials when the selected database authentication requires them, then installs and configures the complete application. It securely generates the first Administrator, stores the one-time login in `/var/lib/microsysadmin/private/initial-administrator.txt` as `0600 root:root`, and requires a password change at first login. Ubuntu 24.04 LTS on x86_64 or arm64 is supported. Use a private network or VPN; Safe mode disables browser terminal access by default.

MySQL/MariaDB is required and is not an optional integration. The installer installs or validates it, creates the `microsysadmin` database plus a restricted `microsysadmin` application account, stores that generated connection only in the protected production configuration, runs every schema migration against it, and refuses success unless the application can query the configured database.

If a healthy compatible `/opt/lampp` exists, the installer uses it without installing a competing stack, stores MicroSysAdmin at `/opt/lampp/htdocs/microsysadmin`, and serves it at `http://localhost/microsysadmin`. A deliberately overridden XAMPP target at `/opt/lampp/htdocs/<directory_name>` maps to `http://localhost/<directory_name>`. Otherwise it installs the managed Apache 2.4, PHP 8.3, MariaDB, Composer, Git, scheduler, worker, health timer, and log rotation stack at `/opt/microsysadmin/app`, also served at `http://localhost/microsysadmin`. It requires root, 2 CPU cores, 2 GiB RAM, 5 GiB free disk, Internet package access, and an available port 80. HTTPS installations require an already configured port 443 listener.

For automated installation, keep passwords out of command arguments and shell history:

```bash
read -rsp "Database administrator password: " MICROSYSADMIN_DB_ADMIN_PASSWORD
export MICROSYSADMIN_DB_ADMIN_PASSWORD
sudo --preserve-env=MICROSYSADMIN_DB_ADMIN_PASSWORD env \
  MICROSYSADMIN_DB_ADMIN_USER=root \
  ./install.sh --non-interactive
unset MICROSYSADMIN_DB_ADMIN_PASSWORD
```

Ubuntu MariaDB normally uses root socket authentication, so the password may be omitted there. Administrator variables remain available when automation needs a predetermined login; otherwise retrieve the generated login with `sudo cat /var/lib/microsysadmin/private/initial-administrator.txt`, sign in at `http://localhost/microsysadmin/login`, change the password, and remove that credential file.

Running `sudo ./install.sh` again validates an existing installation and does not replace secrets, users, or customer data. Changes require an explicit `--repair` or `--upgrade`; both create and verify application and database rollback backups first.

Read [Installation and recovery](docs/INSTALLATION.md), [Quickstart](docs/QUICKSTART.md), [Backups](docs/BACKUPS.md), [Supported environments](docs/SUPPORTED-ENVIRONMENTS.md), [Security](docs/SECURITY.md), and the [exact clean-install canary](tools/github-test-vm/README.md).

## Try the demo

After installation, run `sudo php scripts/demo.php enable` (or `sudo /opt/lampp/bin/php scripts/demo.php enable` on XAMPP), then open `http://localhost/microsysadmin/login`.

| Username | Password |
|---|---|
| `admindemo` | `AdminDemoPassword` |

Demo mode uses sample data and simulates server actions. The shared login works only while demo mode is enabled. See [Demo setup and disabling](docs/DEMO.md) for details.

## Operational commands

```bash
./health-check.sh
./diagnose.sh
./repair.sh
./update.sh check
./update.sh apply /absolute/manifest.json /absolute/microsysadmin.tar.gz
./rollback.sh /var/lib/microsysadmin/releases/rollback-VERSION-TIMESTAMP.tar.gz
/opt/lampp/bin/php scripts/job-worker.php --once
/opt/lampp/bin/php scripts/demo.php enable
doctor/bin/doctor scan
doctor/bin/doctor health
doctor/bin/doctor repair --safe
./build-release.sh --channel development --unsigned-development
```

The authenticated [MicroSysAdmin Doctor](doctor/README.md) workspace and CLI share one evidence-first engine. Its initial production batch includes 20 modules and 200 independent checks, diagnoses, repair/report definitions, verification probes, fixtures, documentation pages, and permanent inventory records. Reports are emitted as JSON, text, HTML, and PDF-ready HTML; ambiguous or destructive findings remain report-only.

The installer renders systemd, cron, and logrotate assets from the actual environment. Files under `packaging/` are tokenized reference templates and must not be copied without rendering.

## Current boundary

The local agent executes in-process by default, but the UI and product API use an agent client contract. `/agent/v1` is HMAC authenticated and replay protected for an out-of-process or future remote client. Hub connectivity is optional, outbound only, entitlement checked, and disabled by default. The included hub router is a development contract mock, not a hosted service.

See [Known limitations](docs/KNOWN-LIMITATIONS.md) before charging customers.

## Terminal Git workflow

MicroSysAdmin repository operations use the fail-closed terminal manager:

```bash
./microsysadmin-git doctor
./microsysadmin-git recover
./microsysadmin-git audit
./microsysadmin-git prepare
./microsysadmin-git verify
```

The manager never embeds credentials, blindly stages the tree, force-pushes, or publishes a release. Read [Terminal Git workflow](docs/TERMINAL-GIT-WORKFLOW.md), [remote recovery](docs/GITHUB-REMOTE-RECOVERY-REPORT.md), and [terminal readiness](docs/GITHUB-TERMINAL-READINESS-REPORT.md) before attaching a GitHub remote.
