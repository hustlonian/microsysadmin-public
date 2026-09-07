# MYSQL-0003: Restore the unavailable MySQL TCP endpoint

Restore the unavailable MySQL TCP endpoint.

## Detection

Runs only the MYSQL-C0003 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `REPORT_ONLY`. Risk: `MEDIUM`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

No automatic mutation is offered. The finding remains visible for an evidence-based operator decision.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
