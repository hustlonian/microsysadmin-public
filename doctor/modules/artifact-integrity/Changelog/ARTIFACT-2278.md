# ARTIFACT-2278

Added exact missing release-directory restoration for `doctor/modules/mysql/Docs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2278`
- Rollback: automatic identity journal
