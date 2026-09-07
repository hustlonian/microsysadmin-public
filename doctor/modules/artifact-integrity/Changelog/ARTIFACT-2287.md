# ARTIFACT-2287

Added exact missing release-directory restoration for `doctor/modules/nginx/Docs`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2287`
- Rollback: automatic identity journal
