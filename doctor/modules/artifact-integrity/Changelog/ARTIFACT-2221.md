# ARTIFACT-2221

Added exact missing release-directory restoration for `doctor/modules/docker/Checks`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2221`
- Rollback: automatic identity journal
