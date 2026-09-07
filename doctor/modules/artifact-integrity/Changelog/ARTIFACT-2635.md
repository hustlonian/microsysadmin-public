# ARTIFACT-2635

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog/Processor`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2635`
- Rollback: automatic identity/prior-mode journal
