# ARTIFACT-2378

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Handler/Slack`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2378`
- Rollback: automatic identity journal
