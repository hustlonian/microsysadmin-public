# COMPOSER-0005: Install exact locked Composer dependencies

Install exact locked Composer dependencies.

## Detection

Runs only the COMPOSER-C0005 probe and records expected state, actual state, path or command evidence, exit status, duration, and timestamp.

## Safety

Automation class: `APPROVAL_REQUIRED`. Risk: `MEDIUM`. The plan is previewed before execution. Secrets are centrally redacted.

## Repair and verification

The implementation performs only the listed structured actions. Verification independently repeats the original probe. Repeated execution is designed to be harmless.

## Rollback

No automatic rollback is claimed; no destructive action is performed.
