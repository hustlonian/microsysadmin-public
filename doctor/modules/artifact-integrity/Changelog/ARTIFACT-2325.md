# ARTIFACT-2325

Added exact missing release-directory restoration for `doctor/modules/redis/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2325`
- Rollback: automatic identity journal
