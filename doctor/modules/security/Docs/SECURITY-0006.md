# SECURITY-0006: Restore the missing audit-log directory

## Problem

Restore the missing audit-log directory.

## Detection and root cause

Check `SECURITY-C0006` feeds diagnosis `SECURITY-D0006` at confidence 0.95.

## Exact repair

Uses Repair SDK primitive `create_directory` in category `filesystem`. Arguments are structured and bounded.

## Backup and rollback

primitive-owned exact rollback. Automatic rollback is required.

## Independent verification

Re-run the independent SECURITY-C0006 probe after execution.

## Commercial classification

Tier: `essential`. Risk: `LOW`. Automation: `AUTO_SAFE`.
