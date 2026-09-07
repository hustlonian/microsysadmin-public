# ARTIFACT-2630

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog/Handler`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2630`
- Rollback: automatic identity/prior-mode journal
