# BACKUP-0008: Define an explicit backup retention policy

Define an explicit backup retention policy.

## Detection

Runs only the BACKUP-C0008 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `REPORT_ONLY`. Risk: `MEDIUM`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

No automatic mutation is offered. The finding remains visible for an evidence-based operator decision.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
