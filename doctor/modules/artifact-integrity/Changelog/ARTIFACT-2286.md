# ARTIFACT-2286

Added exact missing release-directory restoration for `doctor/modules/nginx/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2286`
- Rollback: automatic identity journal
