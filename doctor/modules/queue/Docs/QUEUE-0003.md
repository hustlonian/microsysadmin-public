# QUEUE-0003: Enable the disabled Supervisor service

Enable the disabled Supervisor service.

## Detection

Runs only the QUEUE-C0003 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `APPROVAL_REQUIRED`. Risk: `MEDIUM`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

The implementation performs only the listed structured actions. Verification independently repeats the original probe. Repeated execution is designed to be harmless.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
