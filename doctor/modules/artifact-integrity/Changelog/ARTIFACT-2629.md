# ARTIFACT-2629

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog/Formatter`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2629`
- Rollback: automatic identity/prior-mode journal
