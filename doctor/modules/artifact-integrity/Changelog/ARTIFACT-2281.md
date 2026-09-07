# ARTIFACT-2281

Added exact missing release-directory restoration for `doctor/modules/mysql/Tests`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2281`
- Rollback: automatic identity journal
