# ARTIFACT-2175

Added exact missing release-directory restoration for `doctor/modules/apache/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2175`
- Rollback: automatic identity journal
