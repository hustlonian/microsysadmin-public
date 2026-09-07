# ARTIFACT-2208

Added exact missing release-directory restoration for `doctor/modules/cron/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2208`
- Rollback: automatic identity journal
