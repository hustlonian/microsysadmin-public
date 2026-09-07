# ARTIFACT-2277

Added exact missing release-directory restoration for `doctor/modules/mysql/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2277`
- Rollback: automatic identity journal
