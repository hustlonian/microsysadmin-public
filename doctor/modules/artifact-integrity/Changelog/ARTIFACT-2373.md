# ARTIFACT-2373

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Attribute`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2373`
- Rollback: automatic identity journal
