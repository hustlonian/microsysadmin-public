# ARTIFACT-2376

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Handler/Curl`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2376`
- Rollback: automatic identity journal
