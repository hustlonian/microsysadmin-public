# QUEUE-0005: Restart Laravel queue workers using stale code

Restart Laravel queue workers using stale code.

## Detection

Runs only the QUEUE-C0005 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `AUTO_SAFE`. Risk: `LOW`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

The implementation performs only the listed structured actions. Verification independently repeats the original probe. Repeated execution is designed to be harmless.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
