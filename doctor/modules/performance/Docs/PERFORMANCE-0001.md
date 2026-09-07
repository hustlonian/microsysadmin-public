# PERFORMANCE-0001: Enable the missing PHP OPcache extension

Enable the missing PHP OPcache extension.

## Detection

Runs only the PERFORMANCE-C0001 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `AUTO_WITH_BACKUP`. Risk: `MEDIUM`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

The implementation performs only the listed structured actions. Verification independently repeats the original probe. Repeated execution is designed to be harmless.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
