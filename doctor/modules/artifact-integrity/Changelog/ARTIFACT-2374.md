# ARTIFACT-2374

Added exact missing release-directory restoration for `vendor/monolog/monolog/src/Monolog/Formatter`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2374`
- Rollback: automatic identity journal
