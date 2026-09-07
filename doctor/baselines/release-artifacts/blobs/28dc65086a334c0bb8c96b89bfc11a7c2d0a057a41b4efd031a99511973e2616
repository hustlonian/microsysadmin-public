# Upgrading from the current MicroSysAdmin installation

The commercial foundation migrates in place. It preserves users, settings, audit records, scheduled tasks, script run history, managed sites, backup history/archives, metric samples, and current environment behavior.

## Before upgrading

```bash
stamp="$(date +%Y%m%d-%H%M%S)"
mkdir -p "/tmp/microsysadmin-upgrade-$stamp"
tar -czf "/tmp/microsysadmin-upgrade-$stamp/application.tar.gz" \
  -C /opt/lampp/htdocs sysadmin
/opt/lampp/bin/mysqldump -uroot --single-transaction --routines --triggers sysadmin \
  | gzip > "/tmp/microsysadmin-upgrade-$stamp/database.sql.gz"
cp config/config.php "/tmp/microsysadmin-upgrade-$stamp/config.php"
```

Protect that directory; it contains customer configuration.

## Migrate

```bash
cd /opt/lampp/htdocs/sysadmin
./repair.sh
./diagnose.sh
/opt/lampp/bin/php tests/run.php
./tests/isolated-install.sh
```

The migration runner records each file and checksum in `schema_migrations`. It refuses to silently run an edited migration. Commercial bootstrap creates one local organization, links the existing users, creates the machine row from persistent identity, and scopes legacy records where a new scope column exists.

## Verify

- existing Administrator can log in;
- existing roles are still Administrator, Operator, or Viewer;
- sites, scripts, tasks, backup history, metrics, and audit records remain;
- current `/opt/lampp` paths resolve through Environment Configuration;
- backup creation and restore validation work;
- machine UUID remains unchanged on repeated repair;
- Safe/Developer profile matches the intended environment;
- application is not publicly exposing terminal or agent endpoints.

The migration test in `tests/run.php` snapshots record counts, reruns schema/migrations/seeding, and verifies the protected tables are unchanged.

`tests/isolated-install.sh` validates a clean release package and does not modify the live application database. It creates and removes one uniquely named disposable database.
