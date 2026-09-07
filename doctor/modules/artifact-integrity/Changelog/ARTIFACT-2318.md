# ARTIFACT-2318

Added exact missing release-directory restoration for `doctor/modules/redis`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2318`
- Rollback: automatic identity journal
