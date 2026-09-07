# ARTIFACT-2273

Added exact missing release-directory restoration for `doctor/modules/mysql`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2273`
- Rollback: automatic identity journal
