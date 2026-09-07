# Rollback

Update snapshots are stored under the configured `updates.release_root`, normally `/var/lib/microsysadmin/releases`.

Automatic rollback runs when an update extraction, synchronization, migration, or post-update health check fails. Manual rollback is:

```bash
./rollback.sh /var/lib/microsysadmin/releases/rollback-1.1.0-TIMESTAMP.tar.gz
```

The script only accepts an existing `.tar.gz` beneath the configured release root. It enters maintenance mode, restores application files, reruns idempotent migrations, seeds required reference data, and leaves maintenance mode only if installation-critical health checks pass.

Installer repair and source-checkout upgrade have a separate rollback layer. Before either action, `install.sh` calculates the required rollback space and creates a verified application snapshot, database ZIP, and `SHA256SUMS` beneath `/var/lib/microsysadmin/installer-backups`. If a later installer stage fails, it restores the exact prior application tree and MicroSysAdmin Apache configuration. The verified database archive remains available for the explicit restore procedure in [Backups](BACKUPS.md).

Important limitation: the current automatic path restores application files and preserves the pre-update database archive, but it does not automatically import that database archive. A destructive/data-transforming release must provide and test a reversible migration or a documented database restore procedure before publication.

Before a manual rollback:

1. preserve the current broken tree and database;
2. identify the exact update-history entry;
3. verify the rollback archive checksum;
4. confirm whether the release changed data incompatibly;
5. run rollback from a local console, not through the affected browser request.

Automated evidence: `tests/isolated-install.sh` and the clean Ubuntu canary delete the scheduler runner from signed disposable update packages, confirm the post-update gate fails, confirm automatic application rollback restores it, check maintenance state and update history, and rerun the critical health gate. The clean canary also applies a valid ephemeral RSA-signed update and performs a successful manual rollback.
