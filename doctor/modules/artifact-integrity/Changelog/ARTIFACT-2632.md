# ARTIFACT-2632

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog/Handler/FingersCrossed`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2632`
- Rollback: automatic identity/prior-mode journal
