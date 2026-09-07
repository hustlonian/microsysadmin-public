# ARTIFACT-2628

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog/Attribute`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2628`
- Rollback: automatic identity/prior-mode journal
