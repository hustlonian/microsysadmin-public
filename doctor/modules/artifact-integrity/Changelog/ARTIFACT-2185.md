# ARTIFACT-2185

Added exact missing release-directory restoration for `doctor/modules/backups/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2185`
- Rollback: automatic identity journal
