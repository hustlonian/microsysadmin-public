# ARTIFACT-2283

Added exact missing release-directory restoration for `doctor/modules/nginx/Changelog`.

- Primitive: `restore_managed_directory`
- Condition: `missing`
- Automation: `AUTO_SAFE`
- Risk: `LOW`
- Verification: `ARTIFACT-C2283`
- Rollback: automatic identity journal
