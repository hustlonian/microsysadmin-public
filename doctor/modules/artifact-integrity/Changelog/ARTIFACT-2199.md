# ARTIFACT-2199

Added exact missing release-directory restoration for `doctor/modules/composer/Repairs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2199`
- Rollback: automatic identity journal
