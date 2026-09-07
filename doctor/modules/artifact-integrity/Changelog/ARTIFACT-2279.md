# ARTIFACT-2279

Added exact missing release-directory restoration for `doctor/modules/mysql/Fixtures`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2279`
- Rollback: automatic identity journal
