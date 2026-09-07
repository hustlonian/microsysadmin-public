# ARTIFACT-2235

Added exact missing release-directory restoration for `doctor/modules/filesystem/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2235`
- Rollback: automatic identity journal
