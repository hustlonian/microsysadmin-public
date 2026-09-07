# ARTIFACT-2196

Added exact missing release-directory restoration for `doctor/modules/composer/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2196`
- Rollback: automatic identity journal
