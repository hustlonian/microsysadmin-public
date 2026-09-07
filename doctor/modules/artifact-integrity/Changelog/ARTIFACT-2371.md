# ARTIFACT-2371

Added exact missing release-directory restoration for `vendor/monolog/monolog/src`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2371`
- Rollback: automatic identity journal
