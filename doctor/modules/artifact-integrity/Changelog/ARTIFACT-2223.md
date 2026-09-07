# ARTIFACT-2223

Added exact missing release-directory restoration for `doctor/modules/docker/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2223`
- Rollback: automatic identity journal
