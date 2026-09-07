# ARTIFACT-2307

Added exact missing release-directory restoration for `doctor/modules/php/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2307`
- Rollback: automatic identity journal
