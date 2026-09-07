# ARTIFACT-2280

Added exact missing release-directory restoration for `doctor/modules/mysql/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2280`
- Rollback: automatic identity journal
