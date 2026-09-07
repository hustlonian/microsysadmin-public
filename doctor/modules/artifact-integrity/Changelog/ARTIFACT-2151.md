# ARTIFACT-2151

Added exact missing release-directory restoration for `database/migrations`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2151`
- Rollback: automatic identity journal
