# ARTIFACT-2274

Added exact missing release-directory restoration for `doctor/modules/mysql/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2274`
- Rollback: automatic identity journal
