# ARTIFACT-2363

Added exact missing release-directory restoration for `packaging/logrotate`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2363`
- Rollback: automatic identity journal
