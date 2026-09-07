# ARTIFACT-2203

Added exact missing release-directory restoration for `doctor/modules/cron/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2203`
- Rollback: automatic identity journal
