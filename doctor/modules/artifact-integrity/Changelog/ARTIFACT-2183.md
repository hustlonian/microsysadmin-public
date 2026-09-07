# ARTIFACT-2183

Added exact missing release-directory restoration for `doctor/modules/backups`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2183`
- Rollback: automatic identity journal
