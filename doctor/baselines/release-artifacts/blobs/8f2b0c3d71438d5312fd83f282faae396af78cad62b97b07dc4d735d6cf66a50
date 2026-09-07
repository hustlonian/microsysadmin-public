# Troubleshooting

## Start with diagnostics

```bash
./install.sh --check
./diagnose.sh
./health-check.sh
```

`diagnose.sh` reports detected and configured values. `health-check.sh` exits non-zero when any operational health check fails. `repair.sh` reruns idempotent migrations/reference seeding and succeeds when installation-critical checks pass, even if an intentionally stopped service leaves overall health degraded.

For a managed production install, logs and machine-readable latest diagnostics are under `/var/log/microsysadmin`; Apache logs are under `/var/log/apache2`. XAMPP Apache logs are under `/opt/lampp/logs`. The installer prints its exact `install-TIMESTAMP.log` on failure and never intentionally records passwords.

```bash
sudo tail -n 200 /var/log/microsysadmin/install-*.log
sudo cat /var/log/microsysadmin/diagnostics-latest.json
sudo cat /var/log/microsysadmin/health-gate-latest.json
```

## Database connection

Check the configured host, port, and socket. XAMPP commonly uses:

```text
/opt/lampp/var/mysql/mysql.sock
```

Standard packages commonly use `/run/mysqld/mysqld.sock`. Do not copy credentials into support tickets or command output.

## Apache reports stopped

MicroSysAdmin can still run CLI diagnostics while Apache is stopped. Inspect with the selected driver:

```bash
/opt/lampp/lampp status
```

Use the Services workspace only when the security profile, role, driver, and helper permit mutation.

## Worker is idle

```bash
php scripts/job-worker.php --once
sudo systemctl status microsysadmin-worker.service
```

Review the rendered unit under the configured storage root. Confirm its user can read the application/config and write storage, backup, workspace, and allowed project roots.

## Update fails

Read update history in Administration → Updates. Verify:

- manifest version/channel/timestamp;
- SHA-256;
- `signature_algorithm` and configured public key;
- `updates.until`;
- free storage;
- rollback archive under `updates.release_root`;
- migration and critical-health errors.

If automatic rollback does not pass:

```bash
./rollback.sh /absolute/configured/release/root/rollback-VERSION-TIMESTAMP.tar.gz
```

Do not import a pre-update database backup until the release’s data-compatibility instructions require it.

## Installer permissions

The production path must run as root: `sudo ./install.sh`. Managed configuration is `0640 root:www-data`; persistent data roots are `0770 www-data:www-data`. XAMPP uses the `daemon` service group. Run `sudo ./install.sh --repair` to reapply MicroSysAdmin ownership, permissions, routes, and service integration after inspecting the failure log.

## Port or service conflict

MicroSysAdmin does not stop or replace unrelated web/database services. If preflight reports Nginx, unmanaged MySQL, or a port-80 owner, move MicroSysAdmin to a dedicated supported Ubuntu machine or intentionally remove/relocate the conflicting service yourself. If `/opt/lampp` is partial, repair XAMPP or remove that partial tree before selecting managed mode. HTTPS requires a working TLS listener before installation; use an HTTP URL on a protected network until TLS is deliberately configured.

## Recovery backup

Before repair/upgrade, validate the reported recovery set:

```bash
cd /var/lib/microsysadmin/installer-backups/TIMESTAMP-PID
sudo sha256sum -c SHA256SUMS
sudo tar -tzf application.tar.gz >/dev/null
sudo unzip -t microsysadmin-TIMESTAMP.sql.zip
```

Do not import a database archive merely because an application repair failed. The installer automatically restores application and Apache state when safe; use the explicit database restore only when migrations or controlled recovery require it.

## Support bundle discipline

Share version, OS, architecture, sanitized diagnostics, failed check names, request/job/update IDs, and relevant redacted log lines. Never share `config/config.php`, `.env`, database dumps, machine identity, agent/hub credentials, API tokens, SMTP passwords, source code, or terminal output.
