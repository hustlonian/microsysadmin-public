# BACKUP-0009: Add the missing database backup schedule

Add the missing database backup schedule.

## Detection

Runs only the BACKUP-C0009 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `AUTO_WITH_BACKUP`. Risk: `LOW`. The bounded plan is eligible for zero-interaction execution under the `safe-with-backup` policy. The existing service-account crontab is captured before mutation, and secrets are centrally redacted.

## Repair and verification

Doctor preserves all unrelated crontab entries and adds one marked nightly invocation of the application's structured database backup script. Independent verification requires the exact permanent marker to be present afterward.

## Rollback

Rollback restores the complete captured service-account crontab, including the prior absence of a crontab when applicable.
