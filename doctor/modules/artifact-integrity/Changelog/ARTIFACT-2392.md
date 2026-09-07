# ARTIFACT-2392

Added exact missing release-directory restoration for `vendor/symfony/process/Messenger`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2392`
- Rollback: automatic identity journal
