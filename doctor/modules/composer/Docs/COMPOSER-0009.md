# COMPOSER-0009: Report a Composer self-check failure without guessing its cause

Report a Composer self-check failure without guessing its cause.

## Detection

Runs only the COMPOSER-C0009 probe and records expected state, actual state, command evidence, exit status, duration, and timestamp. Success requires every reviewed Composer diagnostic checkpoint. Composer's root-session advisory on stderr is retained as evidence but does not by itself create a Doctor warning; plugin execution remains disabled.

## Safety

Automation class: `REPORT_ONLY`. Risk: `MEDIUM`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

No automatic mutation is offered. The finding remains visible for an evidence-based operator decision.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
