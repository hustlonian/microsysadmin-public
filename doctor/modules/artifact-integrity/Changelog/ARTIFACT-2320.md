# ARTIFACT-2320

Added exact missing release-directory restoration for `doctor/modules/redis/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2320`
- Rollback: automatic identity journal
