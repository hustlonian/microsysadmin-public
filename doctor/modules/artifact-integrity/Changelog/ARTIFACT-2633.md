# ARTIFACT-2633

Added exact release-directory mode correction for `vendor/monolog/monolog/src/Monolog/Handler/Slack`.

- Primitive: `restore_managed_directory`
- Condition: `mode`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2633`
- Rollback: automatic identity/prior-mode journal
