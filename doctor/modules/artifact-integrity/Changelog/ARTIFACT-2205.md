# ARTIFACT-2205

Added exact missing release-directory restoration for `doctor/modules/cron/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2205`
- Rollback: automatic identity journal
