# ARTIFACT-2228

Added exact missing release-directory restoration for `doctor/modules/filesystem`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2228`
- Rollback: automatic identity journal
