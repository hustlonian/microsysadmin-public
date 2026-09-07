# ARTIFACT-2343

Added exact missing release-directory restoration for `doctor/modules/ssl/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2343`
- Rollback: automatic identity journal
