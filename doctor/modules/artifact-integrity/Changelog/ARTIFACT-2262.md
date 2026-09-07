# ARTIFACT-2262

Added exact missing release-directory restoration for `doctor/modules/linux/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2262`
- Rollback: automatic identity journal
