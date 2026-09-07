# ARTIFACT-2163

Added exact missing release-directory restoration for `doctor/engine/Filesystem`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2163`
- Rollback: automatic identity journal
