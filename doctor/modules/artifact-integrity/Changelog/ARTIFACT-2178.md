# ARTIFACT-2178

Added exact missing release-directory restoration for `doctor/modules/apache/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2178`
- Rollback: automatic identity journal
