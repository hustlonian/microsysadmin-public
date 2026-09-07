# Backups and restore

## Backup types

- Database backup uses the configured `mysqldump` binary and passes the database password through the child-process environment rather than the command line.
- Folder backup creates ZIP archives under `backup_root`.
- Update preparation creates an application rollback tarball and a database backup before file synchronization.
- Restore creates a new backup of the target before extraction.

Backup jobs record type, target, status, progress, archive path, timestamps, owner, organization, machine, and correlation identifiers.

## Create and monitor

Use the Backups workspace or queue an approved backup through `/api/v1`. Long backups run through the durable worker:

```bash
php scripts/job-worker.php
```

Confirm backup files are copied to storage outside the MicroSysAdmin machine. A local archive on the same disk is not disaster recovery.

Create and integrity-check a database backup from the installed managed stack:

```bash
sudo /usr/bin/php /opt/microsysadmin/app/scripts/backup_database.php
sudo unzip -t /var/lib/microsysadmin/backups/microsysadmin-TIMESTAMP.sql.zip
sudo sha256sum /var/lib/microsysadmin/backups/microsysadmin-TIMESTAMP.sql.zip
```

For XAMPP use `/opt/lampp/bin/php /opt/lampp/htdocs/microsysadmin/scripts/backup_database.php`. The installer creates an integrity-checked initial backup and a neighboring SHA-256 file. Repair/upgrade recovery sets are independently stored under `/var/lib/microsysadmin/installer-backups/TIMESTAMP-PID` with `SHA256SUMS`.

## Restore controls

The browser restore workflow currently accepts ZIP folder backups. It:

1. constrains the selected archive to `backup_root`;
2. opens it with ZIP consistency checking;
3. rejects absolute paths, traversal entries, drive paths, reserved metadata, and symbolic links;
4. creates a pre-restore backup;
5. extracts into an allowed existing directory;
6. records an audit entry.

Database restore is an explicit local-console operation. The script accepts only a single-entry MicroSysAdmin `.sql.zip` beneath the configured backup root, validates ZIP consistency and entry safety, creates a new pre-restore database backup, streams the selected dump through the configured client without placing the password in process arguments, and reports the preserved pre-restore archive:

```bash
sudo /usr/bin/php /opt/microsysadmin/app/scripts/restore-database.php \
  --archive=microsysadmin-TIMESTAMP.sql.zip --confirm
sudo /opt/microsysadmin/app/health-check.sh --release-gate
```

Use the XAMPP application path and PHP binary in XAMPP mode. Automatic application rollback preserves the pre-update database archive but intentionally does not import it; a database restore requires this explicit operator action because it can replace customer data.

## Recovery check

Before relying on a backup:

```bash
./health-check.sh
./diagnose.sh
```

Perform restore drills on a disposable installation. Verify application login, projects, managed sites, scripts, schedules, users, settings, and audit history—not merely that an archive can be opened.

The exact clean-install canary writes controlled data, creates and checks a backup, changes the data, runs the safeguarded restore, and verifies that the original value returns. It also confirms that uninstall creates a verified recovery bundle while preserving the database and backup root.

Large archives still require capacity and resource testing for the customer’s environment.
