# ARTIFACT-2259

Added exact missing release-directory restoration for `doctor/modules/linux/Diagnoses`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2259`
- Rollback: automatic identity journal
