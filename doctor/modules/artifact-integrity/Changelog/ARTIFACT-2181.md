# ARTIFACT-2181

Added exact missing release-directory restoration for `doctor/modules/apache/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2181`
- Rollback: automatic identity journal
