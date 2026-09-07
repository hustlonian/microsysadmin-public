# ARTIFACT-2322

Added exact missing release-directory restoration for `doctor/modules/redis/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2322`
- Rollback: automatic identity journal
