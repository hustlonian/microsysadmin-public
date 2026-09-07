# ARTIFACT-2285

Added exact missing release-directory restoration for `doctor/modules/nginx/Commercial`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2285`
- Rollback: automatic identity journal
