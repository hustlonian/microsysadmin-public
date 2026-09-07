# ARTIFACT-2625

Added exact release-directory mode correction for `vendor/monolog/monolog`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2625`
- Rollback: automatic identity/prior-mode journal
