# ARTIFACT-2627

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2627`
- Rollback: automatic identity/prior-mode journal
