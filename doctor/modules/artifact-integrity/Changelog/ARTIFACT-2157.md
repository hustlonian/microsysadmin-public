# ARTIFACT-2157

Added exact missing release-directory restoration for `doctor/engine/Backup`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2157`
- Rollback: automatic identity journal
