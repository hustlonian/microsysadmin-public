# ARTIFACT-2174

Added exact missing release-directory restoration for `doctor/modules/apache`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2174`
- Rollback: automatic identity journal
