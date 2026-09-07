# ARTIFACT-2380

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Processor`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2380`
- Rollback: automatic identity journal
