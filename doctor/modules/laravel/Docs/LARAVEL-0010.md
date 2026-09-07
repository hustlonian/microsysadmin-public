# LARAVEL-0010: Clear stale Laravel configuration cache

Clear stale Laravel configuration cache.

## Detection

Runs only the LARAVEL-C0010 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `AUTO_SAFE`. Risk: `LOW`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

The implementation performs only the listed structured actions. Verification independently repeats the original probe. Repeated execution is designed to be harmless.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
